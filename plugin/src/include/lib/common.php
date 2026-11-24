<?php
// FanFlow - shared helpers: paths, JSON files, settings.
//
// Every path goes through ff_path(), so the whole plugin runs against a simulated machine when
// FANFLOW_ROOT points to a folder holding a fake /sys, /var/local/emhttp and /boot (see test/sim).

const FF_VERSION = '@@VERSION@@';

function ff_root(): string {
  $root = getenv('FANFLOW_ROOT');
  return $root === false ? '' : rtrim($root, '/');
}

function ff_path(string $path): string {
  return ff_root() . $path;
}

function ff_config_dir(): string { return ff_path('/boot/config/plugins/fanflow'); }
function ff_run_dir(): string    { return ff_path('/var/local/fanflow'); }
function ff_config_file(): string   { return ff_config_dir() . '/fanflow.json'; }
function ff_hardware_file(): string { return ff_config_dir() . '/hardware.json'; }
function ff_state_file(): string    { return ff_run_dir() . '/state.json'; }
function ff_history_file(): string  { return ff_run_dir() . '/history.json'; }
function ff_original_file(): string { return ff_run_dir() . '/original.json'; }
function ff_request_file(): string  { return ff_run_dir() . '/request.json'; }

function ff_read_json(string $file, $default = null) {
  if (!is_file($file)) return $default;
  $data = json_decode((string)@file_get_contents($file), true);
  return is_array($data) ? $data : $default;
}

// Atomic write: readers (the web pages) never see a half-written file.
function ff_write_json(string $file, $data): bool {
  $dir = dirname($file);
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $tmp = $file . '.tmp' . getmypid();
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  if ($json === false || @file_put_contents($tmp, $json . "\n") === false) return false;
  return @rename($tmp, $file);
}

function ff_read(string $file): ?string {
  $value = @file_get_contents($file);
  return $value === false ? null : trim($value);
}

function ff_write(string $file, string $value): bool {
  return @file_put_contents($file, $value) !== false;
}

function ff_now(): float {
  $fake = getenv('FANFLOW_CLOCK');
  return $fake !== false ? (float)$fake : microtime(true);
}

function ff_sleep(float $seconds): void {
  if (function_exists('ff_sim_tick')) ff_sim_tick($seconds); // simulated machine: fans react
  if (getenv('FANFLOW_FAST') !== false) return;            // tests: no waiting
  usleep((int)($seconds * 1e6));
}

// Simulator only: test/sim/physics.php makes the fake fans follow their outputs.
if (ff_root() !== '' && getenv('FANFLOW_SIM_HOOK') && is_file(getenv('FANFLOW_SIM_HOOK'))) require_once getenv('FANFLOW_SIM_HOOK');

function ff_log(string $message): void {
  if (ff_root() !== '' || getenv('FANFLOW_STDERR') !== false) {
    fwrite(STDERR, "fanflow: $message\n");
    return;
  }
  @exec('logger -t fanflow -- ' . escapeshellarg($message));
}

// --- Settings ---------------------------------------------------------------------------------

// Curves are lists of [temperature °C, fan %] points, sorted by temperature.
function ff_presets(): array {
  return [
    'silent' => [
      'cpu' => [[45, 20], [60, 30], [72, 55], [82, 85], [88, 100]],
      'gpu' => [[50, 20], [65, 30], [75, 60], [83, 100]],
      'hdd' => [[36, 20], [42, 35], [47, 65], [52, 100]],
      'ssd' => [[50, 20], [60, 35], [68, 70], [74, 100]],
    ],
    'balanced' => [
      'cpu' => [[40, 25], [55, 35], [68, 60], [78, 85], [85, 100]],
      'gpu' => [[45, 25], [60, 40], [72, 70], [80, 100]],
      'hdd' => [[34, 25], [40, 40], [45, 70], [50, 100]],
      'ssd' => [[45, 25], [55, 40], [65, 75], [72, 100]],
    ],
    'performance' => [
      'cpu' => [[35, 35], [50, 50], [62, 75], [72, 100]],
      'gpu' => [[40, 35], [55, 55], [67, 85], [75, 100]],
      'hdd' => [[32, 35], [38, 55], [43, 85], [47, 100]],
      'ssd' => [[40, 35], [50, 55], [60, 85], [68, 100]],
    ],
  ];
}

function ff_default_config(): array {
  return [
    'version' => 1,
    'enabled' => true,
    'preset' => 'balanced',
    'interval' => 4,          // seconds between two passes
    'hysteresis' => 3,        // °C the temperature must fall before a fan slows down
    'rampUp' => 20,           // largest rise per pass, %
    'rampDown' => 5,          // largest drop per pass, %
    'critical' => ['cpu' => 90, 'gpu' => 87, 'hdd' => 55, 'ssd' => 75],
    'notify' => true,
    'fans' => [],             // fan id => settings, filled from the detection
  ];
}

