<?php
// Simulated fans: each fake tachometer follows its output, as a real fan would a few seconds
// after a change. Loaded by FanFlow through FANFLOW_SIM_HOOK; ff_sleep() calls ff_sim_tick().

function sim_step(string $root): void {
  $sim = json_decode((string)@file_get_contents("$root/sim.json"), true);
  if (!$sim) return;
  $base = "$root/sys/class/hwmon";
  foreach ($sim['fans'] as $f) {
    $dir = "$base/{$f['dir']}";
    $enable = (int)trim((string)@file_get_contents("$dir/pwm{$f['pwm']}_enable"));
    $pwm = (int)trim((string)@file_get_contents("$dir/pwm{$f['pwm']}"));
    $pct = match ($enable) { 1 => $pwm / 2.55, 0 => 100, default => $f['bios'] };
    $rpm = (!empty($f['stuck']) || $pct < $f['stop']) ? 0 : (int)round($f['min'] + ($f['max'] - $f['min']) * $pct / 100);
    file_put_contents("$dir/fan{$f['tach']}_input", "$rpm\n");
  }
  foreach ($sim['fixed'] as $f) file_put_contents("$base/{$f['dir']}/fan{$f['tach']}_input", "{$f['rpm']}\n");
}

function ff_sim_tick(float $seconds): void {
  sim_step(ff_root());
}
