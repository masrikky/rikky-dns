# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.0.0] — 2026-03-11

### 🚀 Major Rework: PHP → Static HTML/JS

This version is a **complete rewrite** of the original PHP-based tool into a fully static
single-page application (SPA) that requires no server-side runtime.

### Added
- **Static SPA** (`index.html`) replacing `index.php` and `dnscheck.php`
- **DNS-over-HTTPS (DoH) engine** (`js/app.js`) — queries Cloudflare DoH API from the browser
- **10 record types**: A, AAAA, MX, TXT, CNAME, PTR, SOA, CAA, DS, DNSKEY
- **"ALL" mode**: query all record types in a single run
- **Color-coded status cards**: green (resolved) / red (no record) / animated pending state
- **Real-time progress bar** with resolved / unresolved / total counters
- **Concurrent querying**: up to 8 parallel DoH requests for fast results
- **Modern dark glassmorphism UI** (`css/style.css`) with:
  - Inter + JetBrains Mono typography (Google Fonts)
  - Animated status badges with pulse indicator
  - Hover effects and card entrance animations
  - Fully responsive layout (mobile-first)
- **`dns_servers.json`**: structured JSON replacing the plain-text `dns_servers.txt`
  - Fields: `ip`, `name`, `asn`
  - 278 entries retained from the original list
- **`README.md`**: full English documentation (overview, features, deployment guide)
- **`CHANGELOG.md`**: this file

### Changed
- `dns_servers.txt` → `dns_servers.json` (structured format, all data preserved)
- All code and documentation rewritten in **English**

### Removed
- `index.php` — replaced by `index.html`
- `dnscheck.php` — replaced by `js/app.js` (client-side DoH queries)
- `dns_servers.txt` — replaced by `dns_servers.json`
- `dns_servers.txt.bak` — obsolete backup file

### Technical Notes
- DNS resolution now uses Cloudflare's DoH endpoint: `https://cloudflare-dns.com/dns-query`
- Each DNS server in the list is displayed as a result card; status reflects what the
  public internet resolves (via DoH), not per-resolver direct UDP query (not possible from browser due to CORS)
- Requires a static file server (cannot open as `file://` due to fetch CORS restrictions)

---

## [1.0.0] — 2025 (initial)

### Initial PHP version
- `index.php`: reads `dns_servers.txt`, performs `dns_get_record()` server-side, outputs HTML table
- `dnscheck.php`: extended version adding IP-based country lookup via ipinfo.io
- `dns_servers.txt`: plain-text list with 278 DNS server IPs and ISP names
- Only `A` record type supported
- No UI styling — raw HTML table output
