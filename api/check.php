<?php
/**
 * DNS Propagation Checker — Per-Resolver Query API
 *
 * Query chain (per request):
 *   1. UDP  — raw DNS over UDP port 53 (fastest, may be blocked on some hosts)
 *   2. TCP  — raw DNS over TCP port 53 (fallback; almost always allowed)
 *   3. DoH  — RFC 8484 POST via cURL   (fallback for servers with known DoH endpoints)
 *
 * Endpoint : GET/POST /api/check.php
 * Params   : domain (string), type (string), server (IPv4)
 * Returns  : JSON { status, domain, type, server, records[], rcode, ttl, method }
 */

declare(strict_types=1);

// ── CORS + headers ──────────────────────────────────────────────────────────

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Config ──────────────────────────────────────────────────────────────────

const DNS_PORT     = 53;
const TIMEOUT_SEC  = 5;
const MAX_PKT_SIZE = 4096;

const TYPE_CODES = [
    'A'      => 1,
    'NS'     => 2,
    'CNAME'  => 5,
    'SOA'    => 6,
    'PTR'    => 12,
    'MX'     => 15,
    'TXT'    => 16,
    'AAAA'   => 28,
    'DS'     => 43,
    'DNSKEY' => 48,
    'CAA'    => 257,
];

/**
 * Known public DoH endpoints (RFC 8484) indexed by resolver IP.
 * Used as last-resort fallback when both UDP and TCP are unavailable.
 */
const DOH_ENDPOINTS = [
    // Cloudflare
    '1.1.1.1'           => 'https://1.1.1.1/dns-query',
    '1.0.0.1'           => 'https://1.0.0.1/dns-query',
    '1.0.0.2'           => 'https://cloudflare-dns.com/dns-query',
    // Google
    '8.8.8.8'           => 'https://8.8.8.8/dns-query',
    '8.8.4.4'           => 'https://8.8.4.4/dns-query',
    // Quad9
    '9.9.9.9'           => 'https://9.9.9.9/dns-query',
    '9.9.9.10'          => 'https://9.9.9.10/dns-query',
    '149.112.112.10'    => 'https://149.112.112.10/dns-query',
    '149.112.112.112'   => 'https://149.112.112.112/dns-query',
    // AdGuard
    '94.140.14.14'      => 'https://94.140.14.14/dns-query',
    '94.140.15.15'      => 'https://94.140.15.15/dns-query',
    '94.140.14.15'      => 'https://94.140.14.15/dns-query',
    '94.140.15.16'      => 'https://94.140.15.16/dns-query',
    '94.140.14.140'     => 'https://94.140.14.140/dns-query',
    '94.140.14.141'     => 'https://94.140.14.141/dns-query',
    // OpenDNS (Cisco)
    '208.67.222.222'    => 'https://doh.opendns.com/dns-query',
    '208.67.220.220'    => 'https://doh.opendns.com/dns-query',
    // Alibaba
    '223.5.5.5'         => 'https://dns.alidns.com/dns-query',
    '223.6.6.6'         => 'https://dns.alidns.com/dns-query',
    // NextDNS
    '45.90.29.120'      => 'https://dns.nextdns.io/dns-query',
    '45.90.30.97'       => 'https://dns.nextdns.io/dns-query',
    '45.90.29.149'      => 'https://dns.nextdns.io/dns-query',
    '45.90.31.217'      => 'https://dns.nextdns.io/dns-query',
    '45.90.28.169'      => 'https://dns.nextdns.io/dns-query',
    // Hurricane Electric
    '74.82.42.42'       => 'https://74.82.42.42/dns-query',
    // Wikimedia
    '185.71.138.138'    => 'https://185.71.138.138/dns-query',
    // Yandex
    '77.88.8.8'         => 'https://77.88.8.8/dns-query',
];

// ── Ping / health-check ─────────────────────────────────────────────────────

if (isset($_GET['ping'])) {
    echo json_encode(['status' => 'ok', 'version' => '2.0', 'curl' => function_exists('curl_init')]);
    exit;
}