// Settings of one fan, created from what the detection found.
function ff_default_fan(array $hw, string $preset = 'balanced'): array {
  $curves = ff_presets()[$preset] ?? ff_presets()['balanced'];
  $role = $hw['role'] ?? 'case';
  $min = $role === 'cpu' ? 30 : 25;
  if (preg_match('/PUMP|AIO/i', (string)($hw['label'] ?? ''))) $min = 60; // a pump must keep turning
  if (!empty($hw['calibration']['minPct'])) $min = max($min, (int)$hw['calibration']['minPct']);
  return [
    'name' => $hw['label'] ?? $hw['id'],
    'role' => $role,
    'mode' => 'auto',         // auto | fixed | bios
    'fixed' => 50,
    'min' => $min,
    'allowStop' => false,
    // The CPU fan follows the CPU; case fans follow whichever of CPU, GPU and disks needs most.
    'curves' => $role === 'cpu'
      ? ['cpu' => $curves['cpu']]
      : ['cpu' => $curves['cpu'], 'gpu' => $curves['gpu'], 'hdd' => $curves['hdd'], 'ssd' => $curves['ssd']],
  ];
}

// The settings file merged over the defaults, with a fan entry for every controllable fan found.
function ff_load_config(?array $hardware = null): array {
  $config = array_replace(ff_default_config(), ff_read_json(ff_config_file(), []));
  $config['critical'] = array_replace(ff_default_config()['critical'], (array)($config['critical'] ?? []));
  $config['fans'] = (array)($config['fans'] ?? []);
  foreach (($hardware['fans'] ?? []) as $id => $fan) {
    if (empty($fan['controllable'])) continue;
    if (!isset($config['fans'][$id])) $config['fans'][$id] = ff_default_fan($fan + ['id' => $id], $config['preset']);
  }
  return $config;
}

// Checks and cleans settings sent by the settings page. Returns [config, error|null].
function ff_validate_config(array $in): array {
  $base = ff_default_config();
  $out = $base;
  $out['enabled'] = !empty($in['enabled']);
  $out['preset'] = in_array($in['preset'] ?? '', ['silent', 'balanced', 'performance', 'custom'], true) ? $in['preset'] : 'balanced';
  $out['interval'] = max(2, min(30, (int)($in['interval'] ?? $base['interval'])));
  $out['hysteresis'] = max(0, min(10, (int)($in['hysteresis'] ?? $base['hysteresis'])));
  $out['rampUp'] = max(1, min(100, (int)($in['rampUp'] ?? $base['rampUp'])));
  $out['rampDown'] = max(1, min(100, (int)($in['rampDown'] ?? $base['rampDown'])));
  $out['notify'] = !empty($in['notify']);
  foreach ($base['critical'] as $key => $value) {
    $out['critical'][$key] = max(30, min(110, (int)($in['critical'][$key] ?? $value)));
  }
  $out['fans'] = [];
  foreach ((array)($in['fans'] ?? []) as $id => $fan) {
    if (!is_string($id) || !preg_match('#^[\w.:-]+/pwm\d+$#', $id) || !is_array($fan)) return [null, "bad fan id"];
    $clean = [
      'name' => ff_short_text((string)($fan['name'] ?? $id), 40) ?: $id,
      'role' => ($fan['role'] ?? '') === 'cpu' ? 'cpu' : 'case',
      'mode' => in_array($fan['mode'] ?? '', ['auto', 'fixed', 'bios'], true) ? $fan['mode'] : 'auto',
      'fixed' => max(0, min(100, (int)($fan['fixed'] ?? 50))),
      'min' => max(0, min(100, (int)($fan['min'] ?? 25))),
      'allowStop' => !empty($fan['allowStop']),
      'curves' => [],
    ];
    // A fan on a fixed speed may not be stopped unless stopping is allowed.
    if (!$clean['allowStop']) $clean['fixed'] = max($clean['fixed'], $clean['min']);
    foreach ((array)($fan['curves'] ?? []) as $source => $points) {
      if (!is_string($source) || !preg_match('/^(cpu|gpu|hdd|ssd|gpu:[\w.:-]+)$/', $source)) return [null, "bad source $source"];
      $pts = [];
      foreach ((array)$points as $p) {
        if (!is_array($p) || count($p) < 2) continue;
        $pts[] = [max(0, min(120, (int)$p[0])), max(0, min(100, (int)$p[1]))];
      }
      usort($pts, fn($a, $b) => $a[0] <=> $b[0]);
      if (count($pts) >= 2) $clean['curves'][$source] = array_slice($pts, 0, 12);
    }
    $out['fans'][$id] = $clean;
  }
  return [$out, null];
}

// Plain text of at most $max characters (UTF-8 safe without mbstring).
function ff_short_text(string $text, int $max): string {
  $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');
  return preg_match('/^.{0,' . $max . '}/us', $text, $m) ? trim($m[0]) : '';
}
