# DNS Propagation Checker

A **static + PHP-powered** open-source web tool that checks DNS propagation across **200+ public resolvers worldwide** — with true per-resolver UDP queries when hosted on PHP, and automatic DoH fallback for static hosting.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Features

- **True Per-Resolver Queries** — when hosted on PHP, sends real UDP/TCP DNS packets to each resolver directly
- **Resolver Selector** — pick a specific resolver from a searchable datalist, or leave blank to query all
- **Results Search** — live-filter results by IP, name, or ASN after querying
- **200+ Public Resolvers** — covers global ISPs, cloud providers, and Indonesian ISPs
- **11 Record Types** — A, AAAA, NS, MX, TXT, CNAME, PTR, SOA, CAA, DS, DNSKEY
- **Query All Types at Once** — select "ALL" to check every record type in a single run
- **Pagination** — 50 results per page with smart page-number navigation
- **Filter Tabs** — instantly filter by All / Resolved / No Record with live counts
- **Fast Timeouts** — dead resolvers fail in ≤2 s (UDP); JS cancels hung proxy requests at 4 s
- **Color-Coded Results** — green for resolved, red for no record found
- **Real-time Progress** — progress bar with live resolved/unresolved counters
- **Mobile Responsive** — fully responsive down to small phones
- **Modern UI** — dark glassmorphism design with smooth animations
- **DoH Fallback** — works on static hosting (GitHub Pages, Netlify, etc.) via Cloudflare DoH

## How It Works

### With PHP Hosting (recommended)

The front-end pings `/api/check.php?ping=1` on startup. If the PHP backend is detected, all DNS queries are sent to `api/check.php`, which performs real DNS queries using a transport fallback chain:

```
UDP port 53  →  TCP port 53  →  DoH via cURL (for resolvers with known endpoints)
```

This gives true per-resolver results — each card shows what **that specific DNS server** actually returns.

### Without PHP (static hosting)

If the PHP API is not available, the app automatically falls back to querying [Cloudflare DoH](https://developers.cloudflare.com/1.1.1.1/encryption/dns-over-https/) (`https://cloudflare-dns.com/dns-query`) from the browser. All resolver cards will reflect the global internet view via Cloudflare.

## Supported Record Types

| Type   | Description                    |
|--------|--------------------------------|
| A      | IPv4 address                   |
| AAAA   | IPv6 address                   |
| NS     | Name server                    |
| MX     | Mail exchange server           |
| TXT    | Text record (SPF, DMARC, etc.) |
| CNAME  | Canonical name / alias         |
| PTR    | Reverse DNS (pointer)          |
| SOA    | Start of Authority             |
| CAA    | Certification Authority Auth   |
| DS     | Delegation Signer (DNSSEC)     |
| DNSKEY | DNS public key (DNSSEC)        |

## DNS Server List

The resolver list (`dns_servers.json`) contains **278 entries** including:
- Global public resolvers: Cloudflare, Google, Quad9, OpenDNS, AdGuard, NextDNS
- Regional ISPs from Indonesia, Japan, Korea, Singapore, Malaysia, Australia, etc.
- Cloud providers: DigitalOcean, OVH, Contabo, Vultr, etc.

Sources: [publicdnsserver.com](https://publicdnsserver.com)

## Project Structure

```
rikky-dns/
├── index.html           # Main SPA page
├── dns_servers.json     # DNS resolver list (JSON)
├── css/
│   └── style.css        # Glassmorphism dark theme
├── js/
│   └── app.js           # Query engine (auto-detects PHP or DoH fallback)
├── api/
│   ├── check.php        # Per-resolver DNS query backend (UDP → TCP → DoH)
│   └── diagnostic.php  # Hosting compatibility tester (delete after use)
├── Dockerfile           # php:8.3-apache production image
├── docker-compose.yml   # Dev / self-hosted convenience wrapper
├── .dockerignore
├── README.md
└── CHANGELOG.md
```

## Deployment

### Docker (self-hosted)

```bash
# Production — build and run
docker build -t rikky-dns .
docker run -p 8080:80 rikky-dns
# Open http://localhost:8080

# Development — live reload via volume mounts
docker compose up --build
```

> ⚠️ The container needs outbound access to public resolvers on **UDP/TCP port 53**.
> `docker-compose.yml` sets the container DNS to `8.8.8.8` / `1.1.1.1` to ensure this works.
> `api/diagnostic.php` is automatically removed from the production image.

### PHP Shared Hosting (full per-resolver mode)

Upload all files including the `api/` folder. Requires PHP 7.4+ with `fsockopen` enabled (standard on most shared hosts).

To verify your hosting is compatible, upload `api/diagnostic.php` and access:
```
https://yourdomain.com/api/diagnostic.php?k=rahasia
```
Delete the diagnostic file after testing.

### GitHub Pages / Static Hosting

Upload everything **except** the `api/` folder (PHP won't run). The app will automatically fall back to Cloudflare DoH mode.

1. Push to GitHub
2. Go to **Settings → Pages → Source** → select `main` branch
3. Visit `https://yourusername.github.io/rikky-dns`

### Local Development

```bash
# PHP (full per-resolver mode)
php -S localhost:8080

# Python (DoH fallback mode)
python -m http.server 8080

# Node.js (DoH fallback mode)
npx serve .
```

> ⚠️ Do not open `index.html` directly as a `file://` URL — `fetch()` calls will be blocked by the browser.

## Contributing

1. Fork the repository
2. Add/update DNS servers in `dns_servers.json`
3. Submit a pull request with a description of changes

### Adding DNS Servers

Edit `dns_servers.json` and add entries following this format:

```json
{ "ip": "1.1.1.1", "name": "Cloudflare, Inc.", "asn": "AS13335" }
```

## License

MIT License — see [LICENSE](LICENSE) for details.
