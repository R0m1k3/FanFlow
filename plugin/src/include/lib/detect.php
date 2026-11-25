<?php
// FanFlow - hardware detection.
//
//   1. drivers: load the sensor chip driver when no fan output shows;
//   2. temperatures: CPU, graphics cards, disks;
//   3. which fan each output drives: one output at a time is raised to 100 % for a few seconds
//      and the fan that speeds up is its own (speeds only go up in this step);
//   4. calibration: each fan is stepped down to 25 % to learn its speed range (stops if the CPU
//      passes 70 °C);
//   5. role and header name from boards.json, default settings.
// The result is saved in /boot/config/plugins/fanflow/hardware.json; at boot only new outputs
// are tested again.

const FF_CALIBRATION_STEPS = [80, 60, 45, 35, 30, 25];
const FF_CALIBRATION_CPU_LIMIT = 70;
// Modules tried, in this order, when no fan output is visible.
const FF_DRIVERS = ['nct6775', 'it87', 'f71882fg', 'w83627ehf', 'nct6683', 'asus_ec_sensors'];

function ff_dmi(): array {
  $read = fn($f) => ff_read(ff_path("/sys/class/dmi/id/$f")) ?? '';
  return ['vendor' => $read('board_vendor'), 'name' => $read('board_name'), 'sysVendor' => $read('sys_vendor')];
}

function ff_controllable_outputs(array $chips): array {
  $list = [];
  foreach ($chips as $chip) foreach ($chip['pwms'] as $p) if ($p['controllable']) $list[] = $chip['id'] . '/pwm' . $p['ch'];
  return $list;
}

// Loads the first sensor driver that brings fan outputs. Returns [tried, loaded].
function ff_load_drivers(array $chips): array {
  if (ff_controllable_outputs($chips)) return [[], []];
  $tried = $loaded = [];
  foreach (FF_DRIVERS as $module) {
    $tried[] = $module;
    if (ff_root() !== '') continue; // simulated machine: nothing to load
    exec('modprobe ' . escapeshellarg($module) . ' 2>/dev/null', $o, $code);
    if ($code !== 0) continue;
    usleep(500000);
    if (ff_controllable_outputs(ff_hwmon_scan())) { $loaded[] = $module; break; }
  }
  return [$tried, $loaded];
}

// Module that drives a sensor chip, from the chip name hwmon gives ("nct6799" -> nct6775).
function ff_driver_for_chip(string $chip): ?string {
  $map = ['/^nct66(8[3-7])/' => 'nct6683', '/^nct67\d\d|^nct6106|^nct6116/' => 'nct6775', '/^it8[67]/' => 'it87',
    '/^(f71|f8000|f81)/' => 'f71882fg', '/^w836/' => 'w83627ehf', '/^asus/' => 'asus_ec_sensors'];
  foreach ($map as $re => $module) if (preg_match($re, $chip)) return $module;
  return null;
}

// After a reboot the sensor driver that detection loaded is gone, and with it every fan of that
// chip. Load it again (the module of each missing chip, then the ones detection loaded, then the
// usual list) until the known fans are back. Returns the new scan.
function ff_reload_drivers(array $hardware, array $chips): array {
  $missing = ff_hardware_changed($hardware, $chips)['missing'];
  if (!$missing || ff_root() !== '') return $chips;
  $modules = [];
  foreach ($missing as $fanId) {
    $module = ff_driver_for_chip(explode(':', $fanId)[0]);
    if ($module) $modules[] = $module;
  }
  $modules = array_values(array_unique(array_merge($modules, $hardware['drivers']['loaded'] ?? [], FF_DRIVERS)));
  foreach ($modules as $module) {
    exec('modprobe ' . escapeshellarg($module) . ' 2>/dev/null', $o, $code);
    if ($code !== 0) continue;
    usleep(500000);
    $chips = ff_hwmon_scan();
    if (!ff_hardware_changed($hardware, $chips)['missing']) {
      ff_log("sensor driver $module loaded, known fans back");
      break;
    }
  }
  return $chips;
}

