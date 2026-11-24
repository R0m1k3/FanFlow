<?php
// Builds the status.php answers the visual test serves, from a run on the simulated ASUS machine:
// detection, then ten minutes of passes (simulated clock) with the CPU swinging and the graphics
// card warming up.
//   php test/fixture.php [out dir]
$repo = dirname(__DIR__);
require_once "$repo/test/sim/make.php";
$out = $argv[1] ?? "$repo/test/fixture";
@mkdir($out, 0755, true);
$root = sys_get_temp_dir() . '/fanflow-fixture';
putenv("FANFLOW_ROOT=$root");
sim_make('asus-b650', $root);
$daemon = escapeshellarg("$repo/plugin/src/scripts/fanflowd");
$env = 'FANFLOW_ROOT=' . escapeshellarg($root) . ' FANFLOW_FAST=1';
shell_exec("$env FANFLOW_SIM_HOOK=" . escapeshellarg("$repo/test/sim/physics.php") . " php $daemon --detect");

// A simulator hook that also moves the temperatures and the clock at every pass.
$start = time() - 600;
$hook = "$root/physics-driven.php";
file_put_contents($hook, '<?php
require_once ' . var_export("$repo/test/sim/physics.php", true) . ';
function ff_sim_drive(): void {
  static $n = 0;
  $root = ff_root();
  $sim = json_decode(file_get_contents("$root/sim.json"), true);
  $i = intdiv($n++, 4); // four one-second sleeps per pass
  $cpu = 46 + 8 * sin($i / 14) + ($i > 110 ? 6 : 0);
  $gpu = $i < 60 ? 41 + $i * 0.05 : min(71, 44 + ($i - 60) * 0.6);
  file_put_contents("$root/sys/class/hwmon/{$sim["dirs"]["k10temp"]}/temp1_input", (int)($cpu * 1000) . "\n");
  file_put_contents("$root/gpus.csv", sprintf("0, NVIDIA GeForce RTX 5060 Ti, 00000000:01:00.0, %d\n1, NVIDIA GeForce RTX 3060, 00000000:05:00.0, %d\n", $gpu, 38 + $i * 0.04));
  putenv("FANFLOW_CLOCK=" . (' . $start . ' + $i * 4));
}
');
// physics.php defines ff_sim_tick; the driven copy calls ff_sim_drive first.
$physics = str_replace("function ff_sim_tick(float \$seconds): void {\n", "function ff_sim_tick(float \$seconds): void {\n  ff_sim_drive();\n",
  file_get_contents("$repo/test/sim/physics.php"));
file_put_contents($hook, $physics . "\n" . preg_replace('/^<\?php\s*require_once[^\n]*\n/', '', file_get_contents($hook)));
shell_exec("$env FANFLOW_CLOCK=$start FANFLOW_SIM_HOOK=" . escapeshellarg($hook) . " php $daemon --passes 150");

// status.php as the webGUI would answer it (a pid file makes the service look alive).
file_put_contents("$root/var/local/fanflow/fanflowd.pid", '1');
$status = "$repo/plugin/src/include/status.php";
foreach (['status' => '', 'status-full' => '$_GET["full"] = 1; '] as $name => $pre) {
  $s = json_decode((string)shell_exec('FANFLOW_ROOT=' . escapeshellarg($root) . ' php -r ' . escapeshellarg($pre . "include '$status';")), true);
  $s['updated'] = time();
  file_put_contents("$out/$name.json", json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
echo "mode {$s['mode']}, " . count($s['fans']) . " fans, " . count($s['history']['t']) . " history points -> $out\n";
exec('rm -rf ' . escapeshellarg($root));
