# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.6.0] — 2026-03-12

### Added
- **Docker support** — production-ready containerisation:
  - `Dockerfile` — `php:8.3-apache` base; installs `curl` extension, enables Apache rewrite, removes diagnostic script from image, adds healthcheck
  - `docker-compose.yml` — convenience wrapper with source volume mounts for live-reload in dev and public DNS (`8.8.8.8`, `1.1.1.1`) so the container can reach resolvers on port 53
  - `.dockerignore` — excludes `.git`, Docker files, IDE dirs, OS artifacts

---

## [2.5.0] — 2026-03-12


### Changed
- **Smarter timeout cascade in `api/check.php`** — dead servers now fail in ~2 s instead of up to 15 s:
  - UDP timeout reduced 5 s → **2 s** (aggressive fail-fast for non-responding resolvers)
  - TCP is now only attempted when the UDP socket itself couldn’t be created (= PHP host blocks UDP); if UDP opened but got no reply, TCP is skipped (server is dead)
  - cURL/DoH timeout reduced 5 s → **3 s**
  - Worst-case per-resolver: UDP blocked host → TCP (3 s) + DoH (3 s) = **6 s** max
  - Normal dead server: UDP (2 s) + DoH (3 s, if known endpoint) = **5 s** max
- **`PROXY_TIMEOUT_MS = 4000`** added in `js/app.js` — JS aborts proxy requests at 4 s (matches PHP worst-case); DoH mode keeps 8 s
- `CONCURRENT_QUERIES` increased 8 → **10** for faster overall throughput

---

## [2.4.0] — 2026-03-12

### Added
- **Resolver selector** — new form field "Resolver" with a searchable datalist of all 278 servers:
  - Leave empty (default) to query **all resolvers**
  - Type or select a specific resolver (e.g. `8.8.8.8 — Google LLC`) to run a **single-resolver query** (returns 1 result card)
- **Results search bar** — appears after a query completes; type IP, name, or ASN to live-filter results:
  - Combinable with the All/Resolved/No Record filter tabs
  - Shows match count (e.g. `3 of 275 resolvers`) as you type
  - `searchQuery` state is reset between queries

---

## [2.3.0] — 2026-03-11

### Added
- **PHP per-resolver backend** (`api/check.php`) — queries each DNS server directly using a transport fallback chain:
  1. **UDP** (`fsockopen udp://`) — fastest, used when available
  2. **TCP** (`fsockopen` port 53, 2-byte length prefix) — more shared-hosting compatible than UDP
  3. **DoH via cURL** (RFC 8484 binary POST) — last resort for 25+ major resolvers with known public DoH endpoints (Cloudflare, Google, Quad9, AdGuard, OpenDNS, Alibaba, NextDNS, Hurricane Electric, Yandex…)
  - Response includes a `method` field (`"udp"` / `"tcp"` / `"doh"`) indicating which transport was used
  - Full record parser: A, AAAA, NS, MX, TXT, CNAME, PTR, SOA, CAA, DS, DNSKEY
  - Input validation, CORS headers, health-check endpoint (`?ping=1`)
- **Auto-detect in front-end** (`js/app.js`) — pings `/api/check.php?ping=1` on startup; uses per-resolver queries if API available, else falls back to Cloudflare DoH


---

## [2.2.0] — 2026-03-11


### Added
- **NS record type** — Name Server records now supported (added between AAAA and MX in the dropdown and in ALL mode)

### Fixed
- **Mobile responsiveness** — two breakpoints added:
  - `≤ 768px`: stats bar stacks vertically, progress bar stretches full-width, cards go single-column, buttons full-width, filter tabs and pagination buttons compact
  - `≤ 480px`: reduced page/card padding, header scales to `1.75rem`, filter tabs go equal-width full-row, pagination ellipsis hidden to save space

---

## [2.1.0] — 2026-03-11

### Added
- **Pagination** — results paginated at 50 cards per page with prev/next navigation and smart page-number windowing (e.g. `1 … 3 4 5 … 11`). Page info label shows current range (e.g. `51–100 of 275`).
- **Filter tabs** — three tabs above results: `All`, `● Resolved`, `● No Record` with live counts that update while querying. Clicking a tab instantly filters the current result set without re-querying.
- Live card patching: visible cards update in-place; hidden (off-page) cards are corrected on `renderView()` at query completion.

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
- DNS resolution defaults to Cloudflare's DoH endpoint (`https://cloudflare-dns.com/dns-query`) when no PHP backend is available
- When deployed on PHP hosting, `api/check.php` performs real per-resolver UDP/TCP queries (added in v2.3.0)
- Requires a static file server (cannot open as `file://` due to fetch CORS restrictions)

---

## [1.0.0] — 2025 (initial)

### Initial PHP version
- `index.php`: reads `dns_servers.txt`, performs `dns_get_record()` server-side, outputs HTML table
- `dnscheck.php`: extended version adding IP-based country lookup via ipinfo.io
- `dns_servers.txt`: plain-text list with 278 DNS server IPs and ISP names
- Only `A` record type supported
- No UI styling — raw HTML table output
