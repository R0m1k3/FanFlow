<?php
// FanFlow tests: the speed calculation, the settings check, and the service run on simulated
// machines (test/sim).   php test/unit/run.php
error_reporting(E_ALL);
$repo = dirname(__DIR__, 2);
$src = "$repo/plugin/src";
$lib = "$src/include/lib";
foreach (['common', 'hwmon', 'sources', 'curve', 'detect'] as $f) require_once "$lib/$f.php";
require_once "$repo/test/sim/make.php";

$failed = 0; $passed = 0;
function check(string $what, bool $ok, $detail = ''): void {
  global $failed, $passed;
  if ($ok) { $passed++; return; }
  $failed++;
  echo "FAIL  $what" . ($detail !== '' ? '  -> ' . (is_string($detail) ? $detail : json_encode($detail)) : '') . "\n";
}
function section(string $name): void { echo "- $name\n"; }

// --- simulator helpers ------------------------------------------------------------------------
$simRoot = sys_get_temp_dir() . '/fanflow-test';
function sim(string $profile, bool $shuffle = false): array {
  global $simRoot;
  putenv("FANFLOW_ROOT=$simRoot");
  return sim_make($profile, $simRoot, $shuffle);
}
function daemon(string $args): string {
  global $simRoot, $src, $repo;
  $env = 'FANFLOW_ROOT=' . escapeshellarg($simRoot) . ' FANFLOW_FAST=1 FANFLOW_SIM_HOOK=' . escapeshellarg("$repo/test/sim/physics.php");
  return (string)shell_exec("$env php " . escapeshellarg("$src/scripts/fanflowd") . " $args 2>&1");
}
function hw(): array { global $simRoot; return json_decode(file_get_contents("$simRoot/boot/config/plugins/fanflow/hardware.json"), true); }
function state(): array { global $simRoot; return json_decode(file_get_contents("$simRoot/var/local/fanflow/state.json"), true); }
function fan(string $label): array { foreach (state()['fans'] as $f) if ($f['name'] === $label) return $f; return []; }
function hwfile(string $chip, string $file): string {
  global $simRoot;
  $sim = json_decode(file_get_contents("$simRoot/sim.json"), true);
  return "$simRoot/sys/class/hwmon/{$sim['dirs'][$chip]}/$file";
}
function setval(string $chip, string $file, $value): void { file_put_contents(hwfile($chip, $file), "$value\n"); }
function val(string $chip, string $file): string { return trim(file_get_contents(hwfile($chip, $file))); }
function gpu_temp(int $t0, int $t1): void {
  global $simRoot;
  file_put_contents("$simRoot/gpus.csv", "0, NVIDIA GeForce RTX 5060 Ti, 00000000:01:00.0, $t0\n1, NVIDIA GeForce RTX 3060, 00000000:05:00.0, $t1\n");
}

// --- curves -------------------------------------------------------------------------------------
section('curves');
$c = [[40, 20], [60, 50], [80, 100]];
check('below first point', ff_curve_value($c, 30) == 20);
check('between points', ff_curve_value($c, 50) == 35);
check('above last point', ff_curve_value($c, 95) == 100);
$cfg = ff_default_config();
$fanCfg = ['min' => 25, 'curves' => ['cpu' => $c, 'gpu' => [[40, 20], [70, 80]]]];
[$p, $by] = ff_next_speed($fanCfg, ['cpu' => 50, 'gpu' => 65], 30, $cfg);
check('highest curve wins, ramp up limited', $by === 'gpu' && $p == 50, [$p, $by]);
[$p] = ff_next_speed($fanCfg, ['cpu' => 50, 'gpu' => null], 35, $cfg);
check('missing source ignored', $p == 35, $p);
[$p] = ff_next_speed($fanCfg, ['cpu' => 49, 'gpu' => null], 35, $cfg);
check('hysteresis: 1 °C lower does not slow the fan', $p == 35, $p);
[$p] = ff_next_speed($fanCfg, ['cpu' => 40, 'gpu' => null], 35, $cfg);
check('temperature well down: slows by rampDown', $p == 30, $p);
[$p] = ff_next_speed($fanCfg, ['cpu' => 20, 'gpu' => null], 26, $cfg);
check('never under the minimum', $p == 25, $p);
[$p] = ff_next_speed($fanCfg + ['allowStop' => true], ['cpu' => 20, 'gpu' => null], 25, ['rampDown' => 100] + $cfg);
check('stop allowed: under the minimum means stopped', $p == 0, $p);
[$p, $by] = ff_next_speed($fanCfg, ['cpu' => null, 'gpu' => null], 40, $cfg);
check('no reading at all: hold', $p == 40 && $by === null, [$p, $by]);
check('critical detection', ff_critical(['cpu' => 50, 'gpu:01:00.0' => 88, 'hdd' => null], $cfg['critical'])[0] === 'gpu:01:00.0');

