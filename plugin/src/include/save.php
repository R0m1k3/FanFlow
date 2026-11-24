<?php
// FanFlow - saves the settings sent by the settings page (POST, with Unraid's csrf_token, which
// the webGUI checks before this file runs). fanflowd notices the new file at its next pass.
//   POST config=<JSON>
require_once __DIR__ . '/lib/common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ff_reply(int $code, array $body): void {
  http_response_code($code);
  echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ff_reply(405, ['ok' => false, 'error' => 'POST only']);
$in = json_decode((string)($_POST['config'] ?? ''), true);
if (!is_array($in)) ff_reply(400, ['ok' => false, 'error' => 'invalid JSON']);

// Only fans the detection found can be configured.
$hardware = ff_read_json(ff_hardware_file(), []);
foreach (array_keys((array)($in['fans'] ?? [])) as $id) {
  if (!isset($hardware['fans'][$id])) ff_reply(400, ['ok' => false, 'error' => "unknown fan $id"]);
}
[$config, $error] = ff_validate_config($in);
if ($error !== null) ff_reply(400, ['ok' => false, 'error' => $error]);
if (!ff_write_json(ff_config_file(), $config)) ff_reply(500, ['ok' => false, 'error' => 'cannot write ' . ff_config_file()]);
ff_reply(200, ['ok' => true, 'config' => ff_load_config($hardware)]);