// The kernel refused the sensor chip because the ACPI firmware claims the same ports; the fix is
// the boot option acpi_enforce_resources=lax, which FanFlow only suggests.
function ff_acpi_conflict(): bool {
  $log = ff_root() !== '' ? (string)@file_get_contents(ff_path('/dmesg.txt')) : (string)shell_exec('dmesg 2>/dev/null');
  return (bool)preg_match('/resource conflicts? with ACPI|ACPI: OSL: Resource conflict/i', $log);
}

// Other programs that drive fans. While one runs FanFlow only shows readings.
function ff_conflicts(): array {
  $found = [];
  foreach (glob(ff_path('/proc') . '/[0-9]*/cmdline') ?: [] as $file) {
    $cmd = str_replace("\0", ' ', (string)@file_get_contents($file));
    if (str_contains($cmd, 'fanflow')) continue;
    if (preg_match('#(^|/)fancontrol(\s|$)#', $cmd)) $found['fancontrol'] = true;
    if (preg_match('#(^|/)autofan(\s|$)|dynamix\.system\.autofan#', $cmd)) $found['Dynamix Auto Fan Control'] = true;
    if (preg_match('#(^|/)(fan2go|fan_control|thinkfan|ipmifan)(\s|$)#', $cmd, $m)) $found[$m[2]] = true;
  }
  return array_keys($found);
}

function ff_board_profile(array $dmi, array $chips): ?array {
  $boards = ff_read_json(__DIR__ . '/../boards.json', []);
  foreach ($boards['boards'] ?? [] as $b) {
    if (!fnmatch($b['vendor'], $dmi['vendor'], FNM_CASEFOLD) || !fnmatch($b['name'], $dmi['name'], FNM_CASEFOLD)) continue;
    foreach ($chips as $chip) if (fnmatch($b['chip'], $chip['name'])) return $b + ['chipId' => $chip['id']];
  }
  return null;
}

function ff_all_rpms(array $chips): array {
  $rpm = [];
  foreach ($chips as $chip) foreach ($chip['fans'] as $f) $rpm[$chip['id'] . '/fan' . $f['ch']] = ff_hwmon_rpm($chip['dir'], $f['ch']) ?? 0;
  return $rpm;
}

// Which tachometer an output drives: raise it to 100 % and see which fan speeds up.
function ff_pair_output(array $chips, string $outputId, callable $progress): array {
  [$chipId, $pwm] = explode('/', $outputId);
  $ch = (int)substr($pwm, 3);
  $dir = $chips[$chipId]['dir'];
  $first = ff_all_rpms($chips);
  ff_sleep(1);
  $second = ff_all_rpms($chips);
  $before = [];
  foreach ($first as $tach => $rpm) $before[$tach] = ($rpm + ($second[$tach] ?? $rpm)) / 2;
  ff_remember_original($outputId, $dir, $ch);
  ff_pwm_set($dir, $ch, 255);
  $best = null; $gain = 0;
  for ($i = 0; $i < 6; $i++) {
    ff_sleep(1);
    $progress();
    foreach (ff_all_rpms($chips) as $tach => $rpm) {
      $delta = $rpm - ($before[$tach] ?? 0);
      // A real answer: at least +150 rpm and +15 %.
      if ($delta >= 150 && $delta >= 0.15 * max(1, $before[$tach] ?? 0) && $delta > $gain) { $best = $tach; $gain = $delta; }
    }
    if ($best && $i >= 2) break;
  }
  ff_restore_original([$outputId]);
  if ($best) return ['tach' => $best, 'guessed' => false];
  // Nothing moved: the fan may already run flat out. Same number, still spinning: probably that one.
  $same = "$chipId/fan$ch";
  if (($before[$same] ?? 0) > 0) return ['tach' => $same, 'guessed' => true];
  return ['tach' => null, 'guessed' => false];
}

function ff_cpu_now(array $chips, array $cpuSensors): ?float {
  $t = null;
  foreach ($cpuSensors as $s) {
    $v = isset($chips[$s['chip']]) ? ff_hwmon_temp($chips[$s['chip']]['dir'], (int)$s['ch']) : null;
    if ($v !== null) $t = max($t ?? $v, $v);
  }
  return $t;
}

