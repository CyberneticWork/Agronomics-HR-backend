/**
 * Sohan HR — Hikvision on-site bridge (Solar DS-K1T320 parity)
 *
 * Runs on an office PC that can reach the fingerprint device LAN IP.
 * Polls ISAPI AcsEvent (HTTP Digest) and POSTs punches to HR:
 *   POST /api/hikvision/punches/{token}
 *
 * Setup:
 *   1. npm install
 *   2. Time Card → Hikvision → Agent config → Download office .env
 *      Save it in THIS folder as .env (or hikvision-bridge.env)
 *   3. From THIS folder: npm start   (or start-bridge.bat / install-autostart.bat)
 */
const path = require('path');
const fs = require('fs');
const os = require('os');
const net = require('net');
const crypto = require('crypto');
const http = require('http');
const https = require('https');
const { URL } = require('url');

function cleanSecret(value) {
  return String(value || '')
    .replace(/^\uFEFF/, '')
    .replace(/\r/g, '')
    .trim()
    .replace(/^[\u201C\u201D"']+|[\u201C\u201D"']+$/g, '');
}

function decodeEnvBuffer(buf) {
  if (buf.length >= 2 && buf[0] === 0xff && buf[1] === 0xfe) {
    return buf.slice(2).toString('utf16le');
  }
  if (buf.includes(0) && buf.length > 8) {
    return buf.toString('utf16le').replace(/\u0000/g, '');
  }
  let s = buf.toString('utf8');
  if (s.charCodeAt(0) === 0xfeff) s = s.slice(1);
  return s;
}

function parseEnvText(text) {
  const out = {};
  for (const rawLine of String(text).split(/\r?\n/)) {
    const line = rawLine.replace(/^\uFEFF/, '').trim();
    if (!line || line.startsWith('#')) continue;
    const eq = line.indexOf('=');
    if (eq < 1) continue;
    const key = line.slice(0, eq).trim();
    let val = line.slice(eq + 1).trim();
    const quoted = val.match(/^(['"])([\s\S]*)\1$/);
    out[key] = quoted ? quoted[2] : val;
  }
  return out;
}

function findEnvPath() {
  const names = ['.env', '.env.txt', 'hikvision-bridge.env', '.env.txt.txt'];
  for (const name of names) {
    const p = path.join(__dirname, name);
    try {
      if (fs.existsSync(p) && fs.statSync(p).isFile()) return p;
    } catch {
      /* ignore */
    }
  }
  return null;
}

function envKey(name) {
  return String(name || '')
    .toUpperCase()
    .replace(/[^A-Z0-9]+/g, '_');
}

function pickParsed(parsed, ...names) {
  const map = {};
  for (const [k, v] of Object.entries(parsed || {})) map[envKey(k)] = v;
  for (const name of names) {
    const hit = map[envKey(name)];
    if (hit != null && String(hit).trim() !== '') return String(hit);
  }
  return '';
}

const envPath = findEnvPath();
const parsedEnv = envPath ? parseEnvText(decodeEnvBuffer(fs.readFileSync(envPath))) : {};
if (envPath) {
  for (const [key, value] of Object.entries(parsedEnv)) process.env[key] = value;
  console.log(`[bridge] Loaded ${envPath} (${Object.keys(parsedEnv).join(', ') || 'no keys'})`);
} else {
  console.error(`[bridge] No .env in ${__dirname}`);
  console.error('[bridge] Save Time Card → Agent config → Download office .env here as .env');
}

const DEVICE_IP = cleanSecret(pickParsed(parsedEnv, 'DEVICE_IP', 'IP') || process.env.DEVICE_IP);
const DEVICE_PORT = Number(pickParsed(parsedEnv, 'DEVICE_PORT') || process.env.DEVICE_PORT || 80);
const DEVICE_USER = cleanSecret(pickParsed(parsedEnv, 'DEVICE_USER', 'USER') || process.env.DEVICE_USER || 'admin') || 'admin';
const DEVICE_PASSWORD = cleanSecret(
  pickParsed(parsedEnv, 'DEVICE_PASSWORD', 'PASSWORD') || process.env.DEVICE_PASSWORD,
);
let CLOUD_PUNCHES_URL = cleanSecret(
  pickParsed(parsedEnv, 'CLOUD_PUNCHES_URL') ||
    process.env.CLOUD_PUNCHES_URL ||
    Object.values(parsedEnv).find((v) => /\/hikvision\/punches\//i.test(String(v))) ||
    '',
);
const CLOUD_BASE_URL = cleanSecret(
  pickParsed(parsedEnv, 'CLOUD_BASE_URL', 'PUBLIC_API_URL') || process.env.CLOUD_BASE_URL || process.env.PUBLIC_API_URL,
)
  .replace(/\/+$/, '')
  .replace(/(?:\/api\/hr)+$/i, '')
  .replace(/\/api$/i, '');
const SECRET = cleanSecret(process.env.HIKVISION_SECRET || process.env.HIKVISION_WEBHOOK_SECRET || '');
const POLL_SECONDS = Math.max(15, Number(process.env.POLL_SECONDS || 60));
const LOOKBACK_MINUTES = Math.max(5, Number(process.env.LOOKBACK_MINUTES || 180));
const STATE_FILE = path.join(__dirname, '.bridge-state.json');
const once = process.argv.includes('--once');
// Re-read the last N days day-by-day so punches missed while the PC/device/network was down still arrive.
const CATCHUP_DAYS = Math.max(0, Number(process.env.CATCHUP_DAYS ?? 7));
const CATCHUP_EVERY_HOURS = Math.max(1, Number(process.env.CATCHUP_EVERY_HOURS || 6));
// node bridge.js --backfill=2026-09-16  or  --backfill=2026-09-16:2026-09-18
const backfillArg = (process.argv.find((a) => a.startsWith('--backfill=')) || '').slice('--backfill='.length);
const reportArg = (process.argv.find((a) => a.startsWith('--report=')) || '').slice('--report='.length);
const LOCK_FILE = path.join(__dirname, '.bridge.lock');
const EXIT_ALREADY_RUNNING = 3;

const HR_APIS = {
  'spmhr.cyberneticde.site': 'https://apispmhr.cyberneticde.site',
  'apispmhr.cyberneticde.site': 'https://apispmhr.cyberneticde.site',
  'jcfood.cyberneticde.site': 'https://apijcfood.cyberneticde.site',
  'apijcfood.cyberneticde.site': 'https://apijcfood.cyberneticde.site',
  'urbanhr.cyberneticde.site': 'https://apiurbanhr.cyberneticde.site',
  'apiurbanhr.cyberneticde.site': 'https://apiurbanhr.cyberneticde.site',
  'sunfohr.cyberneticde.site': 'https://apisunfohr.cyberneticde.site',
  'apisunfohr.cyberneticde.site': 'https://apisunfohr.cyberneticde.site',
  'bdchrnew.cyberneticde.site': 'https://apibdchrnew.cyberneticde.site',
  'apibdchrnew.cyberneticde.site': 'https://apibdchrnew.cyberneticde.site',
  'hilburn.cyberneticde.site': 'https://apihilburn.cyberneticde.site',
  'apihilburn.cyberneticde.site': 'https://apihilburn.cyberneticde.site',
};

function localIpv4s() {
  const out = [];
  for (const [name, addrs] of Object.entries(os.networkInterfaces())) {
    for (const a of addrs || []) {
      const family = a.family === 4 || a.family === 'IPv4';
      if (family && !a.internal) out.push(`${a.address} (${name})`);
    }
  }
  return out;
}

function tcpReachable(host, port, ms = 5000) {
  return new Promise((resolve) => {
    const socket = net.connect({ host, port });
    const finish = (ok, err) => {
      socket.removeAllListeners();
      socket.destroy();
      resolve({ ok, err });
    };
    socket.setTimeout(ms);
    socket.once('connect', () => finish(true));
    socket.once('timeout', () => finish(false, `connect ETIMEDOUT ${host}:${port}`));
    socket.once('error', (e) => finish(false, e.message || String(e)));
  });
}

function sameLanHint(deviceIp, pcIps) {
  const dev = String(deviceIp || '').split('.').slice(0, 3).join('.');
  return pcIps.some((line) => line.startsWith(`${dev}.`));
}

function punchesToken(urlStr) {
  const m = String(urlStr || '').match(/\/hikvision\/punches\/([^/?#]+)/i);
  return m ? m[1] : '';
}

function usesIndexPhp(urlStr) {
  return /\/index\.php(\/|\?|$)/i.test(String(urlStr || ''));
}

function isCyberneticHost(urlStr) {
  try {
    return new URL(String(urlStr).includes('://') ? urlStr : `https://${urlStr}`)
      .hostname.toLowerCase()
      .endsWith('cyberneticde.site');
  } catch {
    return /cyberneticde\.site/i.test(String(urlStr || ''));
  }
}

function apiOrigin(urlStr) {
  let s = String(urlStr || '').trim().replace(/\/+$/, '');
  s = s.replace(/(?:\/api\/hr)+$/i, '').replace(/\/api$/i, '');
  try {
    const u = new URL(s.includes('://') ? s : `https://${s}`);
    const host = u.hostname.toLowerCase().replace(/^www\./, '');
    if (host === 'localhost' || host === '127.0.0.1') {
      return `${u.protocol}//${host}${u.port ? `:${u.port}` : ''}`;
    }
    return HR_APIS[host] || u.origin;
  } catch {
    return s;
  }
}

function canonicalPunchesUrl(base, punchesUrl) {
  const origin = apiOrigin(base);
  const token = punchesToken(punchesUrl);
  if (!origin || !token) return punchesUrl;
  const host = origin.replace(/\/index\.php$/i, '');
  if (isCyberneticHost(host) || usesIndexPhp(base) || usesIndexPhp(punchesUrl)) {
    return `${host}/index.php?__lr=/api/hikvision/punches/${token}`;
  }
  return `${origin}/api/hikvision/punches/${token}`;
}

function punchesUrlCandidates(punchesUrl) {
  const token = punchesToken(punchesUrl);
  const origin = apiOrigin(punchesUrl || CLOUD_BASE_URL);
  const host = String(origin || '').replace(/\/index\.php$/i, '');
  const list = [];
  if (token && host) {
    if (isCyberneticHost(host) || usesIndexPhp(punchesUrl) || usesIndexPhp(CLOUD_BASE_URL)) {
      list.push(`${host}/index.php?__lr=/api/hikvision/punches/${token}`);
      list.push(`${host}/index.php/api/hikvision/punches/${token}`);
    }
    list.push(`${host}/api/hikvision/punches/${token}`);
  }
  if (punchesUrl) list.push(punchesUrl);
  return [...new Set(list.filter(Boolean))];
}

function looksLikeHtml404(res) {
  const t = String(res?.text || '');
  return res.status === 404 && /<html/i.test(t);
}

async function refreshCloudPunchesUrl() {
  const token = punchesToken(CLOUD_PUNCHES_URL);
  if (!token) return CLOUD_PUNCHES_URL;
  const probeBase = apiOrigin(CLOUD_BASE_URL) || apiOrigin(CLOUD_PUNCHES_URL);
  if (!probeBase) return CLOUD_PUNCHES_URL;
  const bases = [probeBase, `${probeBase}/index.php`].filter((v, i, a) => a.indexOf(v) === i);
  for (const probe of bases) {
    try {
      const res = await rawRequest(`${probe}/api/hikvision/cloud-base`, { method: 'GET' });
      const live = apiOrigin(res.json?.public_api_url || res.json?.publicApiUrl || res.json?.data?.public_api_url);
      if (live) {
        CLOUD_PUNCHES_URL = canonicalPunchesUrl(usesIndexPhp(probe) ? `${live}/index.php` : live, CLOUD_PUNCHES_URL);
        return CLOUD_PUNCHES_URL;
      }
    } catch (e) {
      console.warn('[bridge] cloud-base refresh skipped:', e.message || e);
    }
  }
  if (CLOUD_BASE_URL) {
    CLOUD_PUNCHES_URL = canonicalPunchesUrl(CLOUD_BASE_URL, CLOUD_PUNCHES_URL);
  }
  return CLOUD_PUNCHES_URL;
}

function loadState() {
  try {
    return JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
  } catch {
    return { postedKeys: [], lastSyncAt: null };
  }
}

function saveState(state) {
  fs.writeFileSync(STATE_FILE, JSON.stringify(state, null, 2));
}

function md5(s) {
  return crypto.createHash('md5').update(s).digest('hex');
}

function parseWwwAuthenticate(header) {
  const raw = Array.isArray(header) ? header.join(',') : String(header || '');
  const digestPart = raw.split(/,(?=\s*Basic\b)/i)[0] || raw;
  const p = {};
  for (const m of digestPart.matchAll(/(\w+)=(?:"([^"]*)"|([^\s,]*))/g)) {
    const val = (m[2] ?? m[3] ?? '').trim();
    if (val !== '') p[m[1]] = val;
  }
  return p;
}

/** DS-K1T320 wants unquoted qop=auth (Solar LAN client). Quoted qop="auth" returns userCheck 401. */
function buildDigestHeader(challenge, method, uri, user, pass, ncCounter, opts = {}) {
  const quoteQop = opts.quoteQop === true;
  const algorithm = opts.algorithm || '';
  const nc = ncCounter.toString(16).padStart(8, '0');
  const cnonce = crypto.randomBytes(8).toString('hex');
  const qop = challenge.qop ? String(challenge.qop).split(',')[0].trim() : undefined;
  const algoName = String(algorithm || challenge.algorithm || 'MD5').toUpperCase();
  let ha1 = md5(`${user}:${challenge.realm}:${pass}`);
  if (algoName.includes('MD5-SESS')) {
    ha1 = md5(`${ha1}:${challenge.nonce}:${cnonce}`);
  }
  const ha2 = md5(`${method}:${uri}`);
  const resp = qop
    ? md5(`${ha1}:${challenge.nonce}:${nc}:${cnonce}:${qop}:${ha2}`)
    : md5(`${ha1}:${challenge.nonce}:${ha2}`);

  let auth =
    `Digest username="${user}", realm="${challenge.realm}", nonce="${challenge.nonce}", ` +
    `uri="${uri}", response="${resp}"`;
  if (qop) {
    auth += quoteQop
      ? `, qop="${qop}", nc=${nc}, cnonce="${cnonce}"`
      : `, qop=${qop}, nc=${nc}, cnonce="${cnonce}"`;
  }
  if (challenge.opaque) auth += `, opaque="${challenge.opaque}"`;
  if (algorithm) auth += `, algorithm=${algorithm}`;
  return auth;
}

function rawRequest(urlStr, options = {}, body) {
  return new Promise((resolve, reject) => {
    const u = new URL(urlStr);
    const lib = u.protocol === 'https:' ? https : http;
    const headers = { ...(options.headers || {}) };
    if (body != null && headers['Content-Length'] == null) {
      headers['Content-Length'] = Buffer.byteLength(body);
    }
    const req = lib.request(
      {
        protocol: u.protocol,
        hostname: u.hostname,
        port: u.port || (u.protocol === 'https:' ? 443 : 80),
        path: u.pathname + u.search,
        method: options.method || 'GET',
        headers,
        timeout: options.timeout || 25000,
        rejectUnauthorized: false,
      },
      (res) => {
        const chunks = [];
        res.on('data', (c) => chunks.push(c));
        res.on('end', () => {
          const text = Buffer.concat(chunks).toString('utf8');
          let json = null;
          try {
            json = JSON.parse(text);
          } catch {
            json = null;
          }
          resolve({ status: res.statusCode || 0, headers: res.headers, text, json });
        });
      },
    );
    req.on('error', reject);
    req.on('timeout', () => {
      req.destroy();
      reject(new Error(`timeout ${u.hostname}:${u.port || (u.protocol === 'https:' ? 443 : 80)}`));
    });
    if (body != null) req.write(body);
    req.end();
  });
}

let digestNc = 0;
let lockUntilMs = 0;

function parseUserCheck(xml) {
  const grab = (tag) => {
    const m = String(xml || '').match(new RegExp(`<${tag}>([^<]*)</${tag}>`, 'i'));
    return m ? m[1].trim() : '';
  };
  return {
    statusString: grab('statusString'),
    isActivated: grab('isActivated'),
    lockStatus: grab('lockStatus').toLowerCase(),
    unlockTime: Number(grab('unlockTime') || 0),
    retryLoginTime: Number(grab('retryLoginTime') || 0),
  };
}

function noteLock(res) {
  if (!res) return res;
  res.userCheck = parseUserCheck(res.text);
  if (res.userCheck.lockStatus === 'lock' && res.userCheck.unlockTime > 0) {
    lockUntilMs = Math.max(lockUntilMs, Date.now() + (res.userCheck.unlockTime + 15) * 1000);
  }
  return res;
}

function challengeFrom(res) {
  const www = res?.headers?.['www-authenticate'] || res?.headers?.['WWW-Authenticate'];
  const wwwStr = Array.isArray(www) ? www.join(' | ') : String(www || '');
  return { challenge: parseWwwAuthenticate(www), wwwStr };
}

function lockWaitMessage() {
  const sec = Math.max(0, Math.ceil((lockUntilMs - Date.now()) / 1000));
  return `[bridge] Device admin is locked (${sec}s left). Do not restart the bridge — extra failed logins extend the lock.`;
}

async function digestRequest(urlStr, options = {}, body) {
  const method = options.method || 'GET';
  const u = new URL(urlStr);
  const uri = u.pathname + u.search;
  const baseHeaders = { ...(options.headers || {}) };
  if (body != null && !baseHeaders['Content-Length']) {
    baseHeaders['Content-Length'] = Buffer.byteLength(body);
  }

  let last = noteLock(await rawRequest(urlStr, { ...options, method, headers: baseHeaders }, body));
  if (last.status !== 401) return last;
  if (last.userCheck?.lockStatus === 'lock') {
    last._authDebug = `LOCKED unlockTime=${last.userCheck.unlockTime}s`;
    return last;
  }

  const { challenge, wwwStr } = challengeFrom(last);
  if (!challenge.realm || !challenge.nonce) {
    last._authDebug = `Digest challenge missing www=${wwwStr.slice(0, 180)}`;
    return last;
  }

  digestNc += 1;
  const auth = buildDigestHeader(challenge, method, uri, DEVICE_USER, DEVICE_PASSWORD, digestNc, {
    quoteQop: false,
    algorithm: '',
  });
  last = noteLock(
    await rawRequest(urlStr, { ...options, method, headers: { ...baseHeaders, Authorization: auth } }, body),
  );
  if (last.status !== 401) return last;
  last._authDebug = `realm=${challenge.realm} qop=${challenge.qop || ''} ${last.userCheck?.lockStatus || last.userCheck?.statusString || ''}`;
  return last;
}

function isoLocal(d) {
  const pad = (n) => String(n).padStart(2, '0');
  const off = -d.getTimezoneOffset();
  const sign = off >= 0 ? '+' : '-';
  const abs = Math.abs(off);
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}${sign}${pad(Math.floor(abs / 60))}:${pad(abs % 60)}`;
}

function deviceCandidateBases() {
  const port = Number(DEVICE_PORT) || 80;
  const scheme = port === 443 ? 'https' : 'http';
  const primary = String(process.env.HIKVISION_DEVICE_BASE || `${scheme}://${DEVICE_IP}:${port}`).replace(
    /\/$/,
    '',
  );
  const bases = [primary];
  // Port 80 often only redirects; real ISAPI is HTTPS:443 on modern Hikvision terminals.
  if (port === 80) {
    bases.push(`https://${DEVICE_IP}:443`);
  } else if (port !== 443) {
    bases.push(`https://${DEVICE_IP}:${port}`);
    bases.push(`http://${DEVICE_IP}:${port}`);
  }
  return [...new Set(bases)];
}

async function probeDeviceLogin() {
  if (Date.now() < lockUntilMs) {
    console.error(lockWaitMessage());
    return false;
  }

  let lastErr = '';
  for (const base of deviceCandidateBases()) {
    const url = `${base}/ISAPI/System/deviceInfo`;
    try {
      const res = await digestRequest(url, { method: 'GET' });
      if (res.status === 200) {
        const model = (res.text.match(/<model>([^<]+)<\/model>/i) || [])[1];
        console.log(`[bridge] Device login OK via ${url}${model ? ` · ${model}` : ''}`);
        process.env.HIKVISION_DEVICE_BASE = base;
        return true;
      }
      // Redirect / connection-style failures: try next base (e.g. http→https).
      if ([301, 302, 307, 308].includes(res.status)) {
        lastErr = `${url} → HTTP ${res.status} redirect`;
        console.warn(`[bridge] ${lastErr} — trying alternate HTTPS base`);
        continue;
      }
      const check = res.userCheck || parseUserCheck(res.text);
      if (check.lockStatus === 'lock') {
        console.error(
          `[bridge] ${url} → admin LOCKED for ${check.unlockTime || '?'}s (retryLoginTime=${check.retryLoginTime}).`,
        );
        console.error(lockWaitMessage());
        return false;
      }
      lastErr = `${url} → HTTP ${res.status} ${res._authDebug || ''} ${check.statusString || ''}`.trim();
      console.error(`[bridge] ${lastErr}`);
      if (res.status === 401 || res.status === 403) {
        break;
      }
    } catch (e) {
      lastErr = `${url} → ${e.message || e}`;
      console.error(`[bridge] ${lastErr}`);
    }
  }
  console.error(
    `[bridge] LOGIN FAILED for ${DEVICE_USER}@${DEVICE_IP}:${DEVICE_PORT} (passwordChars=${DEVICE_PASSWORD.length}).`,
  );
  console.error(
    '[bridge] If the device web UI is https://IP, set DEVICE_PORT=443 in .env (this terminal redirects HTTP:80).',
  );
  console.error(
    '[bridge] In C:\\hikvision-bridge\\.env quote the web password, e.g. DEVICE_PASSWORD="your-web-password" because @ and # break unquoted .env values.',
  );
  if (lastErr) console.error(`[bridge] Last error: ${lastErr}`);
  return false;
}

function deviceBase() {
  const port = Number(DEVICE_PORT) || 80;
  const scheme = port === 443 ? 'https' : 'http';
  return String(process.env.HIKVISION_DEVICE_BASE || `${scheme}://${DEVICE_IP}:${port}`).replace(/\/$/, '');
}

async function fetchDeviceEvents(from, to, maxPages = 20) {
  const url = `${deviceBase()}/ISAPI/AccessControl/AcsEvent?format=json`;
  const queries = [
    { major: 5, minor: 38 },
    { major: 5, minor: 1 },
    { major: 5, minor: 75 },
    { major: 5, minor: 76 },
    { major: 0, minor: 0 },
  ];

  const bySerial = new Map();
  for (const q of queries) {
    let position = 0;
    for (let page = 0; page < maxPages; page += 1) {
      const searchBody = JSON.stringify({
        AcsEventCond: {
          searchID: `${Date.now()}-${q.major}-${q.minor}-${page}`,
          searchResultPosition: position,
          maxResults: 30,
          major: q.major,
          minor: q.minor,
          startTime: isoLocal(from),
          endTime: isoLocal(to),
        },
      });
      const res = await digestRequest(
        url,
        { method: 'POST', headers: { 'Content-Type': 'application/json' } },
        searchBody,
      );
      if (res.status < 200 || res.status >= 300) {
        console.log(`[bridge] AcsEvent major=${q.major} minor=${q.minor} → HTTP ${res.status}: ${res.text.slice(0, 120)}`);
        break;
      }
      const acs = res.json?.AcsEvent || {};
      const list = acs.InfoList;
      const rows = !list ? [] : Array.isArray(list) ? list : [list];
      for (const ev of rows) {
        const key = `${ev.serialNo ?? 'x'}|${ev.time || ev.dateTime || ''}|${ev.employeeNoString || ev.employeeNo || ''}`;
        bySerial.set(key, ev);
      }
      const num = Number(acs.numOfMatches || rows.length || 0);
      const total = Number(acs.totalMatches || 0);
      if (!rows.length || position + num >= total || acs.responseStatusStrg === 'NO MATCH') break;
      position += num;
    }
  }
  return Array.from(bySerial.values());
}

function mapEvent(ev) {
  const employeeNo = String(ev.employeeNoString || ev.employeeNo || ev.empNo || '').trim();
  const timeRaw = ev.time || ev.dateTime || ev.eventTime;
  if (!employeeNo || !timeRaw) return null;

  let d;
  if (/^\d{1,2}:\d{2}(:\d{2})?$/.test(String(timeRaw).trim()) && ev.date) {
    d = new Date(`${String(ev.date).slice(0, 10)}T${String(timeRaw).trim()}`);
  } else {
    d = new Date(timeRaw);
  }
  if (Number.isNaN(d.getTime())) return null;

  const pad = (n) => String(n).padStart(2, '0');
  const date = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
  const time = `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
  return {
    empNo: employeeNo,
    employeeNo,
    date,
    time,
    clockTime: time,
    serialNo: ev.serialNo != null ? Number(ev.serialNo) : null,
    eventTime: d.toISOString(),
  };
}

async function postToCloud(punches) {
  await refreshCloudPunchesUrl();
  if (!CLOUD_PUNCHES_URL) throw new Error('CLOUD_PUNCHES_URL missing');
  const body = JSON.stringify({ punches });
  const headers = { 'Content-Type': 'application/json' };
  if (SECRET) headers['X-Hikvision-Secret'] = SECRET;

  const urls = punchesUrlCandidates(CLOUD_PUNCHES_URL);
  let lastErr = '';
  for (const url of urls) {
    const res = await rawRequest(url, { method: 'POST', headers }, body);
    if (res.status >= 200 && res.status < 300) {
      CLOUD_PUNCHES_URL = url;
      return res.json?.data || res.json;
    }
    lastErr = `Cloud ${res.status}: ${String(res.text).slice(0, 180)}`;
    if (looksLikeHtml404(res)) {
      console.warn(`[bridge] nginx 404 at ${url.split('?')[0]} — trying index.php front controller`);
      continue;
    }
    throw new Error(lastErr);
  }
  throw new Error(lastErr || 'CLOUD_PUNCHES_URL missing');
}

function explainUnreachable(err) {
  const ips = localIpv4s();
  console.error(`[bridge] ERROR: ${err}`);
  console.error(`[bridge] This PC cannot open TCP to the fingerprint device ${DEVICE_IP}:${DEVICE_PORT}.`);
  console.error('[bridge] Run the bridge on an office PC on the same LAN as the terminal.');
  console.error(`[bridge] This PC IPs: ${ips.join(', ') || 'none'}`);
  if (!sameLanHint(DEVICE_IP, ips)) {
    console.error(
      `[bridge] Those IPs are not on the ${String(DEVICE_IP).split('.').slice(0, 3).join('.')}.x subnet — that is why this times out.`,
    );
  }
  console.error(`[bridge] On that office PC, open http://${DEVICE_IP}:${DEVICE_PORT} in a browser.`);
}

function assertConfigured() {
  if (!DEVICE_IP || !DEVICE_PASSWORD || !CLOUD_PUNCHES_URL) {
    const missing = [
      !DEVICE_IP && 'DEVICE_IP',
      !DEVICE_PASSWORD && 'DEVICE_PASSWORD',
      !CLOUD_PUNCHES_URL && 'CLOUD_PUNCHES_URL',
    ].filter(Boolean);
    throw new Error(`Missing ${missing.join(', ')} in ${envPath || path.join(__dirname, '.env')}`);
  }
}

async function deviceReady() {
  const probe = await tcpReachable(DEVICE_IP, DEVICE_PORT, 5000);
  if (!probe.ok) {
    explainUnreachable(probe.err);
    return false;
  }
  return probeDeviceLogin();
}

/** Posts events not in postedKeys (all events when force) in chunks; the HR server also skips duplicates. */
async function postEvents(rawEvents, postedKeys, force = false) {
  const punches = [];
  for (const ev of rawEvents) {
    const mapped = mapEvent(ev);
    if (!mapped) continue;
    const key = `${mapped.serialNo ?? 'x'}|${mapped.date} ${mapped.time}|${mapped.employeeNo}`;
    if (!force && postedKeys.has(key)) continue;
    punches.push({ ...mapped, _key: key });
  }

  const summary = { raw: rawEvents.length, sent: punches.length, imported: 0, skipped: 0, errors: [], sample: '' };
  summary.sample = punches
    .slice(0, 3)
    .map((p) => `${p.employeeNo} ${p.date} ${p.time}`)
    .join(' | ');
  for (let i = 0; i < punches.length; i += 200) {
    const chunk = punches.slice(i, i + 200);
    const result = await postToCloud(chunk.map(({ _key, ...p }) => p));
    for (const p of chunk) postedKeys.add(p._key);
    summary.imported += Number(result?.imported || 0);
    summary.skipped += Number(result?.skipped || 0);
    summary.errors.push(...(Array.isArray(result?.errors) ? result.errors : []));
  }
  return summary;
}

async function tick() {
  assertConfigured();
  const state = loadState();
  const postedKeys = new Set(state.postedKeys || []);
  const to = new Date(Date.now() + 15 * 60 * 1000);
  const from = state.lastSyncAt
    ? new Date(Math.min(new Date(state.lastSyncAt).getTime() - 30 * 60 * 1000, Date.now() - 30 * 60 * 1000))
    : new Date(Date.now() - LOOKBACK_MINUTES * 60 * 1000);

  console.log(`[bridge] Polling device ${DEVICE_IP}:${DEVICE_PORT} (Digest) from ${from.toISOString()} …`);
  if (!(await deviceReady())) return;

  const rawEvents = await fetchDeviceEvents(from, to);
  const r = await postEvents(rawEvents, postedKeys);
  saveState({ lastSyncAt: new Date().toISOString(), postedKeys: Array.from(postedKeys).slice(-5000) });
  if (!r.sent) {
    console.log(`[bridge] No new punches (raw events: ${r.raw})`);
    return;
  }
  console.log(`[bridge] Posted ${r.sent} punch(es) to ${CLOUD_PUNCHES_URL}. Sample: ${r.sample}`);
  console.log(`[bridge] Cloud result: imported ${r.imported}, skipped ${r.skipped}${r.errors.length ? `, errors: ${r.errors.slice(0, 3).join('; ')}` : ''}`);
}

function ymd(d) {
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/**
 * Day-by-day sync of [fromDay, toDay] (local dates). force = resend even if already posted.
 * Returns totals + server messages, or null when the device cannot be reached.
 */
async function syncDays(fromDay, toDay, label, force) {
  assertConfigured();
  if (!(await deviceReady())) return null;

  const postedKeys = new Set(loadState().postedKeys || []);
  const totals = { raw: 0, sent: 0, imported: 0, skipped: 0 };
  const errors = new Set();
  let day = new Date(fromDay.getFullYear(), fromDay.getMonth(), fromDay.getDate());
  const last = new Date(toDay.getFullYear(), toDay.getMonth(), toDay.getDate());
  while (day <= last) {
    const next = new Date(day.getFullYear(), day.getMonth(), day.getDate() + 1);
    const r = await postEvents(await fetchDeviceEvents(day, next, 200), postedKeys, force);
    if (r.raw || force) {
      console.log(`[bridge] ${label} ${ymd(day)}: device punches ${r.raw}, sent ${r.sent}, imported ${r.imported}, already in HR/skipped ${r.skipped}`);
    }
    r.errors.forEach((e) => errors.add(e));
    for (const k of Object.keys(totals)) totals[k] += r[k];
    if (!force) {
      saveState({ lastSyncAt: loadState().lastSyncAt, postedKeys: Array.from(postedKeys).slice(-5000) });
    }
    day = next;
  }
  console.log(`[bridge] ${label} ${ymd(fromDay)} → ${ymd(toDay)} done: device punches ${totals.raw}, sent ${totals.sent}, imported ${totals.imported}, already in HR/skipped ${totals.skipped}`);
  if (errors.size) {
    console.log(`[bridge] ${label} server messages: ${Array.from(errors).slice(0, 10).join('; ')}`);
  }
  return { ...totals, errors: Array.from(errors) };
}

/** HR app "Sync Now" (cloud mode) queues date ranges; this also tells HR the office PC is online. */
function bridgeApiUrl(suffix) {
  return CLOUD_PUNCHES_URL.replace(/(\/hikvision\/punches\/[^/?#&]+)/i, `$1${suffix}`);
}

async function cloudJson(method, suffix, payload) {
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
  if (SECRET) headers['X-Hikvision-Secret'] = SECRET;
  return rawRequest(bridgeApiUrl(suffix), { method, headers }, payload ? JSON.stringify(payload) : null);
}

let queueUnsupportedWarned = false;

async function runQueuedSyncs() {
  if (!punchesToken(CLOUD_PUNCHES_URL)) return;
  const res = await cloudJson('GET', '/sync-requests');
  if (res.status < 200 || res.status >= 300) {
    if (!queueUnsupportedWarned) {
      console.log(`[bridge] HR app sync queue not available yet (HTTP ${res.status}) — update the HR API to enable "Sync Now" from the app.`);
      queueUnsupportedWarned = true;
    }
    return;
  }
  queueUnsupportedWarned = false;

  const jobs = Array.isArray(res.json?.data) ? res.json.data : [];
  for (const job of jobs) {
    const report = (payload) =>
      cloudJson('POST', `/sync-requests/${job.id}`, payload).catch((e) =>
        console.error(`[bridge] Could not report app sync #${job.id}:`, e.message || e),
      );
    try {
      const fromDay = parseDay(String(job.from_date).slice(0, 10));
      const toDay = parseDay(String(job.to_date).slice(0, 10));
      console.log(`[bridge] HR app requested sync ${ymd(fromDay)} → ${ymd(toDay)} (request #${job.id})`);
      await report({ status: 'running' });
      const r = await syncDays(fromDay, toDay, `App sync #${job.id}`, true);
      if (!r) {
        await report({
          status: 'failed',
          message: `The office PC could not connect to the fingerprint device at ${DEVICE_IP}:${DEVICE_PORT}.`,
        });
        continue;
      }
      await report({
        status: 'done',
        device_punches: r.sent,
        imported: r.imported,
        skipped: r.skipped,
        message: `Device punches ${r.sent}: ${r.imported} new, ${r.skipped} already in HR or rejected.`,
        errors: r.errors.slice(0, 50).map((e) => String(e).slice(0, 500)),
      });
    } catch (e) {
      console.error(`[bridge] App sync #${job.id} ERROR:`, e.message || e);
      await report({ status: 'failed', message: String(e.message || e).slice(0, 2000) });
    }
  }
}

/** Lists every device user ID that punched in [fromDay, toDay] with name, punch count and days (nothing is sent to HR). */
async function reportUsers(fromDay, toDay) {
  assertConfigured();
  if (!(await deviceReady())) return false;

  const users = new Map();
  let day = new Date(fromDay.getFullYear(), fromDay.getMonth(), fromDay.getDate());
  const last = new Date(toDay.getFullYear(), toDay.getMonth(), toDay.getDate());
  while (day <= last) {
    const next = new Date(day.getFullYear(), day.getMonth(), day.getDate() + 1);
    for (const ev of await fetchDeviceEvents(day, next, 200)) {
      const mapped = mapEvent(ev);
      if (!mapped) continue;
      const u = users.get(mapped.employeeNo) || { name: '', punches: 0, days: new Set() };
      u.name = u.name || String(ev.name || '').trim();
      u.punches += 1;
      u.days.add(mapped.date);
      users.set(mapped.employeeNo, u);
    }
    day = next;
  }

  const rows = Array.from(users.entries()).sort(([a], [b]) => a.localeCompare(b, undefined, { numeric: true }));
  console.log(`[bridge] Device users who punched ${ymd(fromDay)} → ${ymd(toDay)}: ${rows.length}`);
  console.log('DEVICE_ID\tNAME\tPUNCHES\tDAYS');
  for (const [id, u] of rows) {
    console.log(`${id}\t${u.name || '-'}\t${u.punches}\t${u.days.size}`);
  }
  return true;
}

function parseDay(s) {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s).trim());
  if (!m) throw new Error(`Bad date "${s}" — use YYYY-MM-DD`);
  return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
}

/** One long-running bridge per folder; a second copy exits so they don't fight over the state file. */
function acquireLock() {
  try {
    const pid = Number(fs.readFileSync(LOCK_FILE, 'utf8'));
    if (pid && pid !== process.pid) {
      process.kill(pid, 0);
      return pid;
    }
  } catch {
    // no lock, unreadable, or that process is gone
  }
  fs.writeFileSync(LOCK_FILE, String(process.pid));
  const release = () => {
    try {
      if (Number(fs.readFileSync(LOCK_FILE, 'utf8')) === process.pid) fs.unlinkSync(LOCK_FILE);
    } catch {
      // already gone
    }
  };
  process.on('exit', release);
  ['SIGINT', 'SIGTERM', 'SIGBREAK'].forEach((sig) => process.on(sig, () => process.exit(0)));
  return null;
}

async function main() {
  await refreshCloudPunchesUrl();
  console.log('[bridge] Sohan HR Hikvision bridge started');
  console.log(
    `[bridge] Device ${DEVICE_IP}:${DEVICE_PORT} user=${DEVICE_USER} passwordChars=${DEVICE_PASSWORD.length} → ${CLOUD_PUNCHES_URL}`,
  );
  if (/paste-the-password|your-device-web-password|your-device-password/i.test(DEVICE_PASSWORD) || DEVICE_PASSWORD.length > 40) {
    console.error('[bridge] DEVICE_PASSWORD looks like example text, not the device login.');
  }
  if (CLOUD_BASE_URL) {
    console.log(`[bridge] CLOUD_BASE_URL=${CLOUD_BASE_URL} (refreshes live host from Admin)`);
  }

  if (backfillArg) {
    const [a, b] = backfillArg.split(':');
    const fromDay = parseDay(a);
    const toDay = b ? parseDay(b) : fromDay;
    console.log(`[bridge] Backfill ${ymd(fromDay)} → ${ymd(toDay)} (sends every device punch; HR skips ones it already has)`);
    const ok = await syncDays(fromDay, toDay, 'Backfill', true);
    process.exit(ok ? 0 : 1);
  }

  if (reportArg) {
    const [a, b] = reportArg.split(':');
    const fromDay = parseDay(a);
    const ok = await reportUsers(fromDay, b ? parseDay(b) : fromDay);
    process.exit(ok ? 0 : 1);
  }

  if (!once) {
    const other = acquireLock();
    if (other) {
      console.error(`[bridge] Another bridge is already running from this folder (pid ${other}). Exiting.`);
      process.exit(EXIT_ALREADY_RUNNING);
    }
  }

  let queue = Promise.resolve();
  const serial = (fn) => {
    queue = queue.then(fn, fn);
    return queue;
  };
  const run = () => serial(() => tick().catch((e) => console.error('[bridge] ERROR:', e.message || e)));
  const catchUp = () =>
    serial(() =>
      syncDays(new Date(Date.now() - CATCHUP_DAYS * 86400000), new Date(), 'Catch-up', false).catch((e) =>
        console.error('[bridge] Catch-up ERROR:', e.message || e),
      ),
    );

  const queued = () =>
    serial(() => runQueuedSyncs().catch((e) => console.error('[bridge] App sync queue ERROR:', e.message || e)));

  await run();
  if (once) return;
  queued();
  setInterval(() => {
    run();
    queued();
  }, POLL_SECONDS * 1000);
  if (CATCHUP_DAYS > 0) {
    console.log(`[bridge] Catch-up: re-checking the last ${CATCHUP_DAYS} day(s) now and every ${CATCHUP_EVERY_HOURS}h`);
    catchUp();
    setInterval(catchUp, CATCHUP_EVERY_HOURS * 3600000);
  }
}

main().catch((e) => {
  console.error('[bridge] fatal:', e.message || e);
  process.exit(1);
});
