<?php
// FanFlow - Unraid notifications, in the webGUI's language (French or English).

function ff_lang(): string {
  $cfg = @parse_ini_file(ff_path('/boot/config/plugins/dynamix/dynamix.cfg'), true, INI_SCANNER_RAW);
  $locale = $cfg['display']['locale'] ?? ($cfg['locale'] ?? '');
  return str_starts_with((string)$locale, 'fr') ? 'fr' : 'en';
}

function ff_message(string $key, array $vars = []): string {
  static $text = [
    'en' => [
      'critical.subject' => 'FanFlow: {source} at {temp} °C',
      'critical.body' => 'The {source} temperature reached {temp} °C (limit {limit} °C). All fans run at full speed until it drops.',
      'failsafe.subject' => 'FanFlow: CPU temperature unreadable',
      'failsafe.body' => 'The CPU sensor does not answer. All fans run at full speed until it answers again.',
      'stall.subject' => 'FanFlow: {fan} is not turning',
      'stall.body' => '{fan} reads 0 rpm while it is asked for {pct} %. Check its cable and that nothing blocks it.',
      'conflict.subject' => 'FanFlow: another program drives the fans',
      'conflict.body' => '{what} changes the fan speeds. FanFlow only shows readings until it stops.',
      'source.cpu' => 'CPU', 'source.gpu' => 'graphics card', 'source.hdd' => 'hard disk', 'source.ssd' => 'SSD',
    ],
    'fr' => [
      'critical.subject' => 'FanFlow : {source} à {temp} °C',
      'critical.body' => 'La température {source} a atteint {temp} °C (limite {limit} °C). Tous les ventilateurs tournent à fond jusqu\'à ce qu\'elle baisse.',
      'failsafe.subject' => 'FanFlow : température CPU illisible',
      'failsafe.body' => 'Le capteur du CPU ne répond plus. Tous les ventilateurs tournent à fond jusqu\'à ce qu\'il réponde.',
      'stall.subject' => 'FanFlow : {fan} ne tourne pas',
      'stall.body' => '{fan} affiche 0 tr/min alors qu\'il est commandé à {pct} %. Vérifiez son câble et que rien ne le bloque.',
      'conflict.subject' => 'FanFlow : un autre programme pilote les ventilateurs',
      'conflict.body' => '{what} change la vitesse des ventilateurs. FanFlow se contente d\'afficher les mesures tant qu\'il tourne.',
      'source.cpu' => 'du CPU', 'source.gpu' => 'de la carte graphique', 'source.hdd' => 'des disques', 'source.ssd' => 'des SSD',
    ],
  ];
  $lang = ff_lang();
  $s = $text[$lang][$key] ?? $text['en'][$key] ?? $key;
  foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s);
  return $s;
}

// Sends one notification, at most once every 30 minutes for the same $key.
function ff_notify(string $key, string $subject, string $body, string $level = 'warning'): void {
  static $last = [];
  $now = ff_now();
  if (isset($last[$key]) && $now - $last[$key] < 1800) return;
  $last[$key] = $now;
  $script = ff_path('/usr/local/emhttp/webGui/scripts/notify');
  if (!is_executable($script)) { ff_log("notice: $subject - $body"); return; }
  exec(escapeshellarg($script) . ' -e FanFlow -s ' . escapeshellarg($subject) . ' -d ' . escapeshellarg($body)
    . ' -i ' . escapeshellarg($level) . ' -l /Settings/FanFlow >/dev/null 2>&1 &');
}