// Speed range of one fan: rpm at 100 % and at each step down to 25 %.
function ff_calibrate_fan(array $chips, string $outputId, string $tach, array $cpuSensors, callable $progress): array {
  [$chipId, $pwm] = explode('/', $outputId);
  $ch = (int)substr($pwm, 3);
  $dir = $chips[$chipId]['dir'];
  [$tChip, $tFan] = explode('/', $tach);
  $tDir = $chips[$tChip]['dir'];
  $tCh = (int)substr($tFan, 3);
  ff_remember_original($outputId, $dir, $ch);
  ff_pwm_set($dir, $ch, 255);
  ff_sleep(4);
  $steps = [100 => ff_hwmon_rpm($tDir, $tCh) ?? 0];
  $result = ['maxRpm' => $steps[100], 'minPct' => 25, 'stopsAt' => null, 'aborted' => false];
  $lowestSpinning = 100;
  foreach (FF_CALIBRATION_STEPS as $pct) {
    $cpu = ff_cpu_now($chips, $cpuSensors);
    if ($cpu !== null && $cpu > FF_CALIBRATION_CPU_LIMIT) { $result['aborted'] = true; break; }
    ff_pwm_set($dir, $ch, ff_pct_to_pwm($pct));
    ff_sleep(3);
    $progress();
    $rpm = ff_hwmon_rpm($tDir, $tCh) ?? 0;
    $steps[$pct] = $rpm;
    if ($rpm <= 0) { $result['stopsAt'] = $pct; break; }
    $lowestSpinning = $pct;
  }
  ff_restore_original([$outputId]);
  $result['steps'] = $steps;
  // Stopped during the test: keep a margin above the last speed where it still turned.
  if ($result['stopsAt'] !== null) $result['minPct'] = min(100, max(25, $lowestSpinning + 5));
  return $result;
}

function ff_guess_role(?string $label, ?string $tachLabel): string {
  $text = strtoupper(($label ?? '') . ' ' . ($tachLabel ?? ''));
  return preg_match('/CPU|PUMP|AIO/', $text) ? 'cpu' : 'case';
}

