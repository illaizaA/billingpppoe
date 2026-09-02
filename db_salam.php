<?php
date_default_timezone_set('Asia/Jakarta');
/**
 * Koneksi aplikasi Billing multiwilayah.
 * Nilai dapat dioverride melalui environment variable tanpa mengubah source code.
 */
$host = 'localhost';  
$username = 'root';  
$password = '';       
$dbname = 'pppoebillingdelpoy';

$koneksi = new mysqli($host, $username, $password, $dbname);

if ($koneksi->connect_error) {
    die('Koneksi database Billing gagal: ' . $koneksi->connect_error);
}

$koneksi->set_charset('utf8mb4');
$koneksi->query("SET time_zone = '+07:00'");

// Pastikan tagihan bulan berjalan tersedia pada akses pertama tanggal 1,
// walaupun MySQL Event Scheduler sedang mati.
require_once __DIR__ . '/tagihan_bulanan_otomatis.php';
salamPastikanTagihanBulanBerjalan($koneksi);
?>

