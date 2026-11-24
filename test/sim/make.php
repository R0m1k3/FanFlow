<?php
// Builds a simulated machine for FanFlow: a fake /sys/class/hwmon, DMI, Unraid's disk files and
// an nvidia-smi that answers from a CSV file.
//
//   php test/sim/make.php <profile> <dir> [--shuffle]
//
// Then run FanFlow against it with FANFLOW_ROOT=<dir> FANFLOW_SIM_HOOK=test/sim/physics.php.
// --shuffle numbers the hwmon folders in another order, as a reboot can.

function sim_profiles(): array {
  $n = 0;
  $hdd = function ($name, $temp, $asleep = false) use (&$n) { return ['name' => $name, 'device' => 'sd' . chr(97 + $n++), 'rotational' => '1', 'temp' => $asleep ? '*' : (string)$temp, 'spundown' => $asleep ? '1' : '0', 'type' => str_starts_with($name, 'parity') ? 'Parity' : 'Data']; };
  return [
    // Reference machine: ASUS TUF GAMING B650M-PLUS WIFI, Ryzen 7 8700F, RTX 5060 Ti + RTX 3060, 8 disks.
    'asus-b650' => [
      'dmi' => ['board_vendor' => 'ASUSTeK COMPUTER INC.', 'board_name' => 'TUF GAMING B650M-PLUS WIFI', 'sys_vendor' => 'ASUS'],
      'chips' => [
        ['name' => 'nvme', 'device' => 'nvme0', 'temps' => [1 => ['Composite', 41000]]],
        ['name' => 'k10temp', 'device' => '0000:00:18.3', 'temps' => [1 => ['Tctl', 48000], 3 => ['Tccd1', 46000]]],
        ['name' => 'nct6799', 'device' => 'nct6775.656', 'temps' => [1 => ['SYSTIN', 33000], 2 => ['CPUTIN', 38000]],
          'fans' => [1, 2, 3, 4, 5, 6, 7], 'pwms' => [1, 2, 3, 4, 5, 6, 7], 'enable' => 5],
      ],
      // output -> tachometer, speeds, below which % the fan stops, % the BIOS runs it at
      'fans' => [
        ['chip' => 'nct6799', 'pwm' => 1, 'tach' => 1, 'max' => 1400, 'min' => 350, 'stop' => 15, 'bios' => 40],
        ['chip' => 'nct6799', 'pwm' => 2, 'tach' => 2, 'max' => 1900, 'min' => 400, 'stop' => 10, 'bios' => 45],
        ['chip' => 'nct6799', 'pwm' => 3, 'tach' => 3, 'max' => 1200, 'min' => 300, 'stop' => 28, 'bios' => 40],
      ],
      'fixed' => [['chip' => 'nct6799', 'tach' => 6, 'rpm' => 950]],
      'gpus' => [[0, 'NVIDIA GeForce RTX 5060 Ti', '00000000:01:00.0', 42], [1, 'NVIDIA GeForce RTX 3060', '00000000:05:00.0', 38]],
      'disks' => [
        'parity' => $hdd('parity', 36), 'disk1' => $hdd('disk1', 35), 'disk2' => $hdd('disk2', 34), 'disk3' => $hdd('disk3', 0, true),
        'disk4' => $hdd('disk4', 37), 'disk5' => $hdd('disk5', 0, true), 'disk6' => $hdd('disk6', 33), 'disk7' => $hdd('disk7', 36),
        'cache_vm' => ['name' => 'cache_vm', 'device' => 'nvme0n1', 'rotational' => '0', 'transport' => 'nvme', 'temp' => '41', 'spundown' => '0', 'type' => 'Cache'],
        'flash' => ['name' => 'flash', 'device' => 'sdz', 'type' => 'Flash', 'temp' => '*'],
      ],
      'devs' => ['dev1' => ['name' => 'dev1', 'device' => 'sdy', 'rotational' => '0', 'temp' => '30', 'spundown' => '0']],
      'locale' => 'fr_FR',
    ],
    // An Intel machine with an ITE chip and no graphics card.
    'intel-it87' => [
      'dmi' => ['board_vendor' => 'Gigabyte Technology Co., Ltd.', 'board_name' => 'B760M DS3H', 'sys_vendor' => 'Gigabyte'],
      'chips' => [
        ['name' => 'coretemp', 'device' => 'coretemp.0', 'temps' => [1 => ['Package id 0', 44000], 2 => ['Core 0', 41000]]],
        ['name' => 'it8689', 'device' => 'it87.2624', 'temps' => [1 => [null, 31000]], 'fans' => [1, 2, 3], 'pwms' => [1, 2, 3], 'enable' => 2],
      ],
      'fans' => [
        ['chip' => 'it8689', 'pwm' => 1, 'tach' => 1, 'max' => 2100, 'min' => 500, 'stop' => 12, 'bios' => 35],
        ['chip' => 'it8689', 'pwm' => 3, 'tach' => 2, 'max' => 1100, 'min' => 300, 'stop' => 20, 'bios' => 35],
      ],
      'fixed' => [],
      'gpus' => [],
      'disks' => ['disk1' => $hdd('disk1', 33), 'disk2' => $hdd('disk2', 32), 'disk3' => $hdd('disk3', 31), 'parity' => $hdd('parity', 34)],
      'devs' => [],
      'locale' => 'en_US',
    ],
    // A board whose fan outputs cannot be driven, with an AMD card (its fan stays the card's).
    'no-pwm' => [
      'dmi' => ['board_vendor' => 'Dell Inc.', 'board_name' => '0K240Y', 'sys_vendor' => 'Dell Inc.'],
      'chips' => [
        ['name' => 'k10temp', 'device' => '0000:00:18.3', 'temps' => [1 => ['Tctl', 50000]]],
        ['name' => 'dell_smm', 'device' => 'dell_smm_hwmon', 'temps' => [], 'fans' => [1, 2], 'pwms' => [1, 2], 'noEnable' => true],
        ['name' => 'amdgpu', 'device' => '0000:03:00.0', 'temps' => [1 => ['edge', 45000], 2 => ['junction', 51000]], 'fans' => [1], 'pwms' => [1], 'enable' => 2],
      ],
      'fans' => [],
      'fixed' => [['chip' => 'dell_smm', 'tach' => 1, 'rpm' => 1100], ['chip' => 'dell_smm', 'tach' => 2, 'rpm' => 800], ['chip' => 'amdgpu', 'tach' => 1, 'rpm' => 900]],
      'gpus' => [],
      'disks' => ['disk1' => $hdd('disk1', 30)],
      'devs' => [],
      'locale' => 'en_US',
    ],
  ];
}

