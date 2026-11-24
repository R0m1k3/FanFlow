<?php
// FanFlow - current state for the Dashboard tile and the settings page (read only).
//   GET status.php          state written by fanflowd at every pass
//   GET status.php?full=1   plus the detected hardware, the settings and the profiles
require_once __DIR__ . '/lib/common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$state = ff_read_json(ff_state_file(), ['mode' => 'stopped']);
$pid = (int)@file_get_contents(ff_run_dir() . '/fanflowd.pid');
$alive = $pid > 0 && (ff_root() !== '' || is_dir("/proc/$pid"));
if (!$alive) $state['mode'] = 'stopped';
elseif (($state['mode'] ?? '') !== 'detecting' && time() - (int)($state['updated'] ?? 0) > 3 * (int)($state['interval'] ?? 4) + 15) $state['mode'] = 'stale';

if (!empty($_GET['full'])) {
  $hardware = ff_read_json(ff_hardware_file());
  $state['hardware'] = $hardware;
  $state['config'] = ff_load_config($hardware);
  $state['presets'] = ff_presets();
}
echo json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
