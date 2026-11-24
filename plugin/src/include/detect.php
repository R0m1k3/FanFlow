<?php
// FanFlow - asks the service to detect the hardware again (POST, with Unraid's csrf_token).
// The service runs the detection itself, so a single program ever writes the fan outputs; the
// pages follow its progress in status.php.
//   POST action=detect     detect again
//   POST action=start      start the service if it is not running
require_once __DIR__ . '/lib/common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }
$action = $_POST['action'] ?? '';
if ($action === 'detect') ff_write_json(ff_request_file(), ['action' => 'detect', 'at' => time()]);
elseif ($action !== 'start') { http_response_code(400); echo '{"ok":false,"error":"unknown action"}'; exit; }

if (ff_root() === '') exec('/etc/rc.d/rc.fanflow start >/dev/null 2>&1');
echo '{"ok":true}';
