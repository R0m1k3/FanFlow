<?php
// FanFlow - temperature sources: CPU, graphics cards, disks.
//
// Sources a curve can follow: "cpu", "gpu" (hottest card), "gpu:<bus>" (one card), "hdd" (hottest
// spinning disk) and "ssd" (hottest SSD or NVMe). A source with no reading (cards passed to a VM,
// every disk asleep) is left out: the fans then follow the others.

function ff_find_cpu_sensor(array $chips): ?array {
  $best = null;
  foreach ($chips as $chip) {
    $name = $chip['name'];
    if (!in_array($name, ['k10temp', 'zenpower', 'coretemp'], true)) continue;
    foreach ($chip['temps'] as $t) {
      $label = (string)$t['label'];
      $rank = match (true) {
        $name === 'coretemp' && str_starts_with($label, 'Package') => 3,
        $label === 'Tdie' => 3,
        $label === 'Tctl' => 2,
        $name !== 'coretemp' && $t['ch'] === 1 => 1,
        default => 0,
      };
      if ($rank === 0) continue;
      if ($best === null || $rank > $best['rank']) {
        $best = ['chip' => $chip['id'], 'ch' => $t['ch'], 'label' => $label ?: 'temp' . $t['ch'], 'driver' => $name, 'rank' => $rank];
      }
    }
  }
  if ($best) unset($best['rank']);
  return $best;
}

// All coretemp packages count (dual-socket boards): the hottest one is the CPU temperature.
function ff_cpu_sensors(array $chips): array {
  $one = ff_find_cpu_sensor($chips);
  if (!$one || $one['driver'] !== 'coretemp') return $one ? [$one] : [];
  $list = [];
  foreach ($chips as $chip) {
    if ($chip['name'] !== 'coretemp') continue;
    foreach ($chip['temps'] as $t) {
      if (str_starts_with((string)$t['label'], 'Package')) $list[] = ['chip' => $chip['id'], 'ch' => $t['ch'], 'label' => $t['label'], 'driver' => 'coretemp'];
    }
  }
  return $list;
}

function ff_nvidia_smi(): ?string {
  $override = getenv('FANFLOW_NVIDIA_SMI');
  if ($override !== false) return is_executable($override) ? $override : null;
  foreach (['/usr/bin/nvidia-smi', '/usr/local/bin/nvidia-smi'] as $bin) {
    if (is_executable(ff_path($bin))) return ff_path($bin);
  }
  return null;
}

function ff_short_bus(string $bus): string {
  // "00000000:01:00.0" or "0000:01:00.0" -> "01:00.0"
  return preg_match('/([0-9a-fA-F]{2}:[0-9a-fA-F]{2}\.[0-7])$/', trim($bus), $m) ? strtolower($m[1]) : strtolower(trim($bus));
}

// Graphics cards with their current temperature (null when the card does not answer).
function ff_read_gpus(array $chips): array {
  $gpus = [];
  $smi = ff_nvidia_smi();
  if ($smi) {
    $out = [];
    @exec('timeout 5 ' . escapeshellarg($smi) . ' --query-gpu=index,name,pci.bus_id,temperature.gpu --format=csv,noheader,nounits 2>/dev/null', $out);
    foreach ($out as $line) {
      $f = array_map('trim', explode(',', $line));
      if (count($f) < 4 || !is_numeric($f[0])) continue;
      $bus = ff_short_bus($f[2]);
      $gpus["gpu:$bus"] = ['id' => "gpu:$bus", 'vendor' => 'nvidia', 'index' => (int)$f[0], 'name' => $f[1], 'bus' => $bus,
        'temp' => is_numeric($f[3]) ? (float)$f[3] : null];
    }
  }
  foreach ($chips as $chip) {
    if (!in_array($chip['name'], ['amdgpu', 'radeon', 'i915', 'xe'], true) || !$chip['temps']) continue;
    $bus = ff_short_bus(basename((string)$chip['device']));
    $ch = array_key_first($chip['temps']);
    foreach ($chip['temps'] as $t) if (in_array($t['label'], ['junction', 'pkg'], true)) $ch = $t['ch'];
    $vendor = $chip['name'] === 'amdgpu' || $chip['name'] === 'radeon' ? 'amd' : 'intel';
    $gpus["gpu:$bus"] = ['id' => "gpu:$bus", 'vendor' => $vendor, 'index' => count($gpus), 'name' => strtoupper($vendor) . ' GPU', 'bus' => $bus,
      'temp' => ff_hwmon_temp($chip['dir'], $ch)];
  }
  ksort($gpus);
  return $gpus;
}

// Disks Unraid knows (array, pools, unassigned), from the files emhttpd keeps up to date. Reading
// them never wakes a disk: a disk asleep has "*" as temperature and counts as cold.
function ff_read_disks(): array {
  $disks = [];
  foreach (['disks.ini', 'devs.ini'] as $file) {
    $ini = @parse_ini_file(ff_path("/var/local/emhttp/$file"), true, INI_SCANNER_RAW);
    if (!is_array($ini)) continue;
    foreach ($ini as $key => $d) {
      if (!is_array($d)) continue;
      $device = $d['device'] ?? '';
      if ($device === '' || strcasecmp($d['type'] ?? '', 'Flash') === 0) continue;
      $id = $d['id'] ?? $device;
      if (isset($disks[$id])) continue;
      $ssd = ($d['transport'] ?? '') === 'nvme' || ($d['rotational'] ?? '1') === '0' || str_starts_with($device, 'nvme');
      $temp = $d['temp'] ?? '*';
      $disks[$id] = ['name' => $d['name'] ?? $key, 'device' => $device, 'kind' => $ssd ? 'ssd' : 'hdd',
        'temp' => is_numeric($temp) ? (float)$temp : null, 'asleep' => ($d['spundown'] ?? '0') === '1'];
    }
  }
  return array_values($disks);
}

// One pass: every source's temperature. Returns [values, details].
function ff_read_sources(array $chips, array $hardware): array {
  $values = [];
  $cpu = null;
  foreach ($hardware['sources']['cpu'] ?? [] as $s) {
    $dir = ff_chip_dir($chips, $s['chip']);
    $t = $dir ? ff_hwmon_temp($dir, (int)$s['ch']) : null;
    if ($t !== null) $cpu = max($cpu ?? $t, $t);
  }
  $values['cpu'] = $cpu;

  $gpus = ff_read_gpus($chips);
  $hottest = null;
  foreach ($gpus as $id => $g) {
    $values[$id] = $g['temp'];
    if ($g['temp'] !== null) $hottest = max($hottest ?? $g['temp'], $g['temp']);
  }
  $values['gpu'] = $hottest;

  $disks = ff_read_disks();
  $values['hdd'] = $values['ssd'] = null;
  $hot = ['hdd' => null, 'ssd' => null];
  foreach ($disks as $d) {
    if ($d['temp'] === null) continue;
    if ($values[$d['kind']] === null || $d['temp'] > $values[$d['kind']]) { $values[$d['kind']] = $d['temp']; $hot[$d['kind']] = $d['name']; }
  }
  $counts = ['hdd' => 0, 'ssd' => 0, 'asleep' => 0];
  foreach ($disks as $d) { $counts[$d['kind']]++; if ($d['temp'] === null) $counts['asleep']++; }

  return [$values, ['gpus' => array_values($gpus), 'disks' => $counts, 'hottestDisk' => $hot]];
}
