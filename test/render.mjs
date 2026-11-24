// Screenshots of the FanFlow Dashboard tile and settings page inside Unraid's real stylesheets,
// with Unraid's own themes and with Glacier-Theme, in French and English, wide and phone.
//
//   php test/fixture.php                       (status.php answers from the simulator)
//   node test/render.mjs /path/to/unraid/webgui [/path/to/Glacier-Theme]
//   SERVE=1 FF_PORT=8091 ...                   only serve the pages
//
// The page frame (header, menu, other Dashboard tiles) is Glacier-Theme's mock of Unraid 7.3.4
// (test/layout.html and test/pages/ of that repository). Needs Playwright with Chromium.
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const here = path.dirname(fileURLToPath(import.meta.url));
const webgui = path.resolve(process.argv[2] || process.env.UNRAID_WEBGUI || '/root/dev/unraid-webgui');
const glacier = path.resolve(process.argv[3] || process.env.GLACIER || '/root/dev/Glacier-Theme');
const dynamix = path.join(webgui, 'emhttp/plugins/dynamix');
const require = createRequire(path.join(glacier, 'package.json'));
const { chromium } = require('playwright');
const src = path.resolve(here, '../plugin/src');
const fixture = path.join(here, 'fixture');
const shots = path.join(here, 'shots');
fs.mkdirSync(shots, { recursive: true });

// The .page files rendered by PHP, as Unraid would.
function renderPage(file, column) {
  const code = `$docroot = '/nonexistent'; $var = ['csrf_token' => 'TEST']; $mytiles = [];
    $p = file_get_contents('${path.join(src, file)}'); $body = explode("\\n---\\n", $p, 2)[1];
    ob_start(); eval('?>' . $body); $out = ob_get_clean();
    echo ${column ? `$mytiles['fanflow']['${column}']` : '$out'};`;
  return execFileSync('php', ['-r', code], { encoding: 'utf8' });
}
const tile = renderPage('FanFlowDash.page', 'column2');
const settings = renderPage('FanFlow.page');

const layout = fs.readFileSync(path.join(glacier, 'test/layout.html'), 'utf8');
const dashboard = fs.readFileSync(path.join(glacier, 'test/pages/dashboard.html'), 'utf8').split('\n---\n')[1];
// The FanFlow tile goes at the end of the second column.
const tile2 = dashboard.indexOf("id='db_box2'");
const end = dashboard.indexOf('</table>', tile2);
const dashWithTile = dashboard.slice(0, end) + tile + dashboard.slice(end);
const settingsPage = `<div id="displaybox"><div class="content"><div class="title"><span class="left"><i class="icon-fan f32"></i> FanFlow</span></div>${settings}</div></div>`;

function glacierAssets() {
  const pluginSrc = path.join(glacier, 'plugin/src');
  const list = (dir, ext) => fs.readdirSync(path.join(pluginSrc, dir)).filter((f) => f.startsWith('gl-') && f.endsWith(ext)).sort();
  return '<script>window.glacierTemp = {"hot":45,"max":55}; window.glacierUpdates = {"docker":0,"plugins":0}; window.glacierVersion = "test";</script>'
    + '<link rel="stylesheet" href="/plugins/Glacier-Theme/styles/glacier.css">'
    + list('styles', '.css').map((f) => `<link rel="stylesheet" href="/plugins/Glacier-Theme/styles/${f}">`).join('')
    + ['gl-core.js', ...list('scripts', '.js').filter((f) => f !== 'gl-core.js')].map((f) => `<script defer src="/plugins/Glacier-Theme/scripts/${f}"></script>`).join('')
    + '<script defer src="/plugins/Glacier-Theme/scripts/glacier.js"></script>';
}

// mode: white | black | glacier | glacier-dark
function page(name, mode, lang) {
  const isGlacier = mode.startsWith('glacier');
  const dark = mode === 'black' || mode === 'glacier-dark';
  const pageName = name === 'dashboard' ? 'Dashboard' : 'Settings';
  return layout
    .replace('lang="fr"', `lang="${lang}"`)
    .replaceAll('{{CLASSES}}', isGlacier ? `glacier gl-hero-on gl-page-${pageName}${dark ? ' gl-dark' : ''}` : '')
    .replaceAll('Theme--white', dark ? 'Theme--black' : 'Theme--white')
    .replaceAll('themes/white.css', dark ? 'themes/black.css' : 'themes/white.css')
    .replaceAll('{{GLACIER}}', isGlacier ? glacierAssets() : '')
    .replaceAll('{{SHEET}}', name === 'dashboard' ? '<link rel="stylesheet" href="/webGui/sheets/DashStats.css">' : '')
    .replace(`<div class="nav-item"><a href="/${pageName}">`, `<div class="nav-item active"><a href="/${pageName}">`)
    .replaceAll('{{CONTENT}}', name === 'dashboard' ? dashWithTile : settingsPage);
}

