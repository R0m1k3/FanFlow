<?php
// Simulated fans: each fake tachometer follows its output, as a real fan would a few seconds
// after a change. Loaded by FanFlow through FANFLOW_SIM_HOOK; ff_sleep() calls ff_sim_tick().

function sim_step(string $root): void {
  $sim = json_decode((string)@file_get_contents("$root/sim.json"), true);
  if (!$sim) return;
  $base = "$root/sys/class/hwmon";
  foreach ($sim['fans'] as $f) {
    $dir = "$base/{$f['dir']}";
    // 'clamp': a chip that reads back less than written. 'fight': another program keeps changing
    // the output (a new value at each tick). 'takeover': it puts the output back in automatic mode.
    if (isset($f['fight'])) { file_put_contents("$dir/pwm{$f['pwm']}_enable", "1\n"); $n = (int)@file_get_contents("$root/fight") + 1; file_put_contents("$root/fight", $n); file_put_contents("$dir/pwm{$f['pwm']}", (100 + $n * 53 % 150) . "\n"); }
    if (isset($f['takeover'])) file_put_contents("$dir/pwm{$f['pwm']}_enable", "5\n");
    if (isset($f['clamp'])) {
      $now = (int)trim((string)@file_get_contents("$dir/pwm{$f['pwm']}"));
      if ($now > $f['clamp']) file_put_contents("$dir/pwm{$f['pwm']}", "{$f['clamp']}\n");
    }
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