// ── Input validation ────────────────────────────────────────────────────────

$domain = strtolower(trim($_REQUEST['domain'] ?? ''));
$type   = strtoupper(trim($_REQUEST['type']   ?? 'A'));
$server = trim($_REQUEST['server'] ?? '');

if ($domain === '' || $server === '') {
    json_error(400, 'Missing required parameters: domain, server');
}

if (!preg_match('/^[a-zA-Z0-9._-]+$/', $domain)) {
    json_error(400, 'Invalid domain format');
}

if (!filter_var($server, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    json_error(400, 'Invalid server IP (IPv4 only)');
}

if (!array_key_exists($type, TYPE_CODES)) {
    json_error(400, 'Unsupported record type. Allowed: ' . implode(', ', array_keys(TYPE_CODES)));
}

// ── Execute query ───────────────────────────────────────────────────────────

$result = dns_query($domain, $type, $server);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// ── Main query orchestrator ────────────────────────────────────────────────

/**
 * Attempt DNS query using UDP → TCP → DoH (cURL) cascade.
 */
function dns_query(string $domain, string $type, string $server): array {
    $type_code = TYPE_CODES[$type];
    $query_id  = random_int(1, 65535);
    $packet    = build_query($domain, $type_code, $query_id);

    // 1 — UDP
    $raw = query_udp($server, $packet);
    if ($raw !== null) {
        return parse_response($raw, $domain, $type, $type_code, $server, $query_id, 'udp');
    }

    // 2 — TCP fallback
    $raw = query_tcp($server, $packet);
    if ($raw !== null) {
        return parse_response($raw, $domain, $type, $type_code, $server, $query_id, 'tcp');
    }

    // 3 — DoH via cURL fallback (only if server has a known DoH endpoint)
    $raw = query_doh_curl($server, $packet);
    if ($raw !== null) {
        return parse_response($raw, $domain, $type, $type_code, $server, $query_id, 'doh');
    }

    return timeout_response($domain, $type, $server);
}

// ── Transport layer implementations ───────────────────────────────────────

/**
 * UDP DNS query (standard, fastest, may be blocked on some hosts).
 */
function query_udp(string $server, string $packet): ?string {
    $errno  = 0;
    $errstr = '';
    $sock   = @fsockopen("udp://{$server}", DNS_PORT, $errno, $errstr, TIMEOUT_SEC);
    if (!$sock) return null;

    stream_set_timeout($sock, TIMEOUT_SEC);

    if (fwrite($sock, $packet) === false) {
        fclose($sock);
        return null;
    }

    $response = fread($sock, MAX_PKT_SIZE);
    fclose($sock);

    return (is_string($response) && strlen($response) >= 12) ? $response : null;
}

/**
 * TCP DNS query (RFC 1035 §4.2.2 — 2-byte message length prefix).
 * More likely to work on shared hosting than UDP.
 */
function query_tcp(string $server, string $packet): ?string {
    $errno  = 0;
    $errstr = '';
    // Plain TCP — no "tcp://" prefix needed for fsockopen
    $sock = @fsockopen($server, DNS_PORT, $errno, $errstr, TIMEOUT_SEC);
    if (!$sock) return null;

    stream_set_timeout($sock, TIMEOUT_SEC);

    // Prefix packet with 2-byte big-endian length
    $written = fwrite($sock, pack('n', strlen($packet)) . $packet);
    if ($written === false) {
        fclose($sock);
        return null;
    }

    // Read 2-byte response length
    $len_bytes = '';
    while (strlen($len_bytes) < 2) {
        $chunk = fread($sock, 2 - strlen($len_bytes));
        if ($chunk === false || $chunk === '') { fclose($sock); return null; }
        $len_bytes .= $chunk;
    }
    $resp_len = unpack('n', $len_bytes)[1];

    // Read full response
    $response = '';
    while (strlen($response) < $resp_len) {
        $chunk = fread($sock, $resp_len - strlen($response));
        if ($chunk === false || $chunk === '') break;
        $response .= $chunk;
    }
    fclose($sock);

    return strlen($response) >= 12 ? $response : null;
}

/**
 * DoH fallback via cURL using RFC 8484 binary DNS-over-HTTPS.
 * Only used if the server has a known DoH endpoint AND cURL is available.
 */
function query_doh_curl(string $server, string $packet): ?string {
    $doh_url = DOH_ENDPOINTS[$server] ?? null;
    if ($doh_url === null || !function_exists('curl_init')) return null;

    $ch = curl_init($doh_url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $packet,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/dns-message',
            'Accept: application/dns-message',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => TIMEOUT_SEC,
        CURLOPT_CONNECTTIMEOUT => TIMEOUT_SEC,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $http_code !== 200 || strlen($response) < 12) {
        return null;
    }
    return $response;
}

// ── DNS packet builder ─────────────────────────────────────────────────────

/**
 * Build a minimal DNS query packet (RFC 1035).
 */
function build_query(string $domain, int $type_code, int $id): string {
    // Header: ID | QR=0 RD=1 | QDCOUNT=1 | others=0
    $header = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0);

    // QNAME: length-prefixed labels, terminated with 0x00
    $qname = '';
    foreach (explode('.', rtrim($domain, '.')) as $label) {
        if ($label === '') continue;
        $qname .= chr(strlen($label)) . $label;
    }
    $qname .= "\x00";

    return $header . $qname . pack('nn', $type_code, 1); // QTYPE + QCLASS IN
}

