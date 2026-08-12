-- ============================================================
-- BILLING SALAM - PROFIL PELANGGAN + AUTO MAPPING PPPoE
-- ============================================================
-- Pilih database Billing yang benar di phpMyAdmin sebelum import.
-- File ini TIDAK memakai USE dan TIDAK mengakses information_schema.
-- Website/database PPPoE tidak diubah.
-- ============================================================

CREATE TABLE IF NOT EXISTS `pelanggan_detail_salam` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pelanggan_id` INT UNSIGNED NOT NULL,
    `nama_ktp` VARCHAR(150) DEFAULT NULL,
    `nik` VARCHAR(32) DEFAULT NULL,
    `koordinat_x` DECIMAL(12,8) DEFAULT NULL COMMENT 'Kompatibilitas lama; Billing final tidak menulis koordinat',
    `koordinat_y` DECIMAL(12,8) DEFAULT NULL COMMENT 'Kompatibilitas lama; Billing final tidak menulis koordinat',
    `foto_rumah` VARCHAR(255) DEFAULT NULL COMMENT 'Path foto rumah yang di-upload dari Billing',
    `pppoe_user` VARCHAR(120) DEFAULT NULL COMMENT 'Kompatibilitas lama; admin Billing tidak mengisi username PPPoE',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_pelanggan_detail_pelanggan` (`pelanggan_id`),
    CONSTRAINT `fk_pelanggan_detail_salam_pelanggan`
        FOREIGN KEY (`pelanggan_id`)
        REFERENCES `pelanggan_salam` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pelanggan_pppoe_mapping` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pelanggan_id` INT UNSIGNED NOT NULL,
    `pppoe_id` VARCHAR(120) NOT NULL COMMENT 'ID teknis PPPoE yang disimpan hanya di Billing',
    `match_method` VARCHAR(30) NOT NULL DEFAULT 'auto_name' COMMENT 'auto_id / auto_name',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_mapping_pelanggan` (`pelanggan_id`),
    UNIQUE KEY `uk_mapping_pppoe_id` (`pppoe_id`),
    CONSTRAINT `fk_mapping_pelanggan_salam`
        FOREIGN KEY (`pelanggan_id`)
        REFERENCES `pelanggan_salam` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mapping diisi otomatis oleh Billing ketika ID atau nama cocok pasti.
-- Jika tidak ada kecocokan pasti, sistem tidak membuat mapping/dummy.
