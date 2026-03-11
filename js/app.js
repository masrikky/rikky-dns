/**
 * Rikky DNS Propagation Checker
 * Static SPA — uses DNS-over-HTTPS (DoH) for browser-side DNS queries.
 * DoH endpoint: Cloudflare (https://cloudflare-dns.com/dns-query)
 *
 * Features:
 *  - Pagination (PAGE_SIZE cards per page)
 *  - Filter tabs: All / Resolved / No Record
 */

'use strict';

// ── Constants ──────────────────────────────────────────────────────────────

const DOH_ENDPOINT = 'https://cloudflare-dns.com/dns-query';

/** DNS record types and their display config */
const RECORD_TYPES = {
  A:       { label: 'A',       desc: 'IPv4 address' },
  AAAA:    { label: 'AAAA',    desc: 'IPv6 address' },
  NS:      { label: 'NS',      desc: 'Name server' },
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

const CONCURRENT_QUERIES = 8;    // parallel DoH requests per batch
const QUERY_TIMEOUT_MS   = 8000; // per-request timeout
const PAGE_SIZE          = 50;   // cards per page

// ── State ──────────────────────────────────────────────────────────────────

let dnsServers      = [];
let isRunning       = false;
let abortController = null;

/**
 * Results store: Map<"type:ip", { server, type, status, values }>
 * status: 'pending' | 'resolved' | 'unresolved'
 */
let resultsMap = new Map();

let activeFilter = 'all';   // 'all' | 'resolved' | 'unresolved'
let currentPage  = 1;
let activeTypes  = [];      // record types in current query

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
const filterBar      = $('filter-bar');
const paginationBar  = $('pagination-bar');

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

  // Filter tabs — delegated listener
  filterBar.addEventListener('click', e => {
    const btn = e.target.closest('[data-filter]');
    if (!btn) return;
    setFilter(btn.dataset.filter);
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
  if (type === 'TXT') return d.replace(/^"|"$/g, '');
  return d;
}

// ── Main check flow ────────────────────────────────────────────────────────

async function startCheck() {
  if (isRunning) { stopCheck(); return; }

  const rawDomain  = domainInput.value.trim();
  const recordType = recordSelect.value;

  if (!rawDomain) {
    domainInput.focus();
    domainInput.classList.add('shake');
    setTimeout(() => domainInput.classList.remove('shake'), 500);
    return;
  }

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

  activeTypes  = recordType === 'ALL' ? Object.keys(RECORD_TYPES) : [recordType];
  activeFilter = 'all';
  currentPage  = 1;
  resultsMap   = new Map();

  // Build flat task list
  const tasks = [];
  for (const type of activeTypes) {
    for (const server of dnsServers) {
      const key = `${type}:${server.ip}`;
      resultsMap.set(key, { server, type, status: 'pending', values: [] });
      tasks.push({ server, type, key });
    }
  }

  const total   = tasks.length;
  let completed = 0;
  let resolved  = 0;

  // Show UI chrome
  showFilterBar();
  statsBar.classList.add('visible');
  domainLabel.textContent = domain;
  updateProgress(0, 0, total);

  // Initial render (all pending)
  renderView();

  try {
    for (let i = 0; i < tasks.length; i += CONCURRENT_QUERIES) {
      if (abortController.signal.aborted) break;

      const batch = tasks.slice(i, i + CONCURRENT_QUERIES);

      await Promise.all(batch.map(async ({ server, type, key }) => {
        let values = [];
        try {
          values = await Promise.race([
            queryDoh(domain, type, abortController.signal),
            new Promise((_, rej) =>
              setTimeout(() => rej(new Error('timeout')), QUERY_TIMEOUT_MS)
            ),
          ]);
        } catch (_) { values = []; }

        const status = values.length > 0 ? 'resolved' : 'unresolved';
        resultsMap.set(key, { server, type, status, values });

        completed++;
        if (values.length > 0) resolved++;
        updateProgress(completed, resolved, total);
        updateFilterCounts();
        patchCardOrRerender(key, status, values);
      }));
    }
  } finally {
    clearTimeout(timeout);
    finishCheck();
    renderView(); // final pass to ensure full consistency
  }
}

function stopCheck() { abortController?.abort(); }

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
  if (filterBar)      filterBar.classList.remove('visible');
  if (paginationBar)  paginationBar.innerHTML = '';
  statsBar.classList.remove('visible');
  clearAlert();
  domainInput.value = '';
  resultsMap   = new Map();
  activeFilter = 'all';
  currentPage  = 1;
  activeTypes  = [];

  // Restore empty state
  resultsSection.innerHTML = `
    <div class="state-placeholder">
      <div class="icon">🌐</div>
      <h3>Ready to check</h3>
      <p>Enter a domain name above and select a record type, then click <strong>Check Propagation</strong> to see results from 200+ public resolvers.</p>
    </div>`;
}

// ── Filter ─────────────────────────────────────────────────────────────────

function showFilterBar() {
  filterBar.classList.add('visible');
  renderFilterTabs();
}

function renderFilterTabs() {
  const all        = resultsMap.size;
  const resolved   = [...resultsMap.values()].filter(r => r.status === 'resolved').length;
  const unresolved = [...resultsMap.values()].filter(r => r.status === 'unresolved').length;

  filterBar.innerHTML = `
    <div class="filter-tabs">
      <button class="filter-tab ${activeFilter === 'all'        ? 'active' : ''}" data-filter="all">
        All <span class="filter-count">${all}</span>
      </button>
      <button class="filter-tab ${activeFilter === 'resolved'   ? 'active' : ''}" data-filter="resolved">
        <span class="dot-resolved"></span>Resolved
        <span class="filter-count resolved-count">${resolved}</span>
      </button>
      <button class="filter-tab ${activeFilter === 'unresolved' ? 'active' : ''}" data-filter="unresolved">
        <span class="dot-unresolved"></span>No Record
        <span class="filter-count unresolved-count">${unresolved}</span>
      </button>
    </div>`;
}

function updateFilterCounts() {
  const tabAll        = filterBar.querySelector('[data-filter="all"] .filter-count');
  const tabResolved   = filterBar.querySelector('[data-filter="resolved"] .filter-count');
  const tabUnresolved = filterBar.querySelector('[data-filter="unresolved"] .filter-count');
  if (!tabAll) return;

  const all        = resultsMap.size;
  const resolved   = [...resultsMap.values()].filter(r => r.status === 'resolved').length;
  const unresolved = [...resultsMap.values()].filter(r => r.status === 'unresolved').length;

  tabAll.textContent        = all;
  tabResolved.textContent   = resolved;
  tabUnresolved.textContent = unresolved;
}

function setFilter(filter) {
  activeFilter = filter;
  currentPage  = 1;
  renderFilterTabs();
  renderView();
}

// ── View Rendering (filter + pagination) ───────────────────────────────────

function getFilteredResults() {
  let entries = [...resultsMap.values()];
  if (activeFilter === 'resolved')   entries = entries.filter(r => r.status === 'resolved');
  if (activeFilter === 'unresolved') entries = entries.filter(r => r.status === 'unresolved');
  return entries;
}

/**
 * Full render of the current view (filtered + paginated).
 * Called on filter change, page change, and after query run.
 */
function renderView() {
  resultsSection.innerHTML = '';

  const filtered   = getFilteredResults();
  const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  currentPage = Math.min(currentPage, totalPages);

  const start = (currentPage - 1) * PAGE_SIZE;
  const page  = filtered.slice(start, start + PAGE_SIZE);

  if (filtered.length === 0) {
    let msg = 'No results';
    if (activeFilter === 'resolved')   msg = 'No resolved records yet.';
    if (activeFilter === 'unresolved') msg = 'No unresolved records found.';
    resultsSection.innerHTML = isRunning
      ? '<div class="state-placeholder"><div class="icon">⏳</div><h3>Querying…</h3><p>Results will appear as they arrive.</p></div>'
      : `<div class="state-placeholder"><div class="icon">🔍</div><h3>${msg}</h3></div>`;
    renderPagination(0, 1);
    return;
  }

  // Group page entries by record type in original order
  const byType = new Map();
  for (const type of activeTypes) byType.set(type, []);
  for (const entry of page) {
    if (!byType.has(entry.type)) byType.set(entry.type, []);
    byType.get(entry.type).push(entry);
  }

  for (const [type, entries] of byType) {
    if (entries.length === 0) continue;

    const group = document.createElement('div');
    group.className = 'record-group';

    const title = document.createElement('div');
    title.className = 'record-group-title';
    title.textContent = `${type} — ${RECORD_TYPES[type]?.desc ?? ''}`;
    group.appendChild(title);

    const grid = document.createElement('div');
    grid.className = 'servers-grid';
    for (const entry of entries) grid.appendChild(buildCard(entry));

    group.appendChild(grid);
    resultsSection.appendChild(group);
  }

  renderPagination(filtered.length, totalPages);
}

/**
 * Try to patch a visible card in-place when a result arrives.
 * If the card is not in the current page/filter view, skip — renderView() at
 * the end of the run will correct it.
 */
function patchCardOrRerender(key, status, values) {
  const colonIdx = key.indexOf(':');
  const type = key.slice(0, colonIdx);
  const ip   = key.slice(colonIdx + 1);
  const id   = `card-${type}-${ip.replace(/\./g, '-')}`;
  const el   = document.getElementById(id);

  if (!el) return; // not currently rendered → will be caught by final renderView()

  const entry = resultsMap.get(key);
  if (!entry) return;

  el.className = `server-card status-${status}`;
  el.innerHTML = buildCardHTML(entry);

  // Hide if it no longer matches the active filter
  if (activeFilter === 'resolved'   && status !== 'resolved')   el.style.display = 'none';
  if (activeFilter === 'unresolved' && status !== 'unresolved') el.style.display = 'none';
}

// ── Card building ──────────────────────────────────────────────────────────

function buildCard(entry) {
  const card = document.createElement('div');
  card.className = `server-card status-${entry.status}`;
  card.id = `card-${entry.type}-${entry.server.ip.replace(/\./g, '-')}`;
  card.innerHTML = buildCardHTML(entry);
  return card;
}

function buildCardHTML({ server, status, values }) {
  return `
    <div class="card-header">
      <div>
        <div class="server-ip">${escHtml(server.ip)}</div>
        <div class="server-name">${escHtml(server.name)}</div>
        <div class="server-asn">${escHtml(server.asn)}</div>
      </div>
      ${renderBadge(status)}
    </div>
    ${renderValues(values, status)}`;
}

function renderBadge(status) {
  const labels = { resolved: 'Resolved', unresolved: 'No Record', pending: 'Checking…' };
  return `
    <span class="status-badge badge-${status}">
      <span class="pulse"></span>
      ${labels[status] ?? status}
    </span>`;
}

function renderValues(values, status) {
  if (status === 'pending' || values.length === 0) return '';
  return `
    <div class="record-values">
      ${values.map(v => `<div class="record-value">${escHtml(v)}</div>`).join('')}
    </div>`;
}

// ── Pagination ─────────────────────────────────────────────────────────────

function renderPagination(total, totalPages) {
  paginationBar.innerHTML = '';
  if (totalPages <= 1) return;

  const nav = document.createElement('div');
  nav.className = 'pagination';

  // Prev
  const prev = document.createElement('button');
  prev.className = 'page-btn page-nav';
  prev.disabled  = currentPage === 1;
  prev.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M15 18l-6-6 6-6"/></svg>`;
  prev.addEventListener('click', () => goToPage(currentPage - 1));
  nav.appendChild(prev);

  // Page numbers (smart window)
  for (const p of getPageNumbers(currentPage, totalPages)) {
    if (p === '…') {
      const gap = document.createElement('span');
      gap.className = 'page-gap';
      gap.textContent = '…';
      nav.appendChild(gap);
    } else {
      const btn = document.createElement('button');
      btn.className = `page-btn${p === currentPage ? ' active' : ''}`;
      btn.textContent = p;
      btn.addEventListener('click', () => goToPage(p));
      nav.appendChild(btn);
    }
  }

  // Next
  const next = document.createElement('button');
  next.className = 'page-btn page-nav';
  next.disabled  = currentPage === totalPages;
  next.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="14" height="14"><path d="M9 18l6-6-6-6"/></svg>`;
  next.addEventListener('click', () => goToPage(currentPage + 1));
  nav.appendChild(next);

  // Info label
  const info = document.createElement('span');
  info.className = 'page-info';
  const s = (currentPage - 1) * PAGE_SIZE + 1;
  const e = Math.min(currentPage * PAGE_SIZE, total);
  info.textContent = `${s}–${e} of ${total}`;
  nav.appendChild(info);

  paginationBar.appendChild(nav);
}

function getPageNumbers(current, total) {
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1);
  if (current <= 4)          return [1, 2, 3, 4, 5, '…', total];
  if (current >= total - 3)  return [1, '…', total-4, total-3, total-2, total-1, total];
  return [1, '…', current-1, current, current+1, '…', total];
}

function goToPage(page) {
  currentPage = page;
  renderView();
  resultsSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ── Progress ───────────────────────────────────────────────────────────────

function updateProgress(completed, resolved, total) {
  const pct = total > 0 ? Math.round((completed / total) * 100) : 0;
  progressFill.style.width   = `${pct}%`;
  progressLabel.textContent  = `${completed} / ${total}`;
  statResolved.textContent   = resolved;
  statUnresolved.textContent = completed - resolved;
  statTotal.textContent      = total;
}

// ── Utilities ──────────────────────────────────────────────────────────────

function sanitizeDomain(raw) {
  let d = raw.replace(/^https?:\/\//i, '').split('/')[0].split('?')[0].trim();
  if (!/^[a-zA-Z0-9._-]+$/.test(d)) return null;
  return d.toLowerCase();
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