section('sensor driver of a chip');
check('nct6799 -> nct6775', ff_driver_for_chip('nct6799') === 'nct6775');
check('nct6687 -> nct6683', ff_driver_for_chip('nct6687') === 'nct6683');
check('it8689 -> it87', ff_driver_for_chip('it8689') === 'it87');
check('k10temp -> none', ff_driver_for_chip('k10temp') === null);
$hwGone = ['fans' => ['nct6799:nct6775.656/pwm1' => []], 'emptyOutputs' => []];
check('missing fan seen', ff_hardware_changed($hwGone, [])['missing'] === ['nct6799:nct6775.656/pwm1']);

section('settings check');
[$v, $err] = ff_validate_config(['enabled' => 1, 'interval' => 1, 'fans' => ['nct:x/pwm1' => ['name' => '<b>Front</b>', 'mode' => 'fixed', 'fixed' => 5, 'min' => 25,
  'curves' => ['cpu' => [[70, 90], [30, 20]], 'gpu:01:00.0' => [[40, 30], [70, 100]]]]]]);
check('valid settings accepted', $err === null, $err);
check('interval clamped', $v['interval'] === 2);
check('name stripped of tags', $v['fans']['nct:x/pwm1']['name'] === 'Front');
check('fixed speed not under the minimum', $v['fans']['nct:x/pwm1']['fixed'] === 25);
check('curve points sorted', $v['fans']['nct:x/pwm1']['curves']['cpu'][0] === [30, 20]);
[, $err] = ff_validate_config(['fans' => ['../../etc' => []]]);
check('bad fan id refused', $err !== null);
[, $err] = ff_validate_config(['fans' => ['a/pwm1' => ['curves' => ['rm -rf' => [[1, 1], [2, 2]]]]]]);
check('bad source refused', $err !== null);

// --- Reference machine ------------------------------------------------------------------------
section('detection: ASUS B650 + Ryzen + 2 Nvidia');
sim('asus-b650');
daemon('--detect');
$h = hw();
$ids = array_keys($h['fans']);
$pfx = 'nct6799:nct6775.656/';
check('three fans found', $ids === ["{$pfx}pwm1", "{$pfx}pwm2", "{$pfx}pwm3"], $ids);
check('outputs paired with their tachometers', $h['fans']["{$pfx}pwm2"]['tach'] === "{$pfx}fan2");
check('header names from boards.json', $h['fans']["{$pfx}pwm2"]['label'] === 'CPU_FAN' && $h['fans']["{$pfx}pwm1"]['label'] === 'CHA_FAN1');
check('CPU fan role', $h['fans']["{$pfx}pwm2"]['role'] === 'cpu' && $h['fans']["{$pfx}pwm1"]['role'] === 'case');
check('calibration: fan that stops at 25 % keeps a margin', $h['fans']["{$pfx}pwm3"]['calibration']['minPct'] === 35, $h['fans']["{$pfx}pwm3"]['calibration']);
check('empty outputs listed', $h['emptyOutputs'] === ["{$pfx}pwm4", "{$pfx}pwm5", "{$pfx}pwm6", "{$pfx}pwm7"], $h['emptyOutputs']);
check('fan with no output (hub) listed apart', ($h['otherFans'][0]['tach'] ?? '') === "{$pfx}fan6");
check('CPU sensor k10temp Tctl', ($h['sources']['cpu'][0]['label'] ?? '') === 'Tctl');
check('two graphics cards', count($h['sources']['gpus']) === 2 && $h['sources']['gpus'][1]['bus'] === '05:00.0');
check('disks counted (flash left out)', $h['sources']['disks'] === ['hdd' => 8, 'ssd' => 2], $h['sources']['disks']);
check('BIOS mode back after detection', val('nct6799', 'pwm1_enable') === '5' && val('nct6799', 'pwm3_enable') === '5');

