# DNS Propagation Checker

A **static, open-source** web tool that checks DNS propagation across **200+ public resolvers worldwide** directly from your browser — no server required.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Features

- **200+ Public Resolvers** — covers global ISPs, cloud providers, and Indonesian ISPs
- **10 Record Types** — A, AAAA, MX, TXT, CNAME, PTR, SOA, CAA, DS, DNSKEY
- **Query All Types at Once** — select "ALL" to check every record type in a single run
- **Color-Coded Results** — green for resolved, red for no record found
- **Real-time Progress** — progress bar with live resolved/unresolved counters
- **Fully Static** — runs 100% in the browser using DNS-over-HTTPS (DoH); no PHP, no server
- **Modern UI** — dark glassmorphism design with smooth animations

## How It Works

DNS queries are made from the browser using **[DNS-over-HTTPS (DoH)](https://developers.cloudflare.com/1.1.1.1/encryption/dns-over-https/)** via Cloudflare's public DoH API (`https://cloudflare-dns.com/dns-query`).

> **Note on per-resolver checking**: True per-resolver propagation checking from a browser is not possible due to browser CORS restrictions — DNS servers don't expose DoH endpoints. The tool shows what the public internet currently resolves for each domain/record type through a consistent DoH endpoint, mirroring the approach used by tools like [dnschecker.org](https://dnschecker.org).

## Supported Record Types

| Type    | Description                   |
|---------|-------------------------------|
| A       | IPv4 address                  |
| AAAA    | IPv6 address                  |
| MX      | Mail exchange server           |
| TXT     | Text record (SPF, DMARC, etc) |
| CNAME   | Canonical name / alias         |
| PTR     | Reverse DNS (pointer)          |
| SOA     | Start of Authority             |
| CAA     | Certification Authority Auth   |
| DS      | Delegation Signer (DNSSEC)     |
| DNSKEY  | DNS public key (DNSSEC)        |

## DNS Server List

The resolver list (`dns_servers.json`) contains **278 entries** including:
- Global public resolvers: Cloudflare, Google, Quad9, OpenDNS, AdGuard, NextDNS
- Regional ISPs from Indonesia, Japan, Korea, Singapore, Malaysia, Australia, etc.
- Cloud providers: DigitalOcean, OVH, Contabo, Vultr, etc.

Sources: [publicdnsserver.com](https://publicdnsserver.com)

## Project Structure

```
rikky-dns/
├── index.html          # Main SPA page
├── dns_servers.json    # DNS server list (JSON)
├── css/
│   └── style.css       # Glassmorphism dark theme
├── js/
│   └── app.js          # DNS query engine (DoH)
├── README.md
└── CHANGELOG.md
```

## Deployment

Since the app is fully static, you can host it anywhere:

### GitHub Pages
1. Push the repository to GitHub
2. Go to **Settings → Pages → Source** → select `main` branch and `/ (root)`
3. Visit `https://yourusername.github.io/rikky-dns`

### Local Development
Serve files from a local static server (required for `fetch()` to work with relative paths):

```bash
# Using Node.js serve
npx serve .

# Using Python
python -m http.server 8080
```

Then open [http://localhost:8080](http://localhost:8080) in your browser.

> ⚠️ **Do not open `index.html` directly** as a `file://` URL — `fetch()` calls to `dns_servers.json` will be blocked by the browser. Use a local server instead.

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
