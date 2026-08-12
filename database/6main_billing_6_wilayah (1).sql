-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jul 31, 2026 at 03:14 AM
-- Server version: 8.4.3
-- PHP Version: 8.3.16

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `6main_billing_6_wilayah`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_reset_tagihan_bulanan_salam` ()   BEGIN     DECLARE EXIT HANDLER FOR SQLEXCEPTION     BEGIN         ROLLBACK;         RESIGNAL;     END;      START TRANSACTION;      /* Simpan kondisi terakhir periode lama ke histori */     INSERT INTO tagihan_salam (         pelanggan_id,         periode,         id_pelanggan_snapshot,         nama_snapshot,         alamat_snapshot,         paket_snapshot,         tarif_snapshot,         nominal_tagihan,         status_bayar,         tanggal_jatuh_tempo,         tanggal_bayar,         nominal_dibayar,         nomor_invoice     )     SELECT         p.id,         DATE_FORMAT(p.waktu, '%Y-%m-01'),         COALESCE(p.id_pelanggan, ''),         p.nama,         p.alamat,         p.paket,         COALESCE(p.tarif_langganan, 0),         COALESCE(NULLIF(p.tarif_langganan, 0), p.tagihan, 0),         p.status_bayar,         p.langganan_selesai,         p.tanggal_bayar,         p.nominal_dibayar,         p.nomor_invoice     FROM pelanggan_salam p     WHERE p.status_pelanggan = 'Aktif'       AND p.waktu IS NOT NULL       AND p.waktu < DATE_FORMAT(CURDATE(), '%Y-%m-01')      ON DUPLICATE KEY UPDATE         id_pelanggan_snapshot = VALUES(id_pelanggan_snapshot),         nama_snapshot = VALUES(nama_snapshot),         alamat_snapshot = VALUES(alamat_snapshot),         paket_snapshot = VALUES(paket_snapshot),         tarif_snapshot = VALUES(tarif_snapshot),         nominal_tagihan = VALUES(nominal_tagihan),         status_bayar = VALUES(status_bayar),         tanggal_jatuh_tempo = VALUES(tanggal_jatuh_tempo),         tanggal_bayar = VALUES(tanggal_bayar),         nominal_dibayar = VALUES(nominal_dibayar),         nomor_invoice = VALUES(nomor_invoice);      /* Reset tabel utama ke bulan berjalan */     UPDATE pelanggan_salam     SET         waktu = DATE_FORMAT(CURDATE(), '%Y-%m-01'),         langganan_selesai = LAST_DAY(CURDATE()),         status_bayar = 'Belum Lunas',         tagihan = tarif_langganan,         tanggal_bayar = NULL,         nominal_dibayar = NULL,         nomor_invoice = CONCAT(             'SLM/INV/',             LPAD(id, 5, '0'),             '/',             DATE_FORMAT(CURDATE(), '%m/%Y')         )     WHERE status_pelanggan = 'Aktif'       AND (           waktu IS NULL           OR waktu < DATE_FORMAT(CURDATE(), '%Y-%m-01')       );      COMMIT;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `message_send_log`
--

CREATE TABLE `message_send_log` (
  `id` int NOT NULL,
  `wilayah` varchar(50) NOT NULL,
  `id_pelanggan` varchar(100) DEFAULT NULL,
  `message_type` varchar(50) DEFAULT 'tagihan',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

--
-- Table structure for table `pelanggan_salam`
--

CREATE TABLE `pelanggan_salam` (
  `id` int UNSIGNED NOT NULL,
  `id_pelanggan` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `kode_pelanggan` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nomor_pelanggan` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Nomor WhatsApp pelanggan',
  `alamat` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paket` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Bebas diisi admin',
  `tarif_langganan` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'Nominal dasar pelanggan untuk bulan berikutnya',
  `tagihan` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'Tagihan pada periode berjalan',
  `status_bayar` enum('Lunas','Belum Lunas') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Belum Lunas',
  `status_pelanggan` enum('Aktif','Tidak Aktif') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Aktif',
  `waktu` date NOT NULL COMMENT 'Tanggal awal periode tagihan',
  `langganan_selesai` date NOT NULL COMMENT 'Akhir periode tagihan / masa aktif sampai',
  `tanggal_daftar` date DEFAULT NULL,
  `tanggal_bayar` date DEFAULT NULL,
  `nominal_dibayar` decimal(12,2) DEFAULT NULL,
  `nomor_invoice` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `pelanggan_salam`
--

INSERT INTO `pelanggan_salam` (`id`, `id_pelanggan`, `kode_pelanggan`, `nama`, `nomor_pelanggan`, `alamat`, `paket`, `tarif_langganan`, `tagihan`, `status_bayar`, `status_pelanggan`, `waktu`, `langganan_selesai`, `tanggal_daftar`, `tanggal_bayar`, `nominal_dibayar`, `nomor_invoice`, `created_at`, `updated_at`) VALUES
(51, 'SLM90001', 'SIM-SLM-001', 'Rizky Pratama', '6282338635691', 'Baran', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', 'Tidak Aktif', '2026-07-01', '2026-07-31', '2026-07-03', NULL, NULL, 'SLM/INV/00051/07/2026', '2026-07-06 07:53:10', '2026-07-27 01:30:31'),
(52, 'SLM90002', 'SIM-SLM-002', 'Dinda Maharani', '6282338635691', 'Pengkok', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', 'Aktif', '2026-08-01', '2026-08-31', '2026-06-30', NULL, NULL, 'SLM/INV/90002/07/2026', '2026-07-06 07:53:10', '2026-07-24 08:04:15'),
(53, 'SLM90003', 'SIM-SLM-003', 'Bagas Saputra', '6282338635691', 'Gunung Manuk', 'CLEON Family', 150000.00, 0.00, 'Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-27', '2026-07-21', 150000.00, 'SLM/INV/90003/07/2026', '2026-07-06 07:53:10', '2026-07-21 01:47:11'),
(54, 'SLM90004', 'SIM-SLM-004', 'Nabila Putriii', '082338635691', 'Waduk', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-24', NULL, NULL, 'SLM/INV/90004/07/2026', '2026-07-06 07:53:10', '2026-07-23 04:33:49'),
(104, 'SLM9000156', 'SIM-SLM-006', 'sudioarto', '6282338635691', 'Ngasem Ayu', 'CLEON Standart', 120000.00, 0.00, 'Lunas', 'Aktif', '2026-08-06', '2026-08-31', '2026-07-10', '2026-07-10', 120000.00, 'SLM/INV/00104/07/2026', '2026-07-10 04:19:50', '2026-07-10 04:21:33'),
(105, 'SLM01001', 'SIM-SLM-001', 'Pelanggan Dummy 01', '628131230001', 'Baran', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-02', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(106, 'SLM01002', 'SIM-SLM-002', 'Pelanggan Dummy 02', '628131230002', 'Pengkok', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-03', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(107, 'SLM01003', 'SIM-SLM-003', 'Pelanggan Dummy 03', '628131230003', 'Gunung Manuk', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-04', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-23 04:32:06'),
(108, 'SLM01004', 'SIM-SLM-004', 'Pelanggan Dummy 04', '628131230004', 'Waduk', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-05', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(109, 'SLM01005', 'SIM-SLM-005', 'Pelanggan Dummy 05', '628131230005', 'Ngasem Ayu', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-06', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(110, 'SLM01006', 'SIM-SLM-006', 'Pelanggan Dummy 06', '628131230006', 'Baran', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-07', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(111, 'SLM01007', 'SIM-SLM-007', 'Pelanggan Dummy 07', '628131230007', 'Pengkok', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-08', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(112, 'SLM01008', 'SIM-SLM-008', 'Pelanggan Dummy 08', '628131230008', 'Gunung Manuk', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-09', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(113, 'SLM01009', 'SIM-SLM-009', 'Pelanggan Dummy 09', '628131230009', 'Waduk', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-10', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(114, 'SLM01010', 'SIM-SLM-010', 'Pelanggan Dummy 10', '628131230010', 'Ngasem Ayu', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-11', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(115, 'SLM01011', 'SIM-SLM-011', 'Pelanggan Dummy 11', '628131230011', 'Baran', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-12', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(116, 'SLM01012', 'SIM-SLM-012', 'Pelanggan Dummy 12', '628131230012', 'Pengkok', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-13', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(117, 'SLM01013', 'SIM-SLM-013', 'Pelanggan Dummy 13', '628131230013', 'Gunung Manuk', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-14', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(118, 'SLM01014', 'SIM-SLM-014', 'Pelanggan Dummy 14', '628131230014', 'Waduk', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-15', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(119, 'SLM01015', 'SIM-SLM-015', 'Pelanggan Dummy 15', '628131230015', 'Ngasem Ayu', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-16', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(120, 'SLM01016', 'SIM-SLM-016', 'Pelanggan Dummy 16', '628131230016', 'Baran', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-17', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(121, 'SLM01017', 'SIM-SLM-017', 'Pelanggan Dummy 17', '628131230017', 'Pengkok', 'CLEON Standart', 100000.00, 0.00, 'Belum Lunas', 'Tidak Aktif', '2026-07-01', '2026-07-31', '2026-05-18', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(122, 'SLM01018', 'SIM-SLM-018', 'Pelanggan Dummy 18', '628131230018', 'Gunung Manuk', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-19', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(123, 'SLM01019', 'SIM-SLM-019', 'Pelanggan Dummy 19', '628131230019', 'Waduk', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-20', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(124, 'SLM01020', 'SIM-SLM-020', 'Pelanggan Dummy 20', '628131230020', 'Ngasem Ayu', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-21', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(125, 'SLM01021', 'SIM-SLM-021', 'Pelanggan Dummy 21', '628131230021', 'Baran', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-22', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(126, 'SLM01022', 'SIM-SLM-022', 'Pelanggan Dummy 22', '628131230022', 'Pengkok', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-23', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(127, 'SLM01023', 'SIM-SLM-023', 'Pelanggan Dummy 23', '628131230023', 'Gunung Manuk', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-24', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(128, 'SLM01024', 'SIM-SLM-024', 'Pelanggan Dummy 24', '628131230024', 'Waduk', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-25', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(129, 'SLM01025', 'SIM-SLM-025', 'Pelanggan Dummy 25', '628131230025', 'Ngasem Ayu', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-05-26', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(130, 'SLM01026', 'SIM-SLM-026', 'Pelanggan Dummy 26', '628131230026', 'Baran', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-27', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(131, 'SLM01027', 'SIM-SLM-027', 'Pelanggan Dummy 27', '628131230027', 'Pengkok', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-28', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(132, 'SLM01028', 'SIM-SLM-028', 'Pelanggan Dummy 28', '628131230028', 'Gunung Manuk', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-01', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(133, 'SLM01029', 'SIM-SLM-029', 'Pelanggan Dummy 29', '628131230029', 'Waduk', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-02', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(134, 'SLM01030', 'SIM-SLM-030', 'Pelanggan Dummy 30', '628131230030', 'Ngasem Ayu', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-03', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(135, 'SLM01031', 'SIM-SLM-031', 'Pelanggan Dummy 31', '628131230031', 'Baran', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-04', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(136, 'SLM01032', 'SIM-SLM-032', 'Pelanggan Dummy 32', '628131230032', 'Pengkok', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-05', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(137, 'SLM01033', 'SIM-SLM-033', 'Pelanggan Dummy 33', '628131230033', 'Gunung Manuk', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-06', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(138, 'SLM01034', 'SIM-SLM-034', 'Pelanggan Dummy 34', '628131230034', 'Waduk', 'CLEON Basic', 125000.00, 0.00, 'Belum Lunas', 'Tidak Aktif', '2026-07-01', '2026-07-31', '2026-06-07', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(139, 'SLM01035', 'SIM-SLM-035', 'Pelanggan Dummy 35', '628131230035', 'Ngasem Ayu', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-08', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(140, 'SLM01036', 'SIM-SLM-036', 'Pelanggan Dummy 36', '628131230036', 'Baran', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-09', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(141, 'SLM01037', 'SIM-SLM-037', 'Pelanggan Dummy 37', '628131230037', 'Pengkok', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-10', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(142, 'SLM01038', 'SIM-SLM-038', 'Pelanggan Dummy 38', '628131230038', 'Gunung Manuk', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-11', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(143, 'SLM01039', 'SIM-SLM-039', 'Pelanggan Dummy 39', '628131230039', 'Waduk', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-12', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(144, 'SLM01040', 'SIM-SLM-040', 'Pelanggan Dummy 40', '628131230040', 'Ngasem Ayu', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-13', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(145, 'SLM01041', 'SIM-SLM-041', 'Pelanggan Dummy 41', '628131230041', 'Baran', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-14', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(146, 'SLM01042', 'SIM-SLM-042', 'Pelanggan Dummy 42', '628131230042', 'Pengkok', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-15', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(147, 'SLM01043', 'SIM-SLM-043', 'Pelanggan Dummy 43', '628131230043', 'Gunung Manuk', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-16', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(148, 'SLM01044', 'SIM-SLM-044', 'Pelanggan Dummy 44', '628131230044', 'Waduk', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-17', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(149, 'SLM01045', 'SIM-SLM-045', 'Pelanggan Dummy 45', '628131230045', 'Ngasem Ayu', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-18', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(150, 'SLM01046', 'SIM-SLM-046', 'Pelanggan Dummy 46', '628131230046', 'Baran', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-19', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(151, 'SLM01047', 'SIM-SLM-047', 'Pelanggan Dummy 47', '628131230047', 'Pengkok', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-20', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(152, 'SLM01048', 'SIM-SLM-048', 'Pelanggan Dummy 48', '628131230048', 'Gunung Manuk', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-21', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(153, 'SLM01049', 'SIM-SLM-049', 'Pelanggan Dummy 49', '628131230049', 'Waduk', 'CLEON Standart', 100000.00, 0.00, 'Belum Lunas', 'Tidak Aktif', '2026-07-01', '2026-07-31', '2026-06-22', NULL, NULL, NULL, '2026-07-14 07:38:53', '2026-07-14 07:38:53'),
(154, 'SLM01050', 'SIM-SLM-050', 'Pelanggan Dummy 50', '628131230050', 'Ngasem Ayu', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-06-23', NULL, NULL, 'SLM/INV/00154/07/2026', '2026-07-14 07:38:53', '2026-07-16 04:19:41'),
(161, 'SLM-00161', 'SIM-SLM-009', 'Arga Hartigan', '085876589876', 'Blitar', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-07-16', NULL, NULL, 'SLM/INV/00161/07/2026', '2026-07-16 06:51:25', '2026-07-16 06:51:25'),
(162, 'SLM-00162', 'SIM-SLM-005', 'Farida Nurhan', '085675434578', 'Gunung Manuk', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-07-16', NULL, NULL, 'SLM/INV/00162/07/2026', '2026-07-16 07:40:16', '2026-07-16 07:40:16'),
(164, 'BRN10', 'BRN10', 'Dinda Maharani', '6282338635691', 'BARAN', 'CLEON Family', 10000.00, 0.00, 'Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-07-28', '2026-07-28', 10000.00, 'BRN/INV/00164/07/2026', '2026-07-28 04:46:21', '2026-07-28 04:46:50'),
(165, 'BRN13', 'BRN1345', 'HASIL', '6282338635691', 'BARAN', 'CLEON Plus', 100000.00, 100000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-07-28', NULL, NULL, 'BRN/INV/00165/07/2026', '2026-07-28 04:50:34', '2026-07-28 04:50:34'),
(166, 'SLM-00060', 'SIM-SLM-11009978', 'ijat', '6282338635691', 'SALAM', 'CLEON Basic', 100000.00, 0.00, 'Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-07-29', '2026-07-29', 100000.00, 'SLM/INV/00166/07/2026', '2026-07-29 03:43:15', '2026-07-29 03:44:27'),
(167, 'BRN-00167', 'BRN10', 'Kaluna Putri', '085850827586', 'BARAN', 'CLEON premium', 300000.00, 300000.00, 'Belum Lunas', 'Aktif', '2026-07-01', '2026-07-31', '2026-07-29', NULL, NULL, 'BRN/INV/00167/07/2026', '2026-07-29 04:37:24', '2026-07-29 04:37:24');

--
-- Triggers `pelanggan_salam`
--
DELIMITER $$
CREATE TRIGGER `trg_bi_pelanggan_salam` BEFORE INSERT ON `pelanggan_salam` FOR EACH ROW BEGIN
  SET NEW.tanggal_daftar = COALESCE(NEW.tanggal_daftar, CURDATE());
  SET NEW.waktu = DATE_FORMAT(CURDATE(), '%Y-%m-01');
  SET NEW.langganan_selesai = LAST_DAY(CURDATE());

  IF NEW.tarif_langganan IS NULL OR NEW.tarif_langganan = 0 THEN
    SET NEW.tarif_langganan = COALESCE(NEW.tagihan, 0);
  END IF;

  IF NEW.status_pelanggan = 'Aktif' THEN
    SET NEW.status_bayar = 'Belum Lunas';
    SET NEW.tagihan = NEW.tarif_langganan;
  ELSE
    SET NEW.tagihan = 0;
  END IF;

  SET NEW.tanggal_bayar = NULL;
  SET NEW.nominal_dibayar = NULL;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `tagihan_salam`
--

CREATE TABLE `tagihan_salam` (
  `id` bigint UNSIGNED NOT NULL,
  `pelanggan_id` int UNSIGNED NOT NULL COMMENT 'Mengacu secara logis ke pelanggan_salam.id',
  `periode` date NOT NULL COMMENT 'Tanggal awal periode, contoh 2026-07-01',
  `id_pelanggan_snapshot` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `nama_snapshot` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `alamat_snapshot` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paket_snapshot` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tarif_snapshot` decimal(12,2) NOT NULL DEFAULT '0.00',
  `nominal_tagihan` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status_bayar` enum('Lunas','Belum Lunas') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Belum Lunas',
  `tanggal_jatuh_tempo` date NOT NULL,
  `tanggal_bayar` date DEFAULT NULL,
  `nominal_dibayar` decimal(12,2) DEFAULT NULL,
  `nomor_invoice` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tagihan_salam`
--

INSERT INTO `tagihan_salam` (`id`, `pelanggan_id`, `periode`, `id_pelanggan_snapshot`, `nama_snapshot`, `alamat_snapshot`, `paket_snapshot`, `tarif_snapshot`, `nominal_tagihan`, `status_bayar`, `tanggal_jatuh_tempo`, `tanggal_bayar`, `nominal_dibayar`, `nomor_invoice`, `created_at`, `updated_at`) VALUES
(1, 51, '2026-07-01', 'SLM90001', 'Rizky Pratama', 'Baran', 'CLEON Standart', 100000.00, 100000.00, 'Lunas', '2026-07-31', '2026-07-14', 100000.00, 'SLM/INV/00051/07/2026', '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(2, 52, '2026-08-01', 'SLM90002', 'Dinda Maharani', 'Pengkok', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', '2026-08-31', NULL, NULL, 'SLM/INV/90002/07/2026', '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(3, 53, '2026-07-01', 'SLM90003', 'Bagas Saputra', 'Gunung Manuk', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, 'SLM/INV/90003/07/2026', '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(4, 54, '2026-07-01', 'SLM90004', 'Nabila Putri', 'Waduk', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, 'SLM/INV/90004/07/2026', '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(5, 104, '2026-08-01', 'SLM9000156', 'sudioarto', 'Ngasem Ayu', 'CLEON Standart', 120000.00, 120000.00, 'Lunas', '2026-08-31', '2026-07-10', 120000.00, 'SLM/INV/00104/07/2026', '2026-07-15 08:00:23', '2026-07-15 09:08:03'),
(6, 105, '2026-07-01', 'SLM01001', 'Pelanggan Dummy 01', 'Baran', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(7, 106, '2026-07-01', 'SLM01002', 'Pelanggan Dummy 02', 'Pengkok', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(8, 107, '2026-07-01', 'SLM01003', 'Pelanggan Dummy 03', 'Gunung Manuk', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(9, 108, '2026-07-01', 'SLM01004', 'Pelanggan Dummy 04', 'Waduk', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(10, 109, '2026-07-01', 'SLM01005', 'Pelanggan Dummy 05', 'Ngasem Ayu', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(11, 110, '2026-07-01', 'SLM01006', 'Pelanggan Dummy 06', 'Baran', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(12, 111, '2026-07-01', 'SLM01007', 'Pelanggan Dummy 07', 'Pengkok', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(13, 112, '2026-07-01', 'SLM01008', 'Pelanggan Dummy 08', 'Gunung Manuk', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(14, 113, '2026-07-01', 'SLM01009', 'Pelanggan Dummy 09', 'Waduk', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(15, 114, '2026-07-01', 'SLM01010', 'Pelanggan Dummy 10', 'Ngasem Ayu', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(16, 115, '2026-07-01', 'SLM01011', 'Pelanggan Dummy 11', 'Baran', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(17, 116, '2026-07-01', 'SLM01012', 'Pelanggan Dummy 12', 'Pengkok', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(18, 117, '2026-07-01', 'SLM01013', 'Pelanggan Dummy 13', 'Gunung Manuk', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(19, 118, '2026-07-01', 'SLM01014', 'Pelanggan Dummy 14', 'Waduk', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(20, 119, '2026-07-01', 'SLM01015', 'Pelanggan Dummy 15', 'Ngasem Ayu', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(21, 120, '2026-07-01', 'SLM01016', 'Pelanggan Dummy 16', 'Baran', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(22, 121, '2026-07-01', 'SLM01017', 'Pelanggan Dummy 17', 'Pengkok', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(23, 122, '2026-07-01', 'SLM01018', 'Pelanggan Dummy 18', 'Gunung Manuk', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(24, 123, '2026-07-01', 'SLM01019', 'Pelanggan Dummy 19', 'Waduk', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(25, 124, '2026-07-01', 'SLM01020', 'Pelanggan Dummy 20', 'Ngasem Ayu', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(26, 125, '2026-07-01', 'SLM01021', 'Pelanggan Dummy 21', 'Baran', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(27, 126, '2026-07-01', 'SLM01022', 'Pelanggan Dummy 22', 'Pengkok', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(28, 127, '2026-07-01', 'SLM01023', 'Pelanggan Dummy 23', 'Gunung Manuk', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(29, 128, '2026-07-01', 'SLM01024', 'Pelanggan Dummy 24', 'Waduk', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(30, 129, '2026-07-01', 'SLM01025', 'Pelanggan Dummy 25', 'Ngasem Ayu', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(31, 130, '2026-07-01', 'SLM01026', 'Pelanggan Dummy 26', 'Baran', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(32, 131, '2026-07-01', 'SLM01027', 'Pelanggan Dummy 27', 'Pengkok', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(33, 132, '2026-07-01', 'SLM01028', 'Pelanggan Dummy 28', 'Gunung Manuk', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(34, 133, '2026-07-01', 'SLM01029', 'Pelanggan Dummy 29', 'Waduk', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(35, 134, '2026-07-01', 'SLM01030', 'Pelanggan Dummy 30', 'Ngasem Ayu', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(36, 135, '2026-07-01', 'SLM01031', 'Pelanggan Dummy 31', 'Baran', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(37, 136, '2026-07-01', 'SLM01032', 'Pelanggan Dummy 32', 'Pengkok', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(38, 137, '2026-07-01', 'SLM01033', 'Pelanggan Dummy 33', 'Gunung Manuk', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(39, 138, '2026-07-01', 'SLM01034', 'Pelanggan Dummy 34', 'Waduk', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(40, 139, '2026-07-01', 'SLM01035', 'Pelanggan Dummy 35', 'Ngasem Ayu', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(41, 140, '2026-07-01', 'SLM01036', 'Pelanggan Dummy 36', 'Baran', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(42, 141, '2026-07-01', 'SLM01037', 'Pelanggan Dummy 37', 'Pengkok', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(43, 142, '2026-07-01', 'SLM01038', 'Pelanggan Dummy 38', 'Gunung Manuk', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(44, 143, '2026-07-01', 'SLM01039', 'Pelanggan Dummy 39', 'Waduk', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(45, 144, '2026-07-01', 'SLM01040', 'Pelanggan Dummy 40', 'Ngasem Ayu', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(46, 145, '2026-07-01', 'SLM01041', 'Pelanggan Dummy 41', 'Baran', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(47, 146, '2026-07-01', 'SLM01042', 'Pelanggan Dummy 42', 'Pengkok', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(48, 147, '2026-07-01', 'SLM01043', 'Pelanggan Dummy 43', 'Gunung Manuk', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(49, 148, '2026-07-01', 'SLM01044', 'Pelanggan Dummy 44', 'Waduk', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(50, 149, '2026-07-01', 'SLM01045', 'Pelanggan Dummy 45', 'Ngasem Ayu', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(51, 150, '2026-07-01', 'SLM01046', 'Pelanggan Dummy 46', 'Baran', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(52, 151, '2026-07-01', 'SLM01047', 'Pelanggan Dummy 47', 'Pengkok', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(53, 152, '2026-07-01', 'SLM01048', 'Pelanggan Dummy 48', 'Gunung Manuk', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(54, 153, '2026-07-01', 'SLM01049', 'Pelanggan Dummy 49', 'Waduk', 'CLEON Standart', 100000.00, 100000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(55, 154, '2026-07-01', 'SLM01050', 'Pelanggan Dummy 50', 'Ngasem Ayu', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, NULL, '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(56, 53, '2026-05-01', 'SLM90003', 'Bagas Saputra', 'Gunung Manuk', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', '2026-05-31', NULL, NULL, 'SLM/TEST/00053/05/2026', '2026-07-16 03:02:37', '2026-07-16 03:02:37'),
(57, 53, '2026-06-01', 'SLM90003', 'Bagas Saputra', 'Gunung Manuk', 'CLEON Family', 150000.00, 150000.00, 'Lunas', '2026-06-30', '2026-06-15', 150000.00, 'SLM/TEST/00053/06/2026', '2026-07-16 03:08:00', '2026-07-16 03:08:00'),
(58, 154, '2026-06-01', 'SLM01050', 'Pelanggan Dummy 50', 'Ngasem Ayu', 'CLEON Basic', 125000.00, 125000.00, 'Lunas', '2026-06-30', '2026-06-30', 125000.00, 'SLM/TEST/00154/06/2026', '2026-07-16 04:19:41', '2026-07-16 04:19:41');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'admin',
  `wilayah` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `wilayah`) VALUES
(6, 'adminsalam', '$2y$12$OvFsXOqZNNaFEhUq1J32/O7r74t0/LAU8RJnVhKe6dmQWfR3botVq', 'admin', 'SEMUA'),
(7, 'superadminsalam', '$2y$12$OvFsXOqZNNaFEhUq1J32/O7r74t0/LAU8RJnVhKe6dmQWfR3botVq', 'superadmin', 'SEMUA'),
(8, 'adminbarann', '$2y$12$OvFsXOqZNNaFEhUq1J32/O7r74t0/LAU8RJnVhKe6dmQWfR3botVq', 'admin', 'BARAN'),
(9, 'admingunungmanuk', '$2y$12$OvFsXOqZNNaFEhUq1J32/O7r74t0/LAU8RJnVhKe6dmQWfR3botVq', 'admin', 'gunungmanuk'),
(10, 'adminngasemayu', '$2y$12$OvFsXOqZNNaFEhUq1J32/O7r74t0/LAU8RJnVhKe6dmQWfR3botVq', 'admin', 'ngasemayu'),
(11, 'admintrosari', '$2y$12$OvFsXOqZNNaFEhUq1J32/O7r74t0/LAU8RJnVhKe6dmQWfR3botVq', 'admin', 'trosari'),
(12, 'adminwaduk', '$2y$12$OvFsXOqZNNaFEhUq1J32/O7r74t0/LAU8RJnVhKe6dmQWfR3botVq', 'admin', 'waduk'),
(13, 'admin_salam', '$2y$10$GGxRmMZdN4HtrHiiY.Othulqk5di.rsi4Ym0aYz75s.SucgHR.Gie', 'admin', 'SALAM');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `message_send_log`
--
ALTER TABLE `message_send_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wilayah_date` (`wilayah`,`created_at`);

--
-- Indexes for table `pelanggan_salam`
--
ALTER TABLE `pelanggan_salam`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_salam_status_bayar` (`status_bayar`),
  ADD KEY `idx_salam_status_pelanggan` (`status_pelanggan`),
  ADD KEY `idx_salam_periode` (`waktu`),
  ADD KEY `idx_salam_nama` (`nama`);

--
-- Indexes for table `tagihan_salam`
--
ALTER TABLE `tagihan_salam`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_tagihan_salam_pelanggan_periode` (`pelanggan_id`,`periode`),
  ADD KEY `idx_tagihan_salam_periode` (`periode`),
  ADD KEY `idx_tagihan_salam_status` (`status_bayar`),
  ADD KEY `idx_tagihan_salam_invoice` (`nomor_invoice`),
  ADD KEY `idx_tagihan_salam_alamat` (`alamat_snapshot`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `message_send_log`
--
ALTER TABLE `message_send_log`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `pelanggan_salam`
--
ALTER TABLE `pelanggan_salam`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=168;

--
-- AUTO_INCREMENT for table `tagihan_salam`
--
ALTER TABLE `tagihan_salam`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=59;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

DELIMITER $$
--
-- Events
--
CREATE DEFINER=`root`@`localhost` EVENT `evt_reset_tagihan_salam` ON SCHEDULE EVERY 1 DAY STARTS '2026-07-06 14:41:40' ON COMPLETION PRESERVE ENABLE DO CALL `sp_reset_tagihan_bulanan_salam`()$$

DELIMITER ;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