// ── DNS response parser ────────────────────────────────────────────────────

function parse_response(
    string $data, string $domain, string $type, int $type_code,
    string $server, int $expected_id, string $method
): array {
    if (strlen($data) < 12) return timeout_response($domain, $type, $server);

    $header = unpack('nid/nflags/nqd/nan/nns/nar', substr($data, 0, 12));
    $rcode  = $header['flags'] & 0x000F;

    if ($header['id'] !== $expected_id) {
        return error_response($domain, $type, $server, 'Response ID mismatch');
    }

    if ($rcode !== 0) {
        return make_result($domain, $type, $server, [], $rcode, 0, $method);
    }

    $offset = 12;

    // Skip question section
    for ($i = 0; $i < $header['qd']; $i++) {
        $offset = skip_name($data, $offset);
        $offset += 4; // QTYPE + QCLASS
    }

    // Parse answer records
    $records = [];
    $ttl     = 0;

    for ($i = 0; $i < $header['an']; $i++) {
        if ($offset + 10 > strlen($data)) break;

        [, $offset] = read_name($data, $offset);
        $rr = unpack('ntype/nclass/Nttl/nrdlen', substr($data, $offset, 10));
        $offset += 10;

        if ($offset + $rr['rdlen'] > strlen($data)) break;

        if ($rr['type'] === $type_code) {
            $parsed = parse_rdata($data, $offset, $rr['rdlen'], $rr['type']);
            if ($parsed !== null) {
                $records[] = $parsed;
                if ($ttl === 0) $ttl = $rr['ttl'];
            }
        }

        $offset += $rr['rdlen'];
    }

    return make_result($domain, $type, $server, $records, 0, $ttl, $method);
}

// ── RDATA parsers ──────────────────────────────────────────────────────────

