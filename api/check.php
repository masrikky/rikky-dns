<?php
/**
 * DNS Propagation Checker — Per-Resolver Query API
 *
 * Endpoint : GET/POST /api/check.php
 * Params   : domain (string), type (string), server (IPv4)
 * Returns  : JSON { status, domain, type, server, records[], rcode, ttl }
 *
 * Uses fsockopen UDP — compatible with shared PHP hosting.
 * No special extensions required.
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

// ── Ping / health-check ─────────────────────────────────────────────────────

if (isset($_GET['ping'])) {
    echo json_encode(['status' => 'ok', 'version' => '1.0']);
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

// ── Functions ───────────────────────────────────────────────────────────────

/**
 * Perform a real UDP DNS query against the given resolver.
 */
function dns_query(string $domain, string $type, string $server): array {
    $type_code = TYPE_CODES[$type];
    $query_id  = random_int(1, 65535);
    $packet    = build_query($domain, $type_code, $query_id);

    // Open UDP socket using fsockopen (supported on most shared hosting)
    $errno  = 0;
    $errstr = '';
    $sock   = @fsockopen("udp://{$server}", DNS_PORT, $errno, $errstr, TIMEOUT_SEC);

    if (!$sock) {
        return error_response($domain, $type, $server, "Socket error: {$errstr} ({$errno})");
    }

    stream_set_timeout($sock, TIMEOUT_SEC);

    $written = fwrite($sock, $packet);
    if ($written === false) {
        fclose($sock);
        return error_response($domain, $type, $server, 'Failed to send DNS packet');
    }

    $response = fread($sock, MAX_PKT_SIZE);
    fclose($sock);

    $info = stream_get_meta_data($sock ?? null);
    if ($response === false || strlen($response) < 12) {
        return timeout_response($domain, $type, $server);
    }

    return parse_response($response, $domain, $type, $type_code, $server, $query_id);
}

/**
 * Build a minimal DNS query packet.
 */
function build_query(string $domain, int $type_code, int $id): string {
    // Header: ID | Flags (RD=1) | QDCOUNT=1 | ANCOUNT=0 | NSCOUNT=0 | ARCOUNT=0
    $header = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0);

    // QNAME: length-prefixed labels, terminated with 0x00
    $qname = '';
    foreach (explode('.', rtrim($domain, '.')) as $label) {
        if ($label === '') continue;
        $qname .= chr(strlen($label)) . $label;
    }
    $qname .= "\x00";

    // QTYPE + QCLASS (IN = 1)
    $question = $qname . pack('nn', $type_code, 1);

    return $header . $question;
}

/**
 * Parse a raw DNS response packet.
 */
function parse_response(
    string $data, string $domain, string $type, int $type_code,
    string $server, int $expected_id
): array {
    if (strlen($data) < 12) {
        return timeout_response($domain, $type, $server);
    }

    $header = unpack('nid/nflags/nqd/nan/nns/nar', substr($data, 0, 12));
    $rcode  = $header['flags'] & 0x000F;

    // Verify response ID matches query ID
    if ($header['id'] !== $expected_id) {
        return error_response($domain, $type, $server, 'Response ID mismatch');
    }

    // RCODE != 0 means NXDOMAIN (3), SERVFAIL (2), etc.
    if ($rcode !== 0) {
        return [
            'status'  => 'ok',
            'domain'  => $domain,
            'type'    => $type,
            'server'  => $server,
            'records' => [],
            'rcode'   => $rcode,
        ];
    }

    $offset = 12;

    // Skip question section
    for ($i = 0; $i < $header['qd']; $i++) {
        $offset = skip_name($data, $offset);
        $offset += 4; // QTYPE + QCLASS
    }

    // Parse answer section
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

    return [
        'status'  => 'ok',
        'domain'  => $domain,
        'type'    => $type,
        'server'  => $server,
        'records' => $records,
        'rcode'   => 0,
        'ttl'     => $ttl,
    ];
}

/**
 * Parse RDATA for a given record type.
 */
function parse_rdata(string $data, int $offset, int $length, int $type): ?string {
    switch ($type) {
        case 1: // A
            if ($length !== 4) return null;
            return inet_ntop(substr($data, $offset, 4)) ?: null;

        case 28: // AAAA
            if ($length !== 16) return null;
            return inet_ntop(substr($data, $offset, 16)) ?: null;

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
            [$rname]        = read_name($data, $next);
            if ($next + 20 > strlen($data)) return "{$mname} {$rname}";
            $fields = unpack('Nserial/Nrefresh/Nretry/Nexpire/Nminttl', substr($data, $next, 20));
            return "{$mname} {$rname} {$fields['serial']} {$fields['refresh']} {$fields['retry']} {$fields['expire']} {$fields['minttl']}";

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

/**
 * Read a (possibly compressed) DNS name starting at $offset.
 * Returns [name_string, offset_after_name].
 */
function read_name(string $data, int $offset): array {
    $labels      = [];
    $end_offset  = -1;
    $jumps       = 0;
    $max_jumps   = 20;

    while ($offset < strlen($data) && $jumps < $max_jumps) {
        $len = ord($data[$offset]);

        if ($len === 0) {
            $offset++;
            break;
        }

        // Pointer (DNS compression)
        if (($len & 0xC0) === 0xC0) {
            if ($end_offset === -1) $end_offset = $offset + 2;
            $ptr    = (($len & 0x3F) << 8) | ord($data[$offset + 1]);
            $offset = $ptr;
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

/**
 * Advance $offset past a DNS name (used for skipping question section).
 */
function skip_name(string $data, int $offset): int {
    while ($offset < strlen($data)) {
        $len = ord($data[$offset]);
        if ($len === 0)              return $offset + 1;
        if (($len & 0xC0) === 0xC0) return $offset + 2; // pointer
        $offset += $len + 1;
    }
    return $offset;
}

// ── Helpers ─────────────────────────────────────────────────────────────────

function json_error(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

function error_response(string $domain, string $type, string $server, string $msg): array {
    return ['status' => 'error', 'domain' => $domain, 'type' => $type, 'server' => $server, 'records' => [], 'rcode' => -1, 'error' => $msg];
}

function timeout_response(string $domain, string $type, string $server): array {
    return ['status' => 'ok', 'domain' => $domain, 'type' => $type, 'server' => $server, 'records' => [], 'rcode' => -1, 'error' => 'timeout'];
}
