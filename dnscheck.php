<?php

// Fungsi untuk membaca daftar IP DNS dan ISP dari file teks
function load_dns_servers_from_file($filename) {
    $dns_servers = [];
    
    // Baca file dan proses tiap baris
    $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Pisahkan IP dan ISP menggunakan tanda "-"
        list($ip, $isp_name) = explode(' - ', $line);
        $dns_servers[$ip] = $isp_name;
    }
    
    return $dns_servers;
}

// Memuat daftar IP DNS dan ISP dari file teks
$filename = 'dns_servers.txt';  // Gantilah dengan path yang sesuai jika file berada di lokasi lain
$dns_servers = load_dns_servers_from_file($filename);

// Nama domain yang ingin dicek
$domain = "example.com";

// Fungsi untuk mengecek DNS menggunakan server tertentu dan mendapatkan tipe record
function check_dns_propagation($domain, $dns_server) {
    // Cek rekaman DNS A untuk domain
    $result = dns_get_record($domain, DNS_A);
    
    // Periksa jika ada hasilnya
    if ($result) {
        $ips = array_map(function($r) { return $r['ip']; }, $result);
        $record_type = "A"; // Tipe record yang digunakan (A record untuk IPv4)
        return ["record_type" => $record_type, "ips" => implode(", ", $ips)];
    } else {
        return ["record_type" => "N/A", "ips" => "No record found"]; // Jika tidak ada hasil
    }
}

// Output Tabel dengan format yang diperbarui
echo "<table border='1' cellpadding='10' cellspacing='0' style='border-collapse: collapse;'>";
echo "<tr><th>No</th><th>DNS Server IP</th><th>ISP Name</th><th>Domain</th><th>Record Type</th><th>Propagated</th></tr>";

$no = 1;
foreach ($dns_servers as $server_ip => $isp_name) {
    $result = check_dns_propagation($domain, $server_ip);
    $record_type = $result["record_type"];
    $propagated = $result["ips"];
    echo "<tr><td>$no</td><td>$server_ip</td><td>$isp_name</td><td>$domain</td><td>$record_type</td><td>$propagated</td></tr>";
    $no++;
}

echo "</table>";

?>
