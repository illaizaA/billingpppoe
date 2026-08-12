-- ============================================================
-- UPDATE DATABASE BILLING SALAM - MONITORING PPPoE READ ONLY
-- Target database: salam_billing
--
-- Aman terhadap fitur Billing lama:
-- - TIDAK mengubah struktur pelanggan_salam
-- - TIDAK mengubah tagihan_salam
-- - TIDAK mengubah trigger/procedure/event
-- - Hanya menambah 1 tabel detail pelanggan baru
-- ============================================================

USE `salam_billing`;

CREATE TABLE IF NOT EXISTS `pelanggan_detail_salam` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pelanggan_id` INT UNSIGNED NOT NULL,
    `nama_ktp` VARCHAR(150) DEFAULT NULL,
    `nik` VARCHAR(32) DEFAULT NULL,
    `koordinat_x` DECIMAL(12,8) DEFAULT NULL COMMENT 'Longitude / koordinat X',
    `koordinat_y` DECIMAL(12,8) DEFAULT NULL COMMENT 'Latitude / koordinat Y',
    `foto_rumah` VARCHAR(255) DEFAULT NULL COMMENT 'Path file foto rumah pada aplikasi Billing',
    `pppoe_user` VARCHAR(120) DEFAULT NULL COMMENT 'Username PPPoE untuk menghubungkan marker dengan pelanggan Billing',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_pelanggan_detail_pelanggan` (`pelanggan_id`),
    UNIQUE KEY `uk_pelanggan_detail_pppoe_user` (`pppoe_user`),
    CONSTRAINT `fk_pelanggan_detail_salam_pelanggan`
        FOREIGN KEY (`pelanggan_id`) REFERENCES `pelanggan_salam` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pemeriksaan hasil
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pelanggan_detail_salam';
