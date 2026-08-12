-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Aug 12, 2026 at 06:04 AM
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
-- Database: `1pppoebilling`
--

-- --------------------------------------------------------

--
-- Table structure for table `tagihan_salam`
--

CREATE TABLE `tagihan_salam` (
  `id` bigint UNSIGNED NOT NULL,
  `pelanggan_id` int UNSIGNED NOT NULL COMMENT 'Mengacu secara logis ke pelanggan_salam.id',
  `periode` date NOT NULL COMMENT 'Tanggal awal periode, contoh 2026-07-01',
  `id_pelanggan_snapshot` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `nama_snapshot` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alamat_snapshot` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paket_snapshot` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tarif_snapshot` decimal(12,2) NOT NULL DEFAULT '0.00',
  `nominal_tagihan` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status_bayar` enum('Lunas','Belum Lunas') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Belum Lunas',
  `tanggal_jatuh_tempo` date NOT NULL,
  `tanggal_bayar` date DEFAULT NULL,
  `nominal_dibayar` decimal(12,2) DEFAULT NULL,
  `nomor_invoice` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tagihan_salam`
--

INSERT INTO `tagihan_salam` (`id`, `pelanggan_id`, `periode`, `id_pelanggan_snapshot`, `nama_snapshot`, `alamat_snapshot`, `paket_snapshot`, `tarif_snapshot`, `nominal_tagihan`, `status_bayar`, `tanggal_jatuh_tempo`, `tanggal_bayar`, `nominal_dibayar`, `nomor_invoice`, `created_at`, `updated_at`) VALUES
(1, 51, '2026-07-01', 'SLM90001', 'Rizky Pratama', 'Baran', 'CLEON Standart', 100000.00, 100000.00, 'Lunas', '2026-07-31', '2026-07-14', 100000.00, 'SLM/INV/00051/07/2026', '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(2, 52, '2026-08-01', 'SLM90002', 'Dinda Maharani', 'Pengkok', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', '2026-08-31', NULL, NULL, 'SLM/INV/90002/07/2026', '2026-07-15 08:00:23', '2026-07-15 08:00:23'),
(3, 53, '2026-07-01', 'SLM90003', 'Bagas Saputra', 'Gunung Manuk', 'CLEON Family', 150000.00, 150000.00, 'Lunas', '2026-07-31', '2026-07-21', 150000.00, 'SLM/INV/90003/07/2026', '2026-07-15 08:00:23', '2026-08-01 14:41:40'),
(4, 54, '2026-07-01', 'SLM90004', 'Nabila Putriii', 'Waduk', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, 'SLM/INV/90004/07/2026', '2026-07-15 08:00:23', '2026-08-01 14:41:40'),
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
(55, 154, '2026-07-01', 'SLM01050', 'Pelanggan Dummy 50', 'Ngasem Ayu', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, 'SLM/INV/00154/07/2026', '2026-07-15 08:00:23', '2026-08-01 14:41:40'),
(56, 53, '2026-05-01', 'SLM90003', 'Bagas Saputra', 'Gunung Manuk', 'CLEON Family', 150000.00, 150000.00, 'Belum Lunas', '2026-05-31', NULL, NULL, 'SLM/TEST/00053/05/2026', '2026-07-16 03:02:37', '2026-07-16 03:02:37'),
(57, 53, '2026-06-01', 'SLM90003', 'Bagas Saputra', 'Gunung Manuk', 'CLEON Family', 150000.00, 150000.00, 'Lunas', '2026-06-30', '2026-06-15', 150000.00, 'SLM/TEST/00053/06/2026', '2026-07-16 03:08:00', '2026-07-16 03:08:00'),
(58, 154, '2026-06-01', 'SLM01050', 'Pelanggan Dummy 50', 'Ngasem Ayu', 'CLEON Basic', 125000.00, 125000.00, 'Lunas', '2026-06-30', '2026-06-30', 125000.00, 'SLM/TEST/00154/06/2026', '2026-07-16 04:19:41', '2026-07-16 04:19:41'),
(59, 161, '2026-07-01', 'SLM-00161', 'Arga Hartigan', 'Blitar', 'CLEON Basic', 125000.00, 125000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, 'SLM/INV/00161/07/2026', '2026-08-01 14:41:40', '2026-08-01 14:41:40'),
(60, 162, '2026-07-01', 'SLM-00162', 'Farida Nurhan', 'Gunung Manuk', 'CLEON Plus', 175000.00, 175000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, 'SLM/INV/00162/07/2026', '2026-08-01 14:41:40', '2026-08-01 14:41:40'),
(61, 164, '2026-07-01', 'BRN10', 'Dinda Maharani', 'BARAN', 'CLEON Family', 10000.00, 10000.00, 'Lunas', '2026-07-31', '2026-07-28', 10000.00, 'BRN/INV/00164/07/2026', '2026-08-01 14:41:40', '2026-08-01 14:41:40'),
(62, 165, '2026-07-01', 'BRN13', 'HASIL', 'BARAN', 'CLEON Plus', 100000.00, 100000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, 'BRN/INV/00165/07/2026', '2026-08-01 14:41:40', '2026-08-01 14:41:40'),
(63, 166, '2026-07-01', 'SLM-00060', 'ijat', 'SALAM', 'CLEON Basic', 100000.00, 100000.00, 'Lunas', '2026-07-31', '2026-07-29', 100000.00, 'SLM/INV/00166/07/2026', '2026-08-01 14:41:40', '2026-08-01 14:41:40'),
(64, 167, '2026-07-01', 'BRN-00167', 'Kaluna Putri', 'BARAN', 'CLEON premium', 300000.00, 300000.00, 'Belum Lunas', '2026-07-31', NULL, NULL, 'BRN/INV/00167/07/2026', '2026-08-01 14:41:40', '2026-08-01 14:41:40');

--
-- Indexes for dumped tables
--

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
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tagihan_salam`
--
ALTER TABLE `tagihan_salam`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