section('control');
gpu_temp(40, 38);
daemon('--passes 8');
$s = state();
check('auto mode', $s['mode'] === 'auto', $s['mode']);
check('disks asleep count as cold', $s['disks']['asleep'] === 2 && $s['temps']['hdd'] == 37, $s['temps']);
$caseBefore = fan('CHA_FAN1')['pct'];
$cpuBefore = fan('CPU_FAN')['pct'];
gpu_temp(82, 50);
daemon('--passes 8');
check('graphics card heats up: case fans follow', fan('CHA_FAN1')['pct'] > $caseBefore && fan('CHA_FAN1')['by'] === 'gpu', [$caseBefore, fan('CHA_FAN1')]);
check('CPU fan only follows the CPU', fan('CPU_FAN')['pct'] == $cpuBefore && fan('CPU_FAN')['by'] === 'cpu', fan('CPU_FAN'));
check('output in manual mode with the right value', val('nct6799', 'pwm1_enable') === '1' && (int)val('nct6799', 'pwm1') === ff_pct_to_pwm(fan('CHA_FAN1')['pct']));
check('history kept', count($s['history']['t']) > 0 && isset(state()['history']['temps']['gpu']));
gpu_temp(40, 38);

section('safety');
setval('k10temp', 'temp1_input', '93000');
daemon('--passes 1');
check('critical CPU: everything at 100 %', state()['mode'] === 'critical' && val('nct6799', 'pwm2') === '255' && val('nct6799', 'pwm3') === '255', state()['mode']);
setval('k10temp', 'temp1_input', '48000');
unlink(hwfile('k10temp', 'temp1_input'));
daemon('--passes 3');
check('CPU sensor lost: failsafe at 100 %', state()['mode'] === 'failsafe' && val('nct6799', 'pwm1') === '255', state()['mode']);
setval('k10temp', 'temp1_input', '48000');
daemon('--passes 2');
check('sensor back: auto again', state()['mode'] === 'auto');

section('hand-back to the BIOS');
daemon('--restore');
check('pwm_enable restored', val('nct6799', 'pwm1_enable') === '5' && val('nct6799', 'pwm2_enable') === '5');
file_put_contents("$simRoot/boot/config/plugins/fanflow/fanflow.json", json_encode(['enabled' => false]));
daemon('--passes 2');
check('switched off: BIOS mode, outputs untouched', state()['mode'] === 'bios' && val('nct6799', 'pwm1_enable') === '5');
unlink("$simRoot/boot/config/plugins/fanflow/fanflow.json");

section('per-fan modes');
$cfgFans = ff_load_config(hw())['fans'];
$cfgFans["{$pfx}pwm1"]['mode'] = 'fixed'; $cfgFans["{$pfx}pwm1"]['fixed'] = 70;
$cfgFans["{$pfx}pwm3"]['mode'] = 'bios';
file_put_contents("$simRoot/boot/config/plugins/fanflow/fanflow.json", json_encode(['fans' => $cfgFans]));
daemon('--passes 2');
check('fixed speed', fan('CHA_FAN1')['pct'] == 70 && (int)val('nct6799', 'pwm1') === ff_pct_to_pwm(70));
check('one fan left to the BIOS', val('nct6799', 'pwm3_enable') === '5' && fan('CHA_FAN2')['by'] === 'bios');
unlink("$simRoot/boot/config/plugins/fanflow/fanflow.json");

