/* FanFlow - Dashboard tile: temperatures with their last ten minutes, every fan with its speed.
   Reads /plugins/fanflow/include/status.php every 3 seconds while the page is visible. */
(function () {
  'use strict';
  var F = window.FanFlow, t = F.t, esc = F.esc;
  var box = document.getElementById('fanflow-tile');
  if (!box) return;
  var sub = document.getElementById('fanflow-tile-sub');
  var timer = null;

  function banner(s) {
    var m = s.mode;
    if (m === 'detecting' && s.detect) return ['info', t('banner.detecting', { done: s.detect.done, total: s.detect.total || '…' })];
    if (m === 'critical' && s.reason) return ['hot', t('banner.critical', { src: F.sourceName(s.reason.source, s), temp: Math.round(s.reason.temp), limit: s.reason.limit })];
    if (m === 'failsafe') return ['hot', t('banner.failsafe')];
    if (m === 'readonly' && s.conflicts && s.conflicts.length) return ['warm', t('banner.readonly', { what: s.conflicts.map(function (c) { return c === 'external' ? t('banner.external') : c; }).join(', ') })];
    if (m === 'bios') return ['info', t('banner.bios')];
    if (m === 'stopped' || m === 'stale') return ['warm', t('banner.stopped')];
    return null;
  }

  function temps(s) {
    var rows = [];
    var h = (s.history && s.history.temps) || {};
    var tt = s.temps || {};
    var crit = s.critical || {};
    rows.push({ key: 'cpu', label: t('src.cpu'), temp: tt.cpu });
    (s.gpus || []).forEach(function (g) { rows.push({ key: g.id, label: F.gpuName(g), temp: tt[g.id] }); });
    var d = s.disks || {};
    if (d.hdd) rows.push({ key: 'hdd', label: t('src.hdd'), temp: tt.hdd, note: tt.hdd == null ? t('allAsleep') : (d.asleep ? t('asleep', { n: d.asleep }) : '') });
    if (d.ssd) rows.push({ key: 'ssd', label: t('src.ssd'), temp: tt.ssd });
    return '<div class="ff-temps">' + rows.map(function (r) {
      var limit = F.limitFor(r.key, crit);
      var lvl = F.heat(r.temp, limit);
      var pct = r.temp == null || !limit ? 0 : Math.max(4, Math.min(100, r.temp / limit * 100));
      return '<div class="ff-temp ff-heat--' + lvl + '">' +
        '<div class="ff-temp-head"><span class="ff-temp-label" title="' + esc(r.label) + '">' + esc(r.label) + '</span>' +
        '<span class="ff-temp-value">' + (r.temp == null ? '<span class="ff-muted">–</span>' : Math.round(r.temp) + '<small>°C</small>') + '</span></div>' +
        F.sparkline(h[r.key]) +
        '<div class="ff-gauge" title="' + (limit ? limit + ' °C' : '') + '"><i style="width:' + pct.toFixed(0) + '%"></i></div>' +
        (r.note ? '<div class="ff-temp-note">' + esc(r.note) + '</div>' : '') +
        '</div>';
    }).join('') + '</div>';
  }

  function fans(s) {
    if (!s.fans || !s.fans.length) return '<p class="ff-empty">' + esc(t('tile.noFans')) + '</p>';
    return '<div class="ff-fans">' + s.fans.map(function (f) {
      var by = f.by ? F.sourceName(f.by, s) : '';
      var pct = f.pct == null ? 0 : f.pct;
      return '<div class="ff-fan' + (f.stalled ? ' ff-fan--stalled' : '') + '">' + F.rotor(f.rpm || 0) +
        '<div class="ff-fan-main"><div class="ff-fan-name"><span>' + esc(F.fanName(f)) + '</span>' +
        (f.stalled ? '<span class="ff-tag ff-tag--hot">' + esc(t('stalled')) + '</span>' : (by ? '<span class="ff-tag" title="' + esc(t('drivenBy', { src: by })) + '">' + esc(by) + '</span>' : '')) + '</div>' +
        '<div class="ff-bar"><i style="width:' + pct + '%"></i></div></div>' +
        '<div class="ff-fan-num"><b>' + (f.rpm == null ? '–' : f.rpm) + '</b><span>' + esc(t('rpm')) + '</span><em>' + (f.pct == null ? '' : Math.round(f.pct) + ' %') + '</em></div>' +
        '</div>';
    }).join('') + '</div>';
  }

  function others(s) {
    var list = s.otherFans || [];
    if (!list.length) return '';
    return '<div class="ff-fans ff-fans--others">' + list.map(function (o) {
      var on = o.on != null ? o.on : o.rpm > 0;
      return '<div class="ff-fan ff-fan--other' + (on ? '' : ' ff-fan--off') + '">' + F.rotor(o.rpm || 0) +
        '<div class="ff-fan-main"><div class="ff-fan-name"><span>' + esc(F.fanName({ id: o.tach })) + '</span>' +
        '<span class="ff-tag" title="' + esc(t('other.tip')) + '">' + esc(t('other.tag')) + '</span></div>' +
        '<div class="ff-fan-state">' + esc(F.otherStatus(o)) + '</div></div>' +
        '<div class="ff-fan-num"><b>' + (o.rpm == null ? '–' : o.rpm) + '</b><span>' + esc(t('rpm')) + '</span></div>' +
        '</div>';
    }).join('') + '</div>';
  }

  function render(s) {
    var b = banner(s);
    var mode = s.mode || 'stopped';
    if (sub) sub.innerHTML = '<span class="ff-pill ff-pill--' + esc(mode) + '">' + esc(t('mode.' + mode)) + '</span>' +
      (mode === 'auto' ? '<span class="ff-sub-text">' + esc(t('preset.' + (s.preset || 'balanced'))) + '</span>' : '');
    box.innerHTML = (b ? '<div class="ff-banner ff-banner--' + b[0] + '">' + esc(b[1]) + '</div>' : '') + temps(s) + fans(s) + others(s);
  }

  function load() {
    fetch(F.base + '/include/status.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(render)
      .catch(function () { render({ mode: 'stale' }); });
  }
  function schedule() {
    clearInterval(timer);
    if (!document.hidden) { load(); timer = setInterval(load, 3000); }
  }
  document.addEventListener('visibilitychange', schedule);
  schedule();
})();
