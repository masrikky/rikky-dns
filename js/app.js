/**
 * Rikky DNS Propagation Checker
 * Static SPA — uses DNS-over-HTTPS (DoH) for browser-side DNS queries.
 * DoH endpoint: Cloudflare (https://cloudflare-dns.com/dns-query)
 */

'use strict';

// ── Constants ──────────────────────────────────────────────────────────────

const DOH_ENDPOINT = 'https://cloudflare-dns.com/dns-query';

/** DNS record types and their display config */
const RECORD_TYPES = {
  A:       { label: 'A',       desc: 'IPv4 address' },
  AAAA:    { label: 'AAAA',    desc: 'IPv6 address' },
  MX:      { label: 'MX',      desc: 'Mail exchange' },
  TXT:     { label: 'TXT',     desc: 'Text record' },
  CNAME:   { label: 'CNAME',   desc: 'Canonical name' },
  PTR:     { label: 'PTR',     desc: 'Pointer record' },
  SOA:     { label: 'SOA',     desc: 'Start of authority' },
  CAA:     { label: 'CAA',     desc: 'Certification authority' },
  DS:      { label: 'DS',      desc: 'Delegation signer' },
  DNSKEY:  { label: 'DNSKEY',  desc: 'DNS key record' },
};

/** DNS response data type → numeric type code (RFC 1035 / IANA) */
const TYPE_CODES = {
  A: 1, NS: 2, CNAME: 5, SOA: 6, PTR: 12, MX: 15,
  TXT: 16, AAAA: 28, DS: 43, DNSKEY: 48, CAA: 257,
};

const CONCURRENT_QUERIES = 8;      // parallel DoH requests per batch
const QUERY_TIMEOUT_MS   = 8000;   // per-request timeout

// ── State ──────────────────────────────────────────────────────────────────

let dnsServers  = [];
let isRunning   = false;
let abortController = null;

// ── DOM refs ───────────────────────────────────────────────────────────────

const $ = id => document.getElementById(id);

const domainInput    = $('domain-input');
const recordSelect   = $('record-select');
const checkBtn       = $('check-btn');
const clearBtn       = $('clear-btn');
const resultsSection = $('results-section');
const statsBar       = $('stats-bar');
const statResolved   = $('stat-resolved');
const statUnresolved = $('stat-unresolved');
const statTotal      = $('stat-total');
const progressFill   = $('progress-fill');
const progressLabel  = $('progress-label');
const domainLabel    = $('domain-label');
const alertContainer = $('alert-container');

// ── Boot ───────────────────────────────────────────────────────────────────

async function init() {
  try {
    const res = await fetch('./dns_servers.json');
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    dnsServers = await res.json();
  } catch (err) {
    showAlert('Failed to load DNS server list: ' + err.message);
    return;
  }

  checkBtn.addEventListener('click', startCheck);
  clearBtn.addEventListener('click', clearResults);
  domainInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') startCheck();
  });
}

// ── Query helpers ──────────────────────────────────────────────────────────

/**
 * Query Cloudflare DoH for a single record type.
 * @returns {string[]} array of formatted record values, or [] on NXDOMAIN/error
 */
async function queryDoh(domain, type, signal) {
  const typeCode = TYPE_CODES[type] ?? type;
  const url = `${DOH_ENDPOINT}?name=${encodeURIComponent(domain)}&type=${typeCode}`;

  let res;
  try {
    res = await fetch(url, {
      headers: { Accept: 'application/dns-json' },
      signal,
    });
  } catch (err) {
    if (err.name === 'AbortError') throw err;
    return [];
  }

  if (!res.ok) return [];

  const data = await res.json();

  // Status 0 = NOERROR, 3 = NXDOMAIN
  if (data.Status !== 0) return [];

  const answers = (data.Answer || []).filter(a => a.type === typeCode);
  return answers.map(a => formatAnswer(a, type));
}

/** Format a DoH answer into a human-readable string */
function formatAnswer(ans, type) {
  const d = ans.data;
  switch (type) {
    case 'MX':
      // "10 mail.example.com."
      return d;
    case 'TXT':
      return d.replace(/^"|"$/g, '');
    case 'SOA':
      return d;
    case 'CAA':
      return d;
    default:
      return d;
  }
}

// ── Main check flow ────────────────────────────────────────────────────────

async function startCheck() {
  if (isRunning) {
    stopCheck();
    return;
  }

  const rawDomain  = domainInput.value.trim();
  const recordType = recordSelect.value;

  if (!rawDomain) {
    domainInput.focus();
    domainInput.classList.add('shake');
    setTimeout(() => domainInput.classList.remove('shake'), 500);
    return;
  }

  // Basic domain / IP validation
  const domain = sanitizeDomain(rawDomain);
  if (!domain) {
    showAlert('Invalid domain format. Only alphanumeric characters, hyphens, and dots are allowed.');
    return;
  }

  clearAlert();
  isRunning       = true;
  abortController = new AbortController();
  const timeout   = setTimeout(() => abortController.abort(), QUERY_TIMEOUT_MS * 5);

  checkBtn.innerHTML = `<span class="spinner"></span> Stop`;
  checkBtn.classList.add('btn-stop');
  clearBtn.disabled  = true;

  const types = recordType === 'ALL'
    ? Object.keys(RECORD_TYPES)
    : [recordType];

  // Build flat task list: { server, type }
  const tasks = [];
  for (const type of types) {
    for (const server of dnsServers) {
      tasks.push({ server, type });
    }
  }

  const total   = tasks.length;
  let completed = 0;
  let resolved  = 0;

  // Render initial skeleton cards
  renderSkeleton(types, dnsServers.length);
  statsBar.classList.add('visible');
  domainLabel.textContent = domain;
  updateProgress(0, 0, dnsServers.length * types.length);

  // Process in batches
  try {
    for (let i = 0; i < tasks.length; i += CONCURRENT_QUERIES) {
      if (abortController.signal.aborted) break;

      const batch = tasks.slice(i, i + CONCURRENT_QUERIES);

      await Promise.all(batch.map(async ({ server, type }) => {
        let values = [];
        try {
          values = await Promise.race([
            queryDoh(domain, type, abortController.signal),
            new Promise((_, rej) =>
              setTimeout(() => rej(new Error('timeout')), QUERY_TIMEOUT_MS)
            ),
          ]);
        } catch (_) {
          values = [];
        }

        completed++;
        if (values.length > 0) resolved++;
        updateProgress(completed, resolved, total);
        updateCard(server.ip, type, values);
      }));
    }
  } finally {
    clearTimeout(timeout);
    finishCheck();
  }
}