// Full or partial detection. $previous: last hardware.json (outputs already known are kept,
// only new ones are tested). $progress(step, done, total) is called as it goes.
function ff_detect(?array $previous, bool $full, ?callable $progress = null): array {
  $progress ??= function () {};
  $chips = ff_hwmon_scan();
  [$tried, $loaded] = ff_load_drivers($chips);
  if ($loaded) $chips = ff_hwmon_scan();
  $dmi = ff_dmi();
  $board = ff_board_profile($dmi, $chips);

  $cpu = ff_cpu_sensors($chips);
  $gpus = array_map(fn($g) => array_diff_key($g, ['temp' => 1]), array_values(ff_read_gpus($chips)));
  $disks = ['hdd' => 0, 'ssd' => 0];
  foreach (ff_read_disks() as $d) $disks[$d['kind']]++;

  $hw = [
    'version' => 1,
    'detectedAt' => date('c', (int)ff_now()),
    'board' => $dmi,
    'boardProfile' => $board['title'] ?? null,
    'drivers' => ['tried' => $tried, 'loaded' => $loaded],
    'acpiConflict' => false,
    'chips' => array_map(fn($c) => ['name' => $c['name'], 'gpu' => $c['gpu'], 'outputs' => count($c['pwms']), 'fans' => count($c['fans'])], $chips),
    'sources' => ['cpu' => $cpu, 'gpus' => $gpus, 'disks' => $disks],
    'fans' => [],
    'emptyOutputs' => [],
    'otherFans' => [],
  ];

  $outputs = ff_controllable_outputs($chips);
  if (!$outputs) $hw['acpiConflict'] = ff_acpi_conflict();
  $known = $full ? [] : (($previous['fans'] ?? []) + array_fill_keys($previous['emptyOutputs'] ?? [], null));
  $todo = array_values(array_filter($outputs, fn($o) => !array_key_exists($o, $known)));
  $total = count($todo) * 2;
  $done = 0;
  $tick = function ($step) use (&$done, $total, $progress) { $progress($step, $done, $total); };

  // 1. pairing
  $pairs = [];
  foreach ($todo as $out) {
    $tick('pairing');
    $pairs[$out] = ff_pair_output($chips, $out, fn() => $tick('pairing'));
    $done++;
  }
  // 2. calibration
  foreach ($outputs as $out) {
    if (array_key_exists($out, $known)) {
      if ($known[$out] === null) $hw['emptyOutputs'][] = $out; else $hw['fans'][$out] = $known[$out];
      continue;
    }
    $pair = $pairs[$out];
    if (!$pair['tach']) { $hw['emptyOutputs'][] = $out; $done++; continue; }
    $tick('calibrating');
    $cal = ff_calibrate_fan($chips, $out, $pair['tach'], $cpu, fn() => $tick('calibrating'));
    $done++;
    // A guessed pair whose speed did not follow the steps down: that fan is not on this output.
    if ($pair['guessed'] && !$cal['aborted']) {
      $rpms = array_values($cal['steps']);
      if (max($rpms) - min($rpms) < 0.1 * max(1, max($rpms))) { $hw['emptyOutputs'][] = $out; continue; }
    }
    [$chipId, $pwm] = explode('/', $out);
    [$tChip, $tFan] = explode('/', $pair['tach']);
    $tachLabel = $chips[$tChip]['fans'][(int)substr($tFan, 3)]['label'] ?? null;
    $label = ($board && $board['chipId'] === $chipId) ? ($board['outputs'][$pwm] ?? null) : null;
    $hw['fans'][$out] = [
      'id' => $out, 'chip' => $chipId, 'output' => $pwm, 'tach' => $pair['tach'], 'tachGuessed' => $pair['guessed'],
      'controllable' => true, 'label' => $label ?? $tachLabel, 'labelFromBoard' => $label !== null,
      'labelProbable' => $label !== null && !empty($board['probable']),
      'role' => ff_guess_role($label, $tachLabel), 'calibration' => $cal,
    ];
  }
  // 3. fans that turn but no output drives (fan hub with its own switch, fixed headers)
  $used = array_filter(array_column($hw['fans'], 'tach'));
  foreach (ff_all_rpms($chips) as $tach => $rpm) {
    if ($rpm > 0 && !in_array($tach, $used, true)) $hw['otherFans'][] = ['tach' => $tach, 'rpm' => $rpm];
  }
  ksort($hw['fans']);
  sort($hw['emptyOutputs']);
  $progress('done', $total, $total);
  return $hw;
}

// Fans turning on a tachometer that no driven fan uses (3-pin fans, hubs) are kept in otherFans,
// so they stay listed while they are stopped. Returns the tachometers added.
function ff_adopt_other_fans(array &$hardware, array $chips): array {
  $used = array_merge(array_filter(array_column($hardware['fans'] ?? [], 'tach')), array_column($hardware['otherFans'] ?? [], 'tach'));
  $added = [];
  foreach (ff_all_rpms(array_filter($chips, fn($c) => !$c['gpu'])) as $tach => $rpm) {
    if ($rpm <= 0 || in_array($tach, $used, true)) continue;
    $hardware['otherFans'][] = ['tach' => $tach, 'rpm' => $rpm];
    $added[] = $tach;
  }
  return $added;
}

// Boot check without spinning anything: are the same outputs still there?
function ff_hardware_changed(array $hardware, array $chips): array {
  $now = ff_controllable_outputs($chips);
  $known = array_merge(array_keys($hardware['fans'] ?? []), $hardware['emptyOutputs'] ?? []);
  $new = array_values(array_diff($now, $known));
  // A fan plugged on an output found empty before: a tachometer that no known fan uses now
  // turns. Which output it hangs on is unknown without a test, so the empty outputs are tested again.
  $empty = array_values(array_intersect($hardware['emptyOutputs'] ?? [], $now));
  if ($empty) {
    $used = array_merge(array_filter(array_column($hardware['fans'] ?? [], 'tach')), array_column($hardware['otherFans'] ?? [], 'tach'));
    foreach (ff_all_rpms(array_filter($chips, fn($c) => !$c['gpu'])) as $tach => $rpm) {
      if ($rpm > 0 && !in_array($tach, $used, true)) { $new = array_merge($new, $empty); break; }
    }
  }
  return ['new' => $new, 'missing' => array_values(array_diff(array_keys($hardware['fans'] ?? []), $now))];
}
