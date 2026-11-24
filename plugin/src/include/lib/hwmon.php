<?php
// FanFlow - reading and writing /sys/class/hwmon.
//
// hwmonN numbers change from one boot to the next, so every chip gets a stable id made of its
// driver name and the device it sits on ("nct6799:nct6775.656", "k10temp:0000:00:18.3"); a fan
// is "<chip id>/pwmN".

// Drivers whose fan outputs belong to a graphics card: never driven (the card keeps its fans).
const FF_GPU_DRIVERS = ['amdgpu', 'radeon', 'nouveau', 'i915', 'xe', 'nvidia'];

function ff_hwmon_scan(): array {
  $base = ff_path('/sys/class/hwmon');
  $chips = [];
  $seen = [];
  foreach (glob("$base/hwmon*") ?: [] as $dir) {
    $name = ff_read("$dir/name");
    if ($name === null || $name === '') continue;
    $device = @realpath("$dir/device");
    $where = $device ? basename($device) : basename($dir);
    $id = preg_replace('/[^\w.:-]/', '_', "$name:$where");
    if (isset($seen[$id])) $id .= '-' . (++$seen[$id]); else $seen[$id] = 0;

    $chip = ['id' => $id, 'name' => $name, 'dir' => $dir, 'device' => $device ?: null,
      'gpu' => in_array($name, FF_GPU_DRIVERS, true), 'temps' => [], 'fans' => [], 'pwms' => []];
    foreach (glob("$dir/temp*_input") ?: [] as $file) {
      if (!preg_match('/temp(\d+)_input$/', $file, $m)) continue;
      $chip['temps'][(int)$m[1]] = ['ch' => (int)$m[1], 'label' => ff_read("$dir/temp{$m[1]}_label")];
    }
    foreach (glob("$dir/fan*_input") ?: [] as $file) {
      if (!preg_match('/fan(\d+)_input$/', $file, $m)) continue;
      $chip['fans'][(int)$m[1]] = ['ch' => (int)$m[1], 'label' => ff_read("$dir/fan{$m[1]}_label")];
    }
    foreach (glob("$dir/pwm*") ?: [] as $file) {
      if (!preg_match('/pwm(\d+)$/', $file, $m)) continue;
      $ch = (int)$m[1];
      $writable = is_writable($file) && is_file("$dir/pwm{$ch}_enable") && is_writable("$dir/pwm{$ch}_enable");
      $chip['pwms'][$ch] = ['ch' => $ch, 'controllable' => $writable && !$chip['gpu']];
    }
    ksort($chip['temps']); ksort($chip['fans']); ksort($chip['pwms']);
    $chips[$id] = $chip;
  }
  ksort($chips);
  return $chips;
}

function ff_chip_dir(array $chips, string $chipId): ?string {
  return $chips[$chipId]['dir'] ?? null;
}

// Temperature in °C, or null when the sensor does not answer.
function ff_hwmon_temp(string $dir, int $ch): ?float {
  $raw = ff_read("$dir/temp{$ch}_input");
  if ($raw === null || !is_numeric($raw)) return null;
  $value = (float)$raw / 1000;
  return ($value < -40 || $value > 150) ? null : round($value, 1);
}

function ff_hwmon_rpm(string $dir, int $ch): ?int {
  $raw = ff_read("$dir/fan{$ch}_input");
  return ($raw === null || !is_numeric($raw)) ? null : (int)$raw;
}

function ff_pwm_get(string $dir, int $ch): ?int {
  $raw = ff_read("$dir/pwm$ch");
  return ($raw === null || !is_numeric($raw)) ? null : (int)$raw;
}

function ff_pwm_enable_get(string $dir, int $ch): ?int {
  $raw = ff_read("$dir/pwm{$ch}_enable");
  return ($raw === null || !is_numeric($raw)) ? null : (int)$raw;
}

// Manual mode (pwm_enable = 1), then the duty cycle (0-255).
function ff_pwm_set(string $dir, int $ch, int $value): bool {
  $value = max(0, min(255, $value));
  if (ff_pwm_enable_get($dir, $ch) !== 1 && !ff_write("$dir/pwm{$ch}_enable", '1')) return false;
  return ff_write("$dir/pwm$ch", (string)$value);
}

function ff_pct_to_pwm(float $pct): int { return (int)round(max(0, min(100, $pct)) * 2.55); }
function ff_pwm_to_pct(int $pwm): int  { return (int)round(max(0, min(255, $pwm)) / 2.55); }

// --- Hand-back to the BIOS ----------------------------------------------------------------------
// Before FanFlow first touches an output it records its mode and value; stopping FanFlow puts
// them back, so the BIOS (or the chip's own curve) drives the fans again.

function ff_remember_original(string $fanId, string $dir, int $ch): void {
  $orig = ff_read_json(ff_original_file(), []);
  if (isset($orig[$fanId])) return; // never overwrite with FanFlow's own manual mode
  $enable = ff_pwm_enable_get($dir, $ch);
  $orig[$fanId] = ['dir' => $dir, 'ch' => $ch, 'enable' => $enable ?? 2, 'pwm' => ff_pwm_get($dir, $ch) ?? 255];
  ff_write_json(ff_original_file(), $orig);
}

function ff_restore_original(?array $only = null): int {
  $orig = ff_read_json(ff_original_file(), []);
  $count = 0;
  foreach ($orig as $fanId => $o) {
    if ($only !== null && !in_array($fanId, $only, true)) continue;
    $dir = $o['dir'];
    // The value first, then the mode: in automatic modes the chip ignores the value anyway.
    ff_write("$dir/pwm{$o['ch']}", (string)$o['pwm']);
    ff_write("$dir/pwm{$o['ch']}_enable", (string)$o['enable']);
    unset($orig[$fanId]);
    $count++;
  }
  if ($orig) ff_write_json(ff_original_file(), $orig); else @unlink(ff_original_file());
  return $count;
}