function stopCheck() {
  abortController?.abort();
}

function finishCheck() {
  isRunning = false;
  checkBtn.innerHTML = `
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
    </svg>
    Check Propagation`;
  checkBtn.classList.remove('btn-stop');
  clearBtn.disabled = false;
}

function clearResults() {
  resultsSection.innerHTML = '';
  statsBar.classList.remove('visible');
  clearAlert();
  domainInput.value = '';
}

// ── Rendering ──────────────────────────────────────────────────────────────

/** Build initial skeleton grid (one group per record type) */
function renderSkeleton(types, serverCount) {
  resultsSection.innerHTML = '';

  for (const type of types) {
    const group = document.createElement('div');
    group.className  = 'record-group';
    group.id         = `group-${type}`;

    const title = document.createElement('div');
    title.className  = 'record-group-title';
    title.textContent = `${type} — ${RECORD_TYPES[type].desc}`;
    group.appendChild(title);

    const grid = document.createElement('div');
    grid.className   = 'servers-grid';
    grid.id          = `grid-${type}`;

    for (const server of dnsServers) {
      grid.appendChild(createCard(server, type, 'pending', []));
    }

    group.appendChild(grid);
    resultsSection.appendChild(group);
  }
}

/** Build a server result card element */
function createCard(server, type, status, values) {
  const card = document.createElement('div');
  card.className = `server-card status-${status}`;
  card.id        = `card-${type}-${server.ip.replace(/\./g, '-')}`;

  card.innerHTML = `
    <div class="card-header">
      <div>
        <div class="server-ip">${escHtml(server.ip)}</div>
        <div class="server-name">${escHtml(server.name)}</div>
        <div class="server-asn">${escHtml(server.asn)}</div>
      </div>
      ${renderBadge(status)}
    </div>
    ${renderValues(values, status)}
  `;

  return card;
}

/** Update an existing card in-place after query completes */
function updateCard(ip, type, values) {
  const id   = `card-${type}-${ip.replace(/\./g, '-')}`;
  const card = document.getElementById(id);
  if (!card) return;

  const status = values.length > 0 ? 'resolved' : 'unresolved';
  card.className = `server-card status-${status}`;

  card.innerHTML = `
    <div class="card-header">
      <div>
        <div class="server-ip">${escHtml(ip)}</div>
        <div class="server-name">${escHtml(getServerMeta(ip, 'name'))}</div>
        <div class="server-asn">${escHtml(getServerMeta(ip, 'asn'))}</div>
      </div>
      ${renderBadge(status)}
    </div>
    ${renderValues(values, status)}
  `;
}

function renderBadge(status) {
  const labels = {
    resolved:   'Resolved',
    unresolved: 'No Record',
    pending:    'Checking…',
  };
  return `
    <span class="status-badge badge-${status}">
      <span class="pulse"></span>
      ${labels[status] ?? status}
    </span>`;
}

function renderValues(values, status) {
  if (status === 'pending') return '';
  if (values.length === 0) return '';
  return `
    <div class="record-values">
      ${values.map(v => `<div class="record-value">${escHtml(v)}</div>`).join('')}
    </div>`;
}

// ── Progress ───────────────────────────────────────────────────────────────

function updateProgress(completed, resolved, total) {
  const unresolved = completed - resolved;
  const pct = total > 0 ? Math.round((completed / total) * 100) : 0;

  progressFill.style.width = `${pct}%`;
  progressLabel.textContent = `${completed} / ${total}`;
  statResolved.textContent   = resolved;
  statUnresolved.textContent = unresolved;
  statTotal.textContent      = total;
}

// ── Utilities ──────────────────────────────────────────────────────────────

function sanitizeDomain(raw) {
  // Strip protocol/path if user pastes a URL
  let d = raw.replace(/^https?:\/\//i, '').split('/')[0].split('?')[0].trim();
  // Validate: alphanumeric, hyphens, dots, underscores (for _dmarc etc)
  if (!/^[a-zA-Z0-9._-]+$/.test(d)) return null;
  return d.toLowerCase();
}

function getServerMeta(ip, field) {
  const s = dnsServers.find(s => s.ip === ip);
  return s ? s[field] : ip;
}

function escHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function showAlert(msg) {
  alertContainer.innerHTML = `
    <div class="alert alert-error">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>
      ${escHtml(msg)}
    </div>`;
}
function clearAlert() { alertContainer.innerHTML = ''; }

// ── Start ──────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', init);