let lastSaved = null;
const types = { '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2', '.woff': 'font/woff', '.png': 'image/png', '.svg': 'image/svg+xml', '.json': 'application/json' };
const server = http.createServer((req, res) => {
  const url = new URL(req.url, 'http://x');
  if (url.pathname.startsWith('/page/')) {
    const [, , name, mode, lang] = url.pathname.split('/');
    res.writeHead(200, { 'content-type': 'text/html' });
    res.end(page(name, mode, lang || 'fr'));
    return;
  }
  // Saving: answer as save.php would, after the same JSON parse.
  if (req.method === 'POST' && url.pathname === '/plugins/fanflow/include/save.php') {
    let body = '';
    req.on('data', (c) => { body += c; });
    req.on('end', () => {
      const m = body.match(/name="config"\r\n\r\n([\s\S]*?)\r\n--/);
      const config = JSON.parse(m ? m[1] : '{}');
      lastSaved = config;
      res.writeHead(200, { 'content-type': 'application/json' });
      res.end(JSON.stringify({ ok: true, config }));
    });
    return;
  }
  let file;
  if (url.pathname === '/plugins/fanflow/include/status.php') file = path.join(fixture, url.searchParams.get('full') ? 'status-full.json' : 'status.json');
  else if (url.pathname.startsWith('/plugins/fanflow/')) file = path.join(src, url.pathname.slice('/plugins/fanflow/'.length));
  else if (url.pathname.startsWith('/webGui/')) file = path.join(dynamix, url.pathname.slice('/webGui/'.length));
  else if (url.pathname.startsWith('/plugins/Glacier-Theme/')) file = path.join(glacier, 'plugin/src', url.pathname.slice('/plugins/Glacier-Theme/'.length));
  if (!file || !fs.existsSync(file)) { res.writeHead(404); res.end(); return; }
  res.writeHead(200, { 'content-type': types[path.extname(file)] || 'application/octet-stream' });
  fs.createReadStream(file).pipe(res);
});
await new Promise((r) => server.listen(Number(process.env.FF_PORT) || 0, '127.0.0.1', r));
const base = `http://127.0.0.1:${server.address().port}`;
if (process.env.SERVE) { console.log(`${base}/page/<dashboard|settings>/<white|black|glacier|glacier-dark>/<fr|en>`); await new Promise(() => {}); }

const browser = await chromium.launch();
const jobs = [];
for (const mode of ['white', 'black', 'glacier', 'glacier-dark']) {
  jobs.push(['dashboard', mode, 'fr', 1440], ['settings', mode, 'fr', 1440]);
}
jobs.push(['dashboard', 'glacier', 'en', 1440], ['settings', 'glacier', 'en', 1440],
  ['dashboard', 'glacier', 'fr', 390], ['settings', 'glacier', 'fr', 390], ['dashboard', 'white', 'fr', 390]);
let problems = 0;
for (const [name, mode, lang, width] of jobs) {
  const ctx = await browser.newContext({ viewport: { width, height: 900 }, deviceScaleFactor: 1 });
  const tab = await ctx.newPage();
  const errors = [];
  tab.on('pageerror', (e) => errors.push(e.message));
  await tab.goto(`${base}/page/${name}/${mode}/${lang}`, { waitUntil: 'networkidle' });
  await tab.waitForSelector(name === 'dashboard' ? '#fanflow-tile .ff-fan' : '#fanflow-settings .ff-fancard');
  await tab.evaluate(() => document.fonts.ready);
  // The webGUI's footer is fixed to the window: in a full-page shot it would sit mid-page.
  await tab.addStyleTag({ content: '#footer { position: static !important; }' });
  const label = `${name}-${mode}-${lang}-${width < 600 ? 'phone' : 'desktop'}`;
  const out = path.join(shots, `${label}.png`);
  if (name === 'dashboard') await tab.locator('tbody[title="FanFlow"]').screenshot({ path: out });
  else await tab.screenshot({ path: out, fullPage: true });
  const overflow = await tab.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  if (overflow > 0 || errors.length) problems++;
  console.log(`${out}${overflow > 0 ? `  (horizontal overflow ${overflow}px)` : ''}${errors.length ? `  errors: ${errors.join(' | ')}` : ''}`);
  await ctx.close();
}
// Settings page: profile, dragging a point, adding one, saving.
{
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const tab = await ctx.newPage();
  const errors = [];
  tab.on('pageerror', (e) => errors.push(e.message));
  await tab.goto(`${base}/page/settings/glacier/en`, { waitUntil: 'networkidle' });
  await tab.waitForSelector('.ff-fancard');
  const check = (what, ok) => { if (!ok) { problems++; console.log(`FAIL  ${what}`); } else console.log(`ok    ${what}`); };
  check('Apply disabled before any change', await tab.locator('[data-act=save]').isDisabled());
  await tab.click('[data-preset=performance]');
  check('profile applied to the curves', await tab.evaluate(() => document.querySelector('.ff-editor .ff-pt').parentNode.querySelectorAll('.ff-pt').length) === 4);
  check('Apply enabled after a change', !(await tab.locator('[data-act=save]').isDisabled()));
  await tab.locator('.ff-editor').first().scrollIntoViewIfNeeded();
  const pt = tab.locator('.ff-editor').first().locator('.ff-pt-dot').nth(1);
  const box = await pt.boundingBox();
  await tab.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await tab.mouse.down();
  await tab.mouse.move(box.x + 30, box.y - 40, { steps: 5 });
  await tab.mouse.up();
  const svg = await tab.locator('.ff-editor').first().locator('svg').boundingBox();
  await tab.mouse.dblclick(svg.x + svg.width * 0.93, svg.y + svg.height * 0.5);
  const count = await tab.locator('.ff-editor').first().locator('.ff-pt').count();
  check(`point added by double-click (${count} points)`, count === 5);
  await tab.click('[data-act=save]');
  await tab.waitForSelector('.ff-msg--ok');
  const fan = lastSaved && Object.values(lastSaved.fans)[0];
  check('saved as custom profile with the moved point', lastSaved && lastSaved.preset === 'custom' && fan.curves.cpu.length === 5 && fan.curves.cpu[1][1] > 50);
  check('no script error', errors.length === 0);
  await ctx.close();
}
await browser.close();
server.close();
process.exit(problems ? 1 : 0);