section('save.php');
function post_save(array $config): array {
  global $simRoot, $src;
  $code = '$_SERVER["REQUEST_METHOD"] = "POST"; $_POST["config"] = ' . var_export(json_encode($config), true) . '; include ' . var_export("$src/include/save.php", true) . ';';
  return json_decode((string)shell_exec('FANFLOW_ROOT=' . escapeshellarg($simRoot) . ' php -r ' . escapeshellarg($code)), true) ?? [];
}
$conf = ff_load_config(hw());
$conf['fans']["{$pfx}pwm1"]['name'] = 'Façade';
$r = post_save($conf);
check('settings saved', ($r['ok'] ?? false) && ff_read_json("$simRoot/boot/config/plugins/fanflow/fanflow.json")['fans']["{$pfx}pwm1"]['name'] === 'Façade', $r);
$conf['fans']['nct6799:nct6775.656/pwm9'] = $conf['fans']["{$pfx}pwm1"];
$r = post_save($conf);
check('unknown fan refused', ($r['ok'] ?? true) === false, $r);
unlink("$simRoot/boot/config/plugins/fanflow/fanflow.json");

section('another program drives the fans');
daemon('--restore');
mkdir("$simRoot/proc/4242", 0755, true);
file_put_contents("$simRoot/proc/4242/cmdline", "/usr/sbin/fancontrol\0");
daemon('--passes 2');
check('read-only while fancontrol runs', state()['mode'] === 'readonly' && val('nct6799', 'pwm1_enable') === '5' && in_array('fancontrol', state()['conflicts'], true));
exec('rm -rf ' . escapeshellarg("$simRoot/proc/4242"));

section('stalled fan');
$sim = json_decode(file_get_contents("$simRoot/sim.json"), true);
$sim['fans'][0]['stuck'] = true;
file_put_contents("$simRoot/sim.json", json_encode($sim));
daemon('--passes 5');
check('0 rpm while driven: flagged', fan('CHA_FAN1')['stalled'] === true, fan('CHA_FAN1'));

section('reboot: hwmon numbers change');
$saved = file_get_contents("$simRoot/boot/config/plugins/fanflow/hardware.json");
sim('asus-b650', true);
@mkdir("$simRoot/boot/config/plugins/fanflow", 0755, true);
file_put_contents("$simRoot/boot/config/plugins/fanflow/hardware.json", $saved);
$before = filemtime("$simRoot/boot/config/plugins/fanflow/hardware.json");
daemon('--passes 3');
check('same fans recognised, no new detection', array_keys(hw()['fans']) === $ids && state()['changes']['new'] === [], state()['changes']);
check('fans driven on the renumbered chip', state()['mode'] === 'auto' && val('nct6799', 'pwm1_enable') === '1');

section('new output after a reboot');
$h = json_decode($saved, true);
unset($h['fans']["{$pfx}pwm3"]);
file_put_contents("$simRoot/boot/config/plugins/fanflow/hardware.json", json_encode($h));
daemon('--passes 1');
check('only the new output is tested and added', isset(hw()['fans']["{$pfx}pwm3"]) && count(hw()['fans']) === 3);

// --- other machines -------------------------------------------------------------------------
section('detection: Intel + ITE, no graphics card');
sim('intel-it87');
daemon('--detect');
$h = hw();
check('two fans', count($h['fans']) === 2, array_keys($h['fans']));
check('output 3 drives tachometer 2', ($h['fans']['it8689:it87.2624/pwm3']['tach'] ?? '') === 'it8689:it87.2624/fan2');
check('coretemp package as CPU', ($h['sources']['cpu'][0]['label'] ?? '') === 'Package id 0');
check('no graphics card', $h['sources']['gpus'] === []);
check('no names without a known board', $h['fans']['it8689:it87.2624/pwm1']['label'] === null);
daemon('--passes 3');
check('auto mode', state()['mode'] === 'auto' && state()['temps']['gpu'] === null);

section('detection: no drivable output, AMD card');
sim('no-pwm');
daemon('--detect');
$h = hw();
check('nothing to drive', $h['fans'] === []);
check('the card fan is never taken', !in_array('amdgpu:0000:03:00.0/pwm1', $h['emptyOutputs'], true));
check('fans shown as readings only', count($h['otherFans']) === 3, $h['otherFans']);
check('AMD card temperature read', ($h['sources']['gpus'][0]['vendor'] ?? '') === 'amd');
daemon('--passes 2');
check('read-only mode', state()['mode'] === 'readonly' && state()['temps']['gpu'] == 51, state()['temps']);
check('card fan untouched', val('amdgpu', 'pwm1_enable') === '2');

exec('rm -rf ' . escapeshellarg($simRoot));
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
