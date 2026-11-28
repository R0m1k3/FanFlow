/* FanFlow - texts in French and English, picked from the webGUI's language (<html lang>),
   plus small helpers shared by the Dashboard tile and the settings page. */
(function () {
  'use strict';
  var TEXT = {
    en: {
      'mode.auto': 'Auto', 'mode.bios': 'BIOS', 'mode.readonly': 'Read only', 'mode.failsafe': 'Safety',
      'mode.critical': 'Critical', 'mode.detecting': 'Detecting', 'mode.starting': 'Starting', 'mode.stopped': 'Stopped', 'mode.stale': 'Not answering',
      'preset.silent': 'Silent', 'preset.balanced': 'Balanced', 'preset.performance': 'Performance', 'preset.custom': 'Custom',
      'src.cpu': 'CPU', 'src.gpu': 'GPU', 'src.gpus': 'All GPUs', 'src.hdd': 'Disks', 'src.ssd': 'SSD', 'src.fixed': 'Fixed', 'src.bios': 'BIOS',
      'role.cpu': 'CPU fan', 'role.case': 'Case fan',
      'fan': 'Fan {n}', 'rpm': 'rpm', 'asleep': '{n} asleep', 'allAsleep': 'all asleep', 'noReading': 'no reading',
      'updated': 'updated {s} s ago', 'drivenBy': 'driven by {src}', 'stalled': 'not turning',
      'tile.noFans': 'No fan FanFlow can drive was found. Readings only.',
      'tile.others': '{n} fan(s) not drivable: {list}', 'other.tag': '3-pin', 'other.tip': 'Not drivable: runs by itself, FanFlow only shows it.', 'other.on': 'running', 'other.off': 'stopped', 'other.onSince': 'running since {t}', 'other.offSince': 'stopped since {t}',
      'banner.detecting': 'Detecting the fans: each one speeds up for a few seconds ({done}/{total}).',
      'banner.critical': '{src} at {temp} °C (limit {limit} °C): every fan at full speed.',
      'banner.failsafe': 'CPU temperature unreadable: every fan at full speed.',
      'banner.readonly': 'Another program drives the fans ({what}). FanFlow only shows readings.',
      'banner.external': 'a fan was changed outside FanFlow',
      'banner.externalFan': '{fan} was changed outside FanFlow; FanFlow tries again every 5 minutes, saving the settings takes it back at once',
      'banner.bios': 'FanFlow is switched off: the BIOS drives the fans.',
      'banner.stopped': 'The FanFlow service is not running.',
      'banner.new': 'New fan output(s) found: {n}.', 'banner.missing': '{n} fan(s) no longer found: {list}.',
      'set.title': 'FanFlow', 'set.save': 'Apply', 'set.revert': 'Cancel', 'set.saved': 'Saved. FanFlow uses the new settings.',
      'set.saveError': 'Not saved: {e}', 'set.unsaved': 'Unsaved changes',
      'set.hw': 'Detected hardware', 'set.redetect': 'Detect again', 'set.redetectConfirm': 'Each fan will speed up to full for a few seconds, then slow down step by step. It takes a few minutes. Start?',
      'set.board': 'Motherboard', 'set.chips': 'Sensor chips', 'set.cpuSensor': 'CPU sensor', 'set.gpus': 'Graphics cards', 'set.disks': 'Disks',
      'set.fans': 'Fans', 'set.fansSummary': '{a} drivable, {b} not drivable, {c} empty output(s)', 'set.detectedAt': 'Detected {d}',
      'set.disksSummary': '{h} hard disk(s), {s} SSD', 'set.none': 'none', 'set.gpuOwnFans': 'Graphics cards keep driving their own fans; their temperature drives the case fans.',
      'set.acpi': 'The sensor chip is blocked by the firmware (ACPI). Adding acpi_enforce_resources=lax to the boot options (Main > Flash > Syslinux configuration) usually frees it; then reboot and detect again.',
      'set.noOutputs': 'No fan output can be driven on this machine. FanFlow shows the readings.',
      'set.probable': 'Header names come from the usual wiring of this board: check them and rename if needed.',
      'set.driversTried': 'Drivers tried: {list}',
      'set.general': 'General', 'set.enabled': 'FanFlow drives the fans', 'set.enabledHint': 'Off: the BIOS drives them.',
      'set.preset': 'Profile', 'set.presetHint': 'Applies its curves to every fan.',
      'set.interval': 'Check every', 'set.seconds': 's', 'set.hysteresis': 'Slow down after a drop of', 'set.degrees': '°C',
      'set.rampUp': 'Largest rise per check', 'set.rampDown': 'Largest drop per check', 'set.pct': '%',
      'set.critical': 'Full speed from', 'set.notify': 'Unraid notifications (critical temperature, fan stopped)',
      'set.name': 'Name', 'set.role': 'Role', 'set.mode': 'Mode', 'set.modeAuto': 'Auto', 'set.modeFixed': 'Fixed', 'set.modeBios': 'BIOS',
      'set.fixed': 'Speed', 'set.min': 'Minimum', 'set.allowStop': 'May stop below the minimum',
      'set.curves': 'Follows', 'set.curveHelp': 'Drag the points. Double-click to add a point, double-click a point to remove it.',
      'set.now': 'now {t} °C → {p} %', 'set.calib': '{max} rpm at full speed, safe minimum {min} %', 'set.calibAborted': 'calibration stopped (CPU too hot)',
      'set.output': 'Output {o}', 'set.notDrivable': 'Fans not drivable', 'set.empty': 'Empty outputs',
      'set.needSource': 'Keep at least one source.', 'set.loading': 'Loading…'
    },
    fr: {
      'mode.auto': 'Auto', 'mode.bios': 'BIOS', 'mode.readonly': 'Lecture seule', 'mode.failsafe': 'Sécurité',
      'mode.critical': 'Critique', 'mode.detecting': 'Détection', 'mode.starting': 'Démarrage', 'mode.stopped': 'Arrêté', 'mode.stale': 'Ne répond pas',
      'preset.silent': 'Silencieux', 'preset.balanced': 'Équilibré', 'preset.performance': 'Performance', 'preset.custom': 'Personnalisé',
      'src.cpu': 'CPU', 'src.gpu': 'GPU', 'src.gpus': 'Tous les GPU', 'src.hdd': 'Disques', 'src.ssd': 'SSD', 'src.fixed': 'Fixe', 'src.bios': 'BIOS',
      'role.cpu': 'Ventilateur CPU', 'role.case': 'Ventilateur boîtier',
      'fan': 'Ventilateur {n}', 'rpm': 'tr/min', 'asleep': '{n} en veille', 'allAsleep': 'tous en veille', 'noReading': 'pas de mesure',
      'updated': 'mis à jour il y a {s} s', 'drivenBy': 'commandé par {src}', 'stalled': 'ne tourne pas',
      'tile.noFans': 'Aucun ventilateur pilotable trouvé. Affichage des mesures seulement.',
      'tile.others': '{n} ventilateur(s) non pilotable(s) : {list}', 'other.tag': '3 broches', 'other.tip': 'Non pilotable : il tourne tout seul, FanFlow l\'affiche seulement.', 'other.on': 'en marche', 'other.off': 'arrêté', 'other.onSince': 'en marche depuis {t}', 'other.offSince': 'arrêté depuis {t}',
      'banner.detecting': 'Détection des ventilateurs : chacun accélère quelques secondes ({done}/{total}).',
      'banner.critical': '{src} à {temp} °C (limite {limit} °C) : tous les ventilateurs à fond.',
      'banner.failsafe': 'Température CPU illisible : tous les ventilateurs à fond.',
      'banner.readonly': 'Un autre programme pilote les ventilateurs ({what}). FanFlow affiche seulement les mesures.',
      'banner.external': 'un ventilateur a été changé en dehors de FanFlow',
      'banner.externalFan': '{fan} a été changé en dehors de FanFlow ; nouvel essai toutes les 5 minutes, enregistrer les réglages reprend la main tout de suite',
      'banner.bios': 'FanFlow est désactivé : le BIOS pilote les ventilateurs.',
      'banner.stopped': 'Le service FanFlow ne tourne pas.',
      'banner.new': 'Nouvelle(s) sortie(s) de ventilateur trouvée(s) : {n}.', 'banner.missing': '{n} ventilateur(s) introuvable(s) : {list}.',
      'set.title': 'FanFlow', 'set.save': 'Appliquer', 'set.revert': 'Annuler', 'set.saved': 'Enregistré. FanFlow applique les nouveaux réglages.',
      'set.saveError': 'Non enregistré : {e}', 'set.unsaved': 'Modifications non enregistrées',
      'set.hw': 'Matériel détecté', 'set.redetect': 'Re-détecter', 'set.redetectConfirm': 'Chaque ventilateur va monter à fond quelques secondes, puis ralentir par paliers. Cela prend quelques minutes. Lancer ?',
      'set.board': 'Carte mère', 'set.chips': 'Puces de capteurs', 'set.cpuSensor': 'Capteur CPU', 'set.gpus': 'Cartes graphiques', 'set.disks': 'Disques',
      'set.fans': 'Ventilateurs', 'set.fansSummary': '{a} pilotable(s), {b} non pilotable(s), {c} sortie(s) vide(s)', 'set.detectedAt': 'Détecté le {d}',
      'set.disksSummary': '{h} disque(s) dur(s), {s} SSD', 'set.none': 'aucune', 'set.gpuOwnFans': 'Les cartes graphiques gardent la main sur leurs ventilateurs ; leur température commande les ventilateurs du boîtier.',
      'set.acpi': 'La puce des capteurs est bloquée par le firmware (ACPI). Ajouter acpi_enforce_resources=lax aux options de démarrage (Main > Flash > Syslinux configuration) la libère en général ; redémarrer puis re-détecter.',
      'set.noOutputs': 'Aucune sortie de ventilateur n\'est pilotable sur cette machine. FanFlow affiche les mesures.',
      'set.probable': 'Les noms des connecteurs viennent du câblage habituel de cette carte : vérifiez-les et renommez si besoin.',
      'set.driversTried': 'Pilotes essayés : {list}',
      'set.general': 'Général', 'set.enabled': 'FanFlow pilote les ventilateurs', 'set.enabledHint': 'Désactivé : le BIOS les pilote.',
      'set.preset': 'Profil', 'set.presetHint': 'Applique ses courbes à tous les ventilateurs.',
      'set.interval': 'Vérifier toutes les', 'set.seconds': 's', 'set.hysteresis': 'Ralentir après une baisse de', 'set.degrees': '°C',
      'set.rampUp': 'Hausse maximale par vérification', 'set.rampDown': 'Baisse maximale par vérification', 'set.pct': '%',
      'set.critical': 'À fond à partir de', 'set.notify': 'Notifications Unraid (température critique, ventilateur arrêté)',
      'set.name': 'Nom', 'set.role': 'Rôle', 'set.mode': 'Mode', 'set.modeAuto': 'Auto', 'set.modeFixed': 'Fixe', 'set.modeBios': 'BIOS',
      'set.fixed': 'Vitesse', 'set.min': 'Minimum', 'set.allowStop': 'Peut s\'arrêter sous le minimum',
      'set.curves': 'Suit', 'set.curveHelp': 'Faites glisser les points. Double-clic pour ajouter un point, double-clic sur un point pour le supprimer.',
      'set.now': 'maintenant {t} °C → {p} %', 'set.calib': '{max} tr/min à fond, minimum conseillé {min} %', 'set.calibAborted': 'calibrage interrompu (CPU trop chaud)',
      'set.output': 'Sortie {o}', 'set.notDrivable': 'Ventilateurs non pilotables', 'set.empty': 'Sorties vides',
      'set.needSource': 'Gardez au moins une source.', 'set.loading': 'Chargement…'
    }
  };
  var lang = (document.documentElement.getAttribute('lang') || 'en').slice(0, 2).toLowerCase();
  var dict = TEXT[lang] || TEXT.en;

  function t(key, vars) {
    var s = dict[key] != null ? dict[key] : (TEXT.en[key] != null ? TEXT.en[key] : key);
    if (vars) Object.keys(vars).forEach(function (k) { s = s.split('{' + k + '}').join(vars[k]); });
    return s;
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  // Short card name: "NVIDIA GeForce RTX 5060 Ti" -> "RTX 5060 Ti"
  function gpuName(g) {
    return String(g.name || 'GPU').replace(/^(NVIDIA|AMD|Intel\(R\))\s+/i, '').replace(/^GeForce\s+/i, '').replace(/^Radeon\s+/i, 'RX ').trim();
  }
  function sourceName(src, state) {
    if (src && src.indexOf('gpu:') === 0) {
      var gpus = (state && state.gpus) || [];
      for (var i = 0; i < gpus.length; i++) if (gpus[i].id === src) return gpuName(gpus[i]);
      return 'GPU ' + src.slice(4);
    }
    return t('src.' + src);
  }
  // A fan with no name of its own is called after its output: "Fan 3" for pwm3.
  function fanName(f) {
    if (f.name && f.name !== f.id) return f.name;
    var m = /(?:pwm|fan)(\d+)$/.exec(f.id || '');
    return t('fan', { n: m ? m[1] : '?' });
  }
  // What the read-only banner lists: the programs found, or the fan changed behind FanFlow's back.
  function conflictText(state) {
    return (state.conflicts || []).map(function (c) {
      if (c !== 'external') return c;
      var id = (state.external || {}).fan, f = null;
      (state.fans || []).forEach(function (x) { if (x.id === id) f = x; });
      return f ? t('banner.externalFan', { fan: fanName(f) }) : t('banner.external');
    }).join(', ');
  }
  // State of a fan FanFlow does not drive: "running since 14:32", "stopped since 14:40".
  function otherStatus(o) {
    var on = o.on != null ? o.on : o.rpm > 0;
    if (!o.since) return t(on ? 'other.on' : 'other.off');
    var d = new Date(o.since * 1000), now = new Date();
    var time = d.toLocaleTimeString(lang, { hour: '2-digit', minute: '2-digit' });
    if (d.toDateString() !== now.toDateString()) time = d.toLocaleDateString(lang, { day: 'numeric', month: 'short' }) + ' ' + time;
    return t(on ? 'other.onSince' : 'other.offSince', { t: time });
  }
  // Heat level of a temperature against its critical limit: cool / warm / hot.
  function heat(temp, limit) {
    if (temp == null || !limit) return 'none';
    var r = temp / limit;
    return r < 0.75 ? 'cool' : (r < 0.9 ? 'warm' : 'hot');
  }
  function limitFor(src, critical) {
    critical = critical || {};
    return critical[src && src.indexOf('gpu') === 0 ? 'gpu' : src];
  }
  // A small fan rotor; its spin speed follows the rpm (paused at 0).
  function rotor(rpm, extra) {
    var spin = rpm > 0 ? ' style="animation-duration:' + Math.max(0.35, Math.min(4, 1400 / rpm)).toFixed(2) + 's"' : '';
    return '<span class="ff-rotor' + (rpm > 0 ? ' ff-rotor--on' : '') + (extra ? ' ' + extra : '') + '"' + spin + ' aria-hidden="true">' +
      '<svg viewBox="-12 -12 24 24"><circle r="11" class="ff-rotor-ring"/>' +
      [0, 90, 180, 270].map(function (a) { return '<path transform="rotate(' + a + ')" d="M0-1.6C1.5-4 1.2-8.6-1.6-9.6-4.2-8.4-3.1-3.6 0-1.6Z"/>'; }).join('') +
      '<circle r="2.2" class="ff-rotor-hub"/></svg></span>';
  }
  function sparkline(values, cls) {
    var pts = (values || []).map(function (v, i) { return [i, v]; }).filter(function (p) { return p[1] != null; });
    if (pts.length < 2) return '<svg class="ff-spark ' + (cls || '') + '" viewBox="0 0 100 24" preserveAspectRatio="none"></svg>';
    var n = values.length - 1 || 1;
    var lo = Infinity, hi = -Infinity;
    pts.forEach(function (p) { lo = Math.min(lo, p[1]); hi = Math.max(hi, p[1]); });
    if (hi - lo < 4) { var mid = (hi + lo) / 2; lo = mid - 2; hi = mid + 2; }
    var line = pts.map(function (p) { return (p[0] / n * 100).toFixed(1) + ',' + (22 - (p[1] - lo) / (hi - lo) * 20).toFixed(1); }).join(' ');
    return '<svg class="ff-spark ' + (cls || '') + '" viewBox="0 0 100 24" preserveAspectRatio="none">' +
      '<polyline points="0,24 ' + line + ' 100,24" class="ff-spark-fill"/><polyline points="' + line + '" class="ff-spark-line"/></svg>';
  }

  window.FanFlow = { t: t, esc: esc, lang: lang, gpuName: gpuName, sourceName: sourceName, fanName: fanName, conflictText: conflictText, otherStatus: otherStatus, heat: heat, limitFor: limitFor, rotor: rotor, sparkline: sparkline,
    base: '/plugins/fanflow' };
})();
