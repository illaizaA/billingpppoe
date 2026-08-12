<?php
/**
 * Koneksi aplikasi Billing multiwilayah.
 * Nilai dapat dioverride melalui environment variable tanpa mengubah source code.
 */
$host = 'localhost';  
$username = 'root';  
$password = '';       
$dbname = '1pppoebilling';

$koneksi = new mysqli($host, $username, $password, $dbname);

if ($koneksi->connect_error) {
    die('Koneksi database Billing gagal: ' . $koneksi->connect_error);
}

$koneksi->set_charset('utf8mb4');
?>

