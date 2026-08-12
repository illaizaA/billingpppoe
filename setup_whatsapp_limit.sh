#!/bin/bash
set -e

DB_HOST="${BILLING_SALAM_DB_HOST:-localhost}"
DB_USER="${BILLING_SALAM_DB_USER:-billing}"
DB_PASS="${BILLING_SALAM_DB_PASS:-s0t0kudus}"
DB_NAME="${BILLING_SALAM_DB_NAME:-billing_salam}"

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<'SQL'
CREATE TABLE IF NOT EXISTS message_send_log (
    id INT NOT NULL AUTO_INCREMENT,
    wilayah VARCHAR(50) NOT NULL DEFAULT 'salam',
    id_pelanggan VARCHAR(100) DEFAULT NULL,
    message_type VARCHAR(50) DEFAULT 'tagihan',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wilayah_date (wilayah, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL

echo "Tabel pembatasan WhatsApp Billing Salam siap digunakan."
