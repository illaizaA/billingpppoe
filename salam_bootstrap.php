<?php
/*
 * Bootstrap endpoint Billing multiwilayah.
 */
require_once __DIR__ . '/helpers_salam.php';

function salamTableHasColumn(mysqli $koneksi, string $column): bool
{
    $column = $koneksi->real_escape_string($column);
    $result = $koneksi->query("SHOW COLUMNS FROM pelanggan_salam LIKE '{$column}'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function salamDateIsValid(string $value): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}
?>
