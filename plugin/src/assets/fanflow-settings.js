/* FanFlow - settings page: what the detection found, general settings, and for each fan its
   mode and curves (drag the points). Saved with include/save.php; live readings every 3 s. */
(function () {
  'use strict';
  var F = window.FanFlow, t = F.t, esc = F.esc;
  var root = document.getElementById('fanflow-settings');
  if (!root) return;
  var csrf = root.getAttribute('data-csrf') || window.csrf_token || '';

  var data = null;      // status.php?full=1
  var live = null;      // latest status.php
  var cfg = null;       // settings being edited
  var saved = '';       // settings as saved, to know when something changed
  var sel = {};         // fan id -> source shown in its curve editor
  var drawn = {};       // fan id -> temperature its curve editor shows
  var dragging = null;
  var lastMode = null;
  var message = null;

  var X0 = 20, X1 = 100;                       // °C shown
  var W = 560, H = 210, PL = 36, PR = 12, PT = 10, PB = 26;

  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function dirty() { return JSON.stringify(cfg) !== saved; }
  function kind(src) { return src.indexOf('gpu') === 0 ? 'gpu' : src; }
  function presetCurve(src, preset) {
    var p = data.presets[preset] || data.presets.balanced;
    return clone(p[kind(src)] || p.cpu);
  }
  function temps() { return (live && live.temps) || data.temps || {}; }
  function gpus() { return (live && live.gpus) || data.gpus || []; }

  // Sources this machine has.
  function sources() {
    var hw = data.hardware || {}, s = hw.sources || {};
    var list = [{ id: 'cpu', label: t('src.cpu') }];
    var cards = gpus().length ? gpus() : (s.gpus || []);
    if (cards.length) {
      list.push({ id: 'gpu', label: cards.length > 1 ? t('src.gpus') : F.gpuName(cards[0]) });
      if (cards.length > 1) cards.forEach(function (g) { list.push({ id: g.id, label: F.gpuName(g) }); });
    }
    var d = s.disks || {};
    if (d.hdd) list.push({ id: 'hdd', label: t('src.hdd') });
    if (d.ssd) list.push({ id: 'ssd', label: t('src.ssd') });
    return list;
  }
  function sourceLabel(id) {
    var l = sources().filter(function (s) { return s.id === id; })[0];
    return l ? l.label : F.sourceName(id, live || data);
  }

  // --- rendering ------------------------------------------------------------------------------

  function bannerHtml(s) {
    var m = s.mode, b = null;
    if (m === 'detecting') b = ['info', t('banner.detecting', { done: (s.detect || {}).done || 0, total: (s.detect || {}).total || '…' })];
    else if (m === 'critical' && s.reason) b = ['hot', t('banner.critical', { src: F.sourceName(s.reason.source, s), temp: Math.round(s.reason.temp), limit: s.reason.limit })];
    else if (m === 'failsafe') b = ['hot', t('banner.failsafe')];
    else if (m === 'readonly' && s.conflicts && s.conflicts.length) b = ['warm', t('banner.readonly', { what: F.conflictText(s) })];
    else if (m === 'bios') b = ['info', t('banner.bios')];
    else if (m === 'stopped' || m === 'stale') b = ['warm', t('banner.stopped')];
    var out = b ? '<div class="ff-banner ff-banner--' + b[0] + '">' + esc(b[1]) + '</div>' : '';
    var ch = s.changes || {};
    if (ch.missing && ch.missing.length) out += '<div class="ff-banner ff-banner--warm">' + esc(t('banner.missing', { n: ch.missing.length, list: ch.missing.join(', ') })) + '</div>';
    return out;
  }

  function hardwareHtml() {
    var hw = data.hardware;
    if (!hw) return '<section class="ff-card"><h2>' + esc(t('set.hw')) + '</h2><p class="ff-muted">' + esc(t('set.loading')) + '</p></section>';
    var s = hw.sources || {};
    var fans = Object.keys(hw.fans || {});
    var chips = Object.keys(hw.chips || {}).map(function (id) { var c = hw.chips[id]; return c.outputs ? c.name + ' (' + c.outputs + ')' : null; }).filter(Boolean);
    var cpu = (s.cpu || []).map(function (c) { return c.driver + ' ' + c.label; }).join(', ') || t('set.none');
    var g = (s.gpus || []).map(F.gpuName).join(', ') || t('set.none');
    var d = s.disks || {};
    var date = hw.detectedAt ? new Date(hw.detectedAt).toLocaleString(F.lang) : '';
    var rows = [
      [t('set.board'), [hw.board && hw.board.vendor, hw.board && hw.board.name].filter(Boolean).join(' ') || '–'],
      [t('set.chips'), chips.join(', ') || t('set.none')],
      [t('set.cpuSensor'), cpu],
      [t('set.gpus'), g],
      [t('set.disks'), t('set.disksSummary', { h: d.hdd || 0, s: d.ssd || 0 })],
      [t('set.fans'), t('set.fansSummary', { a: fans.length, b: (hw.otherFans || []).length, c: (hw.emptyOutputs || []).length })]
    ];
    var notes = [];
    if ((s.gpus || []).length) notes.push(['info', t('set.gpuOwnFans')]);
    if (hw.acpiConflict) notes.push(['warm', t('set.acpi')]);
    else if (!fans.length) notes.push(['warm', t('set.noOutputs')]);
    if (fans.some(function (id) { return hw.fans[id].labelProbable; })) notes.push(['info', t('set.probable')]);
    if (!fans.length && hw.drivers && hw.drivers.tried && hw.drivers.tried.length) notes.push(['info', t('set.driversTried', { list: hw.drivers.tried.join(', ') })]);
    return '<section class="ff-card"><div class="ff-card-head"><h2>' + esc(t('set.hw')) + '</h2>' +
      '<span class="ff-muted">' + esc(date ? t('set.detectedAt', { d: date }) : '') + '</span>' +
      '<button type="button" class="ff-btn" data-act="detect">' + esc(t('set.redetect')) + '</button></div>' +
      '<dl class="ff-facts">' + rows.map(function (r) { return '<dt>' + esc(r[0]) + '</dt><dd>' + esc(r[1]) + '</dd>'; }).join('') + '</dl>' +
      notes.map(function (n) { return '<p class="ff-note ff-note--' + n[0] + '">' + esc(n[1]) + '</p>'; }).join('') +
      '</section>';
  }

  function num(key, value, min, max, unit, attrs) {
    return '<span class="ff-num"><input type="number" min="' + min + '" max="' + max + '" value="' + esc(value) + '" ' + attrs + ' data-k="' + key + '"><span>' + esc(unit) + '</span></span>';
  }

  function generalHtml() {
    var presets = ['silent', 'balanced', 'performance'];
    var crit = ['cpu', 'gpu', 'hdd', 'ssd'];
    return '<section class="ff-card"><h2>' + esc(t('set.general')) + '</h2>' +
      '<div class="ff-form">' +
      '<label class="ff-row"><span class="ff-label">' + esc(t('set.enabled')) + '<small>' + esc(t('set.enabledHint')) + '</small></span>' +
      '<span class="ff-switch"><input type="checkbox" data-k="enabled"' + (cfg.enabled ? ' checked' : '') + '><i></i></span></label>' +
      '<div class="ff-row"><span class="ff-label">' + esc(t('set.preset')) + '<small>' + esc(t('set.presetHint')) + '</small></span>' +
      '<span class="ff-seg">' + presets.map(function (p) {
        return '<button type="button" data-preset="' + p + '" class="' + (cfg.preset === p ? 'on' : '') + '">' + esc(t('preset.' + p)) + '</button>';
      }).join('') + (cfg.preset === 'custom' ? '<button type="button" class="on" disabled>' + esc(t('preset.custom')) + '</button>' : '') + '</span></div>' +
      '<div class="ff-row"><span class="ff-label">' + esc(t('set.interval')) + '</span>' + num('interval', cfg.interval, 2, 30, t('set.seconds'), '') + '</div>' +
      '<div class="ff-row"><span class="ff-label">' + esc(t('set.hysteresis')) + '</span>' + num('hysteresis', cfg.hysteresis, 0, 10, t('set.degrees'), '') + '</div>' +
      '<div class="ff-row"><span class="ff-label">' + esc(t('set.rampUp')) + '</span>' + num('rampUp', cfg.rampUp, 1, 100, t('set.pct'), '') + '</div>' +
      '<div class="ff-row"><span class="ff-label">' + esc(t('set.rampDown')) + '</span>' + num('rampDown', cfg.rampDown, 1, 100, t('set.pct'), '') + '</div>' +
      '<div class="ff-row"><span class="ff-label">' + esc(t('set.critical')) + '</span><span class="ff-crit">' + crit.map(function (c) {
        return '<label><span>' + esc(t('src.' + c)) + '</span>' + num('critical.' + c, cfg.critical[c], 30, 110, '°C', '') + '</label>';
      }).join('') + '</span></div>' +
      '<label class="ff-row"><span class="ff-label">' + esc(t('set.notify')) + '</span>' +
      '<span class="ff-switch"><input type="checkbox" data-k="notify"' + (cfg.notify ? ' checked' : '') + '><i></i></span></label>' +
      '</div></section>';
  }

  function liveFan(id) {
    var f = ((live || data).fans || []).filter(function (x) { return x.id === id; })[0] || {};
    return f;
  }
  function liveText(f) {
    return (f.rpm == null ? '–' : f.rpm) + ' ' + t('rpm') + (f.pct == null ? '' : ' · ' + Math.round(f.pct) + ' %');
  }

  function fanHtml(id) {
    var f = cfg.fans[id], hw = data.hardware.fans[id] || {}, lf = liveFan(id);
    var srcs = sources();
    var active = Object.keys(f.curves);
    if (!sel[id] || active.indexOf(sel[id]) < 0) sel[id] = active[0] || 'cpu';
    var cal = hw.calibration || {};
    var calText = cal.aborted ? t('set.calibAborted') : (cal.maxRpm ? t('set.calib', { max: cal.maxRpm, min: cal.minPct }) : '');
    var title = f.name && f.name !== id ? f.name : F.fanName({ id: id });
    var modes = [['auto', t('set.modeAuto')], ['fixed', t('set.modeFixed')], ['bios', t('set.modeBios')]];
    return '<section class="ff-card ff-fancard" data-fan="' + esc(id) + '">' +
      '<div class="ff-card-head">' + F.rotor(lf.rpm || 0, 'ff-rotor--lg') + '<h2>' + esc(title) + '</h2>' +
      '<span class="ff-live" data-live="' + esc(id) + '">' + esc(liveText(lf)) + '</span></div>' +
      '<p class="ff-muted ff-fanmeta">' + esc(t('set.output', { o: (hw.output || '') + ' · ' + (hw.chip || '').split(':')[0] })) + (calText ? ' · ' + esc(calText) : '') + '</p>' +
      '<div class="ff-form">' +
      '<div class="ff-row"><span class="ff-label">' + esc(t('set.name')) + '</span><input type="text" maxlength="40" data-k="name" value="' + esc(f.name === id ? '' : f.name) + '" placeholder="' + esc(F.fanName({ id: id })) + '"></div>' +
      '<div class="ff-row"><span class="ff-label">' + esc(t('set.role')) + '</span><select data-k="role"><option value="cpu"' + (f.role === 'cpu' ? ' selected' : '') + '>' + esc(t('role.cpu')) + '</option><option value="case"' + (f.role !== 'cpu' ? ' selected' : '') + '>' + esc(t('role.case')) + '</option></select></div>' +
      '<div class="ff-row"><span class="ff-label">' + esc(t('set.mode')) + '</span><span class="ff-seg">' + modes.map(function (m) {
        return '<button type="button" data-mode="' + m[0] + '" class="' + (f.mode === m[0] ? 'on' : '') + '">' + esc(m[1]) + '</button>';
      }).join('') + '</span></div>' +
      (f.mode === 'fixed' ? '<div class="ff-row"><span class="ff-label">' + esc(t('set.fixed')) + '</span><span class="ff-range"><input type="range" min="0" max="100" step="5" data-k="fixed" value="' + f.fixed + '"><b>' + f.fixed + ' %</b></span></div>' : '') +
      (f.mode !== 'bios' ? '<div class="ff-row"><span class="ff-label">' + esc(t('set.min')) + '</span>' + num('min', f.min, 0, 100, '%', '') +
        '<label class="ff-check"><input type="checkbox" data-k="allowStop"' + (f.allowStop ? ' checked' : '') + '> ' + esc(t('set.allowStop')) + '</label></div>' : '') +
      '</div>' +
      (f.mode === 'auto' ? '<div class="ff-curves"><div class="ff-row ff-row--top"><span class="ff-label">' + esc(t('set.curves')) + '</span><span class="ff-chips">' +
        srcs.map(function (s) {
          var on = active.indexOf(s.id) >= 0;
          return '<button type="button" data-src="' + esc(s.id) + '" class="ff-chip' + (on ? ' on' : '') + (sel[id] === s.id ? ' sel' : '') + '">' + esc(s.label) + '</button>';
        }).join('') + '</span></div>' +
        '<div class="ff-editor" data-editor="' + esc(id) + '">' + curveSvg(id) + '</div>' +
        '<p class="ff-muted ff-help">' + esc(t('set.curveHelp')) + '</p></div>' : '') +
      '</section>';
  }

  function sx(temp) { return PL + (temp - X0) / (X1 - X0) * (W - PL - PR); }
  function sy(pct) { return PT + (1 - pct / 100) * (H - PT - PB); }

  function curveSvg(id) {
    var f = cfg.fans[id], src = sel[id], pts = f.curves[src] || [];
    var g = '';
    for (var x = X0; x <= X1; x += 10) g += '<line class="ff-grid" x1="' + sx(x) + '" x2="' + sx(x) + '" y1="' + PT + '" y2="' + (H - PB) + '"/><text class="ff-axis" x="' + sx(x) + '" y="' + (H - 8) + '" text-anchor="middle">' + x + '°</text>';
    for (var y = 0; y <= 100; y += 25) g += '<line class="ff-grid" x1="' + PL + '" x2="' + (W - PR) + '" y1="' + sy(y) + '" y2="' + sy(y) + '"/><text class="ff-axis" x="' + (PL - 6) + '" y="' + (sy(y) + 4) + '" text-anchor="end">' + y + '%</text>';
    if (f.min > 0) g += '<rect class="ff-floor" x="' + PL + '" y="' + sy(f.min) + '" width="' + (W - PL - PR) + '" height="' + (sy(0) - sy(f.min)) + '"/>';
    var line = '';
    if (pts.length) {
      var all = [[X0, pts[0][1]]].concat(pts, [[X1, pts[pts.length - 1][1]]]);
      line = all.map(function (p) { return sx(Math.max(X0, Math.min(X1, p[0]))).toFixed(1) + ',' + sy(p[1]).toFixed(1); }).join(' ');
      g += '<polygon class="ff-curve-fill" points="' + sx(X0) + ',' + sy(0) + ' ' + line + ' ' + sx(X1) + ',' + sy(0) + '"/><polyline class="ff-curve" points="' + line + '"/>';
    }
    var now = temps()[src];
    if (now != null && pts.length) {
      var v = Math.max(curveValue(pts, now), f.min);
      var cx = sx(Math.max(X0, Math.min(X1, now)));
      g += '<line class="ff-now" x1="' + cx + '" x2="' + cx + '" y1="' + PT + '" y2="' + (H - PB) + '"/>' +
        '<circle class="ff-now-dot" cx="' + cx + '" cy="' + sy(v) + '" r="4"/>' +
        '<text class="ff-now-text" x="' + Math.min(cx + 6, W - PR - 120) + '" y="' + (PT + 12) + '">' + esc(t('set.now', { t: Math.round(now), p: Math.round(v) })) + '</text>';
    }
    pts.forEach(function (p, i) {
      g += '<g class="ff-pt" data-i="' + i + '"><circle class="ff-pt-hit" cx="' + sx(p[0]) + '" cy="' + sy(p[1]) + '" r="13"/><circle class="ff-pt-dot" cx="' + sx(p[0]) + '" cy="' + sy(p[1]) + '" r="6"/>' +
        '<title>' + p[0] + ' °C → ' + p[1] + ' %</title></g>';
    });
    return '<svg viewBox="0 0 ' + W + ' ' + H + '" class="ff-svg" role="img" aria-label="' + esc(sourceLabel(src)) + '">' + g + '</svg>';
  }

  function curveValue(points, temp) {
    if (temp <= points[0][0]) return points[0][1];
    for (var i = 1; i < points.length; i++) {
      if (temp <= points[i][0]) {
        var a = points[i - 1], b = points[i];
        return b[0] === a[0] ? b[1] : a[1] + (b[1] - a[1]) * (temp - a[0]) / (b[0] - a[0]);
      }
    }
    return points[points.length - 1][1];
  }

  function actionsHtml() {
    var d = dirty();
    return '<div class="ff-actions' + (d ? ' ff-actions--dirty' : '') + '">' +
      '<span class="ff-msg' + (message ? ' ff-msg--' + message[0] : '') + '">' + esc(message ? message[1] : (d ? t('set.unsaved') : '')) + '</span>' +
      '<button type="button" class="ff-btn" data-act="revert"' + (d ? '' : ' disabled') + '>' + esc(t('set.revert')) + '</button>' +
      '<button type="button" class="ff-btn ff-btn--primary" data-act="save"' + (d ? '' : ' disabled') + '>' + esc(t('set.save')) + '</button></div>';
  }

  function otherHtml() {
    var hw = data.hardware || {};
    var others = (live || data).otherFans || [];
    if (!others.length && !(hw.emptyOutputs || []).length) return '';
    return '<section class="ff-card ff-card--quiet">' +
      (others.length ? '<h3>' + esc(t('set.notDrivable')) + '</h3><p>' + others.map(function (o) { return esc(F.fanName({ id: o.tach }) + ' (' + o.tach.split('/').pop() + ') · ' + (o.rpm == null ? '–' : o.rpm) + ' ' + t('rpm') + ' · ' + F.otherStatus(o)); }).join('<br>') + '</p>' : '') +
      ((hw.emptyOutputs || []).length ? '<h3>' + esc(t('set.empty')) + '</h3><p class="ff-muted">' + esc(hw.emptyOutputs.map(function (o) { return o.split('/').pop(); }).join(', ')) + '</p>' : '') +
      '</section>';
  }

  function render() {
    if (!data) { root.innerHTML = '<p class="ff-muted">' + esc(t('set.loading')) + '</p>'; return; }
    var fanIds = Object.keys(cfg.fans).filter(function (id) { return data.hardware && data.hardware.fans && data.hardware.fans[id]; });
    root.innerHTML = '<div data-banner>' + bannerHtml(live || data) + '</div>' + hardwareHtml() +
      (fanIds.length ? generalHtml() : '') + fanIds.map(fanHtml).join('') + otherHtml() + (fanIds.length ? '<div data-actions>' + actionsHtml() + '</div>' : '');
  }
  function renderActions() {
    var box = root.querySelector('[data-actions]');
    if (box) box.innerHTML = actionsHtml();
  }
  function renderFan(id) {
    var el = root.querySelector('.ff-fancard[data-fan="' + cssEsc(id) + '"]');
    if (!el) return;
    var tmp = document.createElement('div');
    tmp.innerHTML = fanHtml(id);
    el.replaceWith(tmp.firstChild);
  }
  function renderEditor(id) {
    var el = root.querySelector('[data-editor="' + cssEsc(id) + '"]');
    if (el) el.innerHTML = curveSvg(id);
  }
  function cssEsc(s) { return window.CSS && CSS.escape ? CSS.escape(s) : s.replace(/["\\]/g, '\\$&'); }
  function changed(id) { message = null; if (id) renderFan(id); renderActions(); }

  // --- editing --------------------------------------------------------------------------------

  function fanOf(el) { var c = el.closest('[data-fan]'); return c ? c.getAttribute('data-fan') : null; }

  function setValue(el) {
    var k = el.getAttribute('data-k'), id = fanOf(el);
    var v = el.type === 'checkbox' ? el.checked : (el.type === 'number' || el.type === 'range' ? Number(el.value) : el.value);
    if (id) {
      var f = cfg.fans[id];
      if (k === 'name') f.name = String(v).trim() || id;
      else f[k] = v;
      if (k === 'fixed') { var b = el.parentNode.querySelector('b'); if (b) b.textContent = v + ' %'; }
      if (k === 'min' || k === 'allowStop') renderEditor(id);
      message = null; renderActions();
      return;
    }
    if (k.indexOf('critical.') === 0) cfg.critical[k.slice(9)] = v; else cfg[k] = v;
    message = null; renderActions();
  }

  root.addEventListener('input', function (e) { if (e.target.hasAttribute('data-k') && e.target.type !== 'checkbox') setValue(e.target); });
  root.addEventListener('change', function (e) { if (e.target.hasAttribute('data-k')) setValue(e.target); });

  root.addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b || b.disabled) return;
    var id = fanOf(b);
    if (b.hasAttribute('data-preset')) {
      var p = b.getAttribute('data-preset');
      cfg.preset = p;
      Object.keys(cfg.fans).forEach(function (fid) {
        var f = cfg.fans[fid];
        Object.keys(f.curves).forEach(function (src) { f.curves[src] = presetCurve(src, p); });
      });
      message = null; render();
    } else if (b.hasAttribute('data-mode')) {
      cfg.fans[id].mode = b.getAttribute('data-mode'); changed(id);
    } else if (b.hasAttribute('data-src')) {
      var src = b.getAttribute('data-src'), f = cfg.fans[id];
      if (!f.curves[src]) { f.curves[src] = presetCurve(src, cfg.preset === 'custom' ? 'balanced' : cfg.preset); sel[id] = src; }
      else if (sel[id] !== src) sel[id] = src;
      else if (Object.keys(f.curves).length > 1) { delete f.curves[src]; sel[id] = Object.keys(f.curves)[0]; }
      else { message = ['warm', t('set.needSource')]; renderActions(); return; }
      changed(id);
    } else if (b.getAttribute('data-act') === 'save') save();
    else if (b.getAttribute('data-act') === 'revert') { cfg = JSON.parse(saved); message = null; render(); }
    else if (b.getAttribute('data-act') === 'detect') detect();
  });

  // Curve points: drag, double-click to add, double-click a point to remove.
  function svgPoint(svg, e) {
    var p = svg.createSVGPoint(); p.x = e.clientX; p.y = e.clientY;
    var q = p.matrixTransform(svg.getScreenCTM().inverse());
    var temp = Math.round(X0 + (q.x - PL) / (W - PL - PR) * (X1 - X0));
    var pct = Math.round((1 - (q.y - PT) / (H - PT - PB)) * 100);
    return [Math.max(0, Math.min(110, temp)), Math.max(0, Math.min(100, pct))];
  }
  function markCustom() { if (cfg.preset !== 'custom') { cfg.preset = 'custom'; var seg = root.querySelector('[data-preset]'); if (seg) { var box = seg.parentNode; box.querySelectorAll('.on').forEach(function (x) { x.classList.remove('on'); }); } } }

  root.addEventListener('pointerdown', function (e) {
    var g = e.target.closest('.ff-pt');
    if (!g) return;
    var svg = g.ownerSVGElement, id = fanOf(svg);
    dragging = { id: id, i: Number(g.getAttribute('data-i')), svg: svg };
    svg.setPointerCapture(e.pointerId);
    e.preventDefault();
  });
  root.addEventListener('pointermove', function (e) {
    if (!dragging) return;
    var pts = cfg.fans[dragging.id].curves[sel[dragging.id]], i = dragging.i;
    var p = svgPoint(dragging.svg, e);
    var lo = i > 0 ? pts[i - 1][0] + 1 : 0, hi = i < pts.length - 1 ? pts[i + 1][0] - 1 : 110;
    pts[i] = [Math.max(lo, Math.min(hi, p[0])), p[1]];
    markCustom();
    var svg = dragging.svg, wrap = svg.parentNode;
    wrap.innerHTML = curveSvg(dragging.id);
    dragging.svg = wrap.querySelector('svg');
    dragging.svg.setPointerCapture(e.pointerId);
  });
  root.addEventListener('pointerup', function () { if (dragging) { dragging = null; message = null; renderActions(); } });
  root.addEventListener('dblclick', function (e) {
    var svg = e.target.closest('.ff-svg');
    if (!svg) return;
    var id = fanOf(svg), pts = cfg.fans[id].curves[sel[id]];
    var g = e.target.closest('.ff-pt');
    if (g) { if (pts.length > 2) pts.splice(Number(g.getAttribute('data-i')), 1); }
    else {
      var p = svgPoint(svg, e);
      if (pts.some(function (q) { return q[0] === p[0]; }) || pts.length >= 12) return;
      pts.push(p); pts.sort(function (a, b) { return a[0] - b[0]; });
    }
    markCustom(); renderEditor(id); message = null; renderActions();
  });

  // --- server ---------------------------------------------------------------------------------

  function post(url, fields) {
    var body = new FormData();
    body.append('csrf_token', csrf);
    Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
    return fetch(F.base + '/include/' + url, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'HTTP ' + r.status }; });
    });
  }
  function save() {
    post('save.php', { config: JSON.stringify(cfg) }).then(function (r) {
      if (!r.ok) { message = ['hot', t('set.saveError', { e: r.error || '?' })]; renderActions(); return; }
      cfg = r.config; saved = JSON.stringify(cfg); message = ['ok', t('set.saved')]; render();
    }).catch(function (e) { message = ['hot', t('set.saveError', { e: e.message })]; renderActions(); });
  }
  function detect() {
    if (!window.confirm(t('set.redetectConfirm'))) return;
    post('detect.php', { action: 'detect' }).then(poll);
  }

  function loadFull() {
    return fetch(F.base + '/include/status.php?full=1', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
      data = d; live = d; lastMode = d.mode;
      cfg = clone(d.config); saved = JSON.stringify(cfg);
      render();
    });
  }
  function poll() {
    fetch(F.base + '/include/status.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (s) {
      var was = lastMode;
      live = s; lastMode = s.mode;
      // Detection over: the hardware and the default settings may have changed.
      if (was === 'detecting' && s.mode !== 'detecting' && !dirty()) { loadFull(); return; }
      var bn = root.querySelector('[data-banner]');
      if (bn) bn.innerHTML = bannerHtml(s);
      (s.fans || []).forEach(function (f) {
        var el = root.querySelector('[data-live="' + cssEsc(f.id) + '"]');
        if (el) el.textContent = liveText(f);
        var card = root.querySelector('.ff-fancard[data-fan="' + cssEsc(f.id) + '"] .ff-card-head .ff-rotor');
        if (card) { var tmp = document.createElement('div'); tmp.innerHTML = F.rotor(f.rpm || 0, 'ff-rotor--lg'); card.replaceWith(tmp.firstChild); }
        // Redraw a curve only when the temperature it shows moved (keeps hover and double-clicks).
        var now = cfg.fans[f.id] ? (s.temps || {})[sel[f.id]] : undefined;
        if (!dragging && now !== undefined && drawn[f.id] !== now) { drawn[f.id] = now; renderEditor(f.id); }
      });
    }).catch(function () {});
  }

  render();
  loadFull().catch(function () { root.innerHTML = '<div class="ff-banner ff-banner--warm">' + esc(t('banner.stopped')) + '</div>'; });
  setInterval(function () { if (!document.hidden && data) poll(); }, 3000);
})();