function parse_rdata(string $data, int $offset, int $length, int $type): ?string {
    switch ($type) {
        case 1:  // A
            return ($length === 4) ? (inet_ntop(substr($data, $offset, 4)) ?: null) : null;

        case 28: // AAAA
            return ($length === 16) ? (inet_ntop(substr($data, $offset, 16)) ?: null) : null;

        case 2:  // NS
        case 5:  // CNAME
        case 12: // PTR
            [$name] = read_name($data, $offset);
            return $name !== '' ? $name : null;

        case 15: // MX
            if ($length < 3) return null;
            $pref = unpack('n', substr($data, $offset, 2))[1];
            [$exchange] = read_name($data, $offset + 2);
            return "{$pref} {$exchange}";

        case 16: // TXT
            $result = '';
            $pos    = $offset;
            $end    = $offset + $length;
            while ($pos < $end) {
                $len     = ord($data[$pos++]);
                $result .= substr($data, $pos, $len);
                $pos    += $len;
            }
            return $result !== '' ? $result : null;

        case 6: // SOA
            [$mname, $next] = read_name($data, $offset);
            [$rname, $after_rname] = read_name($data, $next);
            if ($after_rname + 20 > strlen($data)) return "{$mname} {$rname}";
            $f = unpack('Nserial/Nrefresh/Nretry/Nexpire/Nmin', substr($data, $after_rname, 20));
            return "{$mname} {$rname} {$f['serial']} {$f['refresh']} {$f['retry']} {$f['expire']} {$f['min']}";

        case 257: // CAA
            if ($length < 2) return null;
            $flags   = ord($data[$offset]);
            $tag_len = ord($data[$offset + 1]);
            $tag     = substr($data, $offset + 2, $tag_len);
            $value   = substr($data, $offset + 2 + $tag_len, $length - 2 - $tag_len);
            return "{$flags} {$tag} \"{$value}\"";

        case 43: // DS
            if ($length < 4) return null;
            $key_tag  = unpack('n', substr($data, $offset, 2))[1];
            $alg      = ord($data[$offset + 2]);
            $dig_type = ord($data[$offset + 3]);
            $digest   = strtoupper(bin2hex(substr($data, $offset + 4, $length - 4)));
            return "{$key_tag} {$alg} {$dig_type} {$digest}";

        case 48: // DNSKEY
            if ($length < 4) return null;
            $flags = unpack('n', substr($data, $offset, 2))[1];
            $proto = ord($data[$offset + 2]);
            $alg   = ord($data[$offset + 3]);
            $key   = base64_encode(substr($data, $offset + 4, $length - 4));
            return "{$flags} {$proto} {$alg} {$key}";

        default:
            return strtoupper(bin2hex(substr($data, $offset, $length)));
    }
}

// ── Name helpers ───────────────────────────────────────────────────────────

/**
 * Read a (possibly pointer-compressed) DNS name.
 * Returns [name_string, offset_after_name].
 */
function read_name(string $data, int $offset): array {
    $labels     = [];
    $end_offset = -1;
    $jumps      = 0;

    while ($offset < strlen($data) && $jumps < 20) {
        $len = ord($data[$offset]);

        if ($len === 0) { $offset++; break; }

        if (($len & 0xC0) === 0xC0) {         // pointer
            if ($end_offset === -1) $end_offset = $offset + 2;
            $offset = (($len & 0x3F) << 8) | ord($data[$offset + 1]);
            $jumps++;
            continue;
        }

        $offset++;
        $labels[] = substr($data, $offset, $len);
        $offset  += $len;
    }

    if ($end_offset === -1) $end_offset = $offset;
    return [implode('.', $labels), $end_offset];
}

function skip_name(string $data, int $offset): int {
    while ($offset < strlen($data)) {
        $len = ord($data[$offset]);
        if ($len === 0)              return $offset + 1;
        if (($len & 0xC0) === 0xC0) return $offset + 2;
        $offset += $len + 1;
    }
    return $offset;
}

// ── Response builders ──────────────────────────────────────────────────────

function make_result(
    string $domain, string $type, string $server,
    array $records, int $rcode, int $ttl, string $method
): array {
    return [
        'status'  => 'ok',
        'domain'  => $domain,
        'type'    => $type,
        'server'  => $server,
        'records' => $records,
        'rcode'   => $rcode,
        'ttl'     => $ttl,
        'method'  => $method,   // 'udp' | 'tcp' | 'doh'
    ];
}

function error_response(string $domain, string $type, string $server, string $msg): array {
    return ['status' => 'error', 'domain' => $domain, 'type' => $type,
            'server' => $server, 'records' => [], 'rcode' => -1, 'error' => $msg];
}

function timeout_response(string $domain, string $type, string $server): array {
    return ['status' => 'ok', 'domain' => $domain, 'type' => $type,
            'server' => $server, 'records' => [], 'rcode' => -1, 'error' => 'no transport available'];
}

function json_error(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}