function sim_put(string $file, string $content): void {
  if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
  file_put_contents($file, $content);
}

function sim_ini(array $sections): string {
  $out = '';
  foreach ($sections as $name => $values) {
    $out .= "[$name]\n";
    foreach ($values as $k => $v) $out .= "$k=\"$v\"\n";
  }
  return $out;
}

function sim_make(string $profileName, string $root, bool $shuffle = false): array {
  $p = sim_profiles()[$profileName] ?? throw new RuntimeException("unknown profile $profileName");
  if (is_dir($root)) exec('rm -rf ' . escapeshellarg($root));
  mkdir($root, 0755, true);
  $root = realpath($root);

  foreach ($p['dmi'] as $k => $v) sim_put("$root/sys/class/dmi/id/$k", "$v\n");
  $chips = $p['chips'];
  if ($shuffle) $chips = array_reverse($chips);
  $dirs = [];
  foreach ($chips as $n => $c) {
    $dev = "$root/sys/devices/platform/{$c['device']}";
    $dir = "$root/sys/class/hwmon/hwmon$n";
    @mkdir($dev, 0755, true);
    @mkdir($dir, 0755, true);
    symlink($dev, "$dir/device");
    sim_put("$dir/name", "{$c['name']}\n");
    foreach ($c['temps'] as $ch => [$label, $value]) {
      sim_put("$dir/temp{$ch}_input", "$value\n");
      if ($label !== null) sim_put("$dir/temp{$ch}_label", "$label\n");
    }
    foreach ($c['fans'] ?? [] as $ch) sim_put("$dir/fan{$ch}_input", "0\n");
    foreach ($c['pwms'] ?? [] as $ch) {
      sim_put("$dir/pwm$ch", "128\n");
      if (empty($c['noEnable'])) sim_put("$dir/pwm{$ch}_enable", ($c['enable'] ?? 2) . "\n");
    }
    $dirs[$c['name']] = "hwmon$n";
  }

  $gpus = '';
  foreach ($p['gpus'] as [$i, $name, $bus, $temp]) $gpus .= "$i, $name, $bus, $temp\n";
  if ($p['gpus']) {
    sim_put("$root/gpus.csv", $gpus);
    sim_put("$root/usr/bin/nvidia-smi", "#!/bin/sh\ncat \"\$FANFLOW_ROOT/gpus.csv\"\n");
    chmod("$root/usr/bin/nvidia-smi", 0755);
  }
  sim_put("$root/var/local/emhttp/disks.ini", sim_ini($p['disks']));
  sim_put("$root/var/local/emhttp/devs.ini", sim_ini($p['devs']));
  sim_put("$root/boot/config/plugins/dynamix/dynamix.cfg", "[display]\nlocale=\"{$p['locale']}\"\n");
  @mkdir("$root/proc", 0755, true);

  $sim = ['profile' => $profileName, 'fans' => [], 'fixed' => []];
  foreach ($p['fans'] as $f) $sim['fans'][] = $f + ['dir' => $dirs[$f['chip']]];
  foreach ($p['fixed'] as $f) $sim['fixed'][] = $f + ['dir' => $dirs[$f['chip']]];
  $sim['dirs'] = $dirs;
  file_put_contents("$root/sim.json", json_encode($sim, JSON_PRETTY_PRINT));
  require_once __DIR__ . '/physics.php';
  sim_step($root);
  return $sim;
}

if (PHP_SAPI === 'cli' && realpath($argv[0]) === __FILE__) {
  if ($argc < 3) { fwrite(STDERR, "usage: php make.php <" . implode('|', array_keys(sim_profiles())) . "> <dir> [--shuffle]\n"); exit(1); }
  sim_make($argv[1], $argv[2], in_array('--shuffle', $argv, true));
  echo "simulated machine '{$argv[1]}' in {$argv[2]}\n";
}
