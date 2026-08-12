<?php
session_start();
require_once __DIR__ . '/db_salam.php';
require_once __DIR__ . '/helpers_salam.php';
salamRequireLogin();
$isSuperAdminSalam = salamIsSuperAdmin();
$canAccessAllWilayah = salamCanAccessAllWilayah();
$dashboardWilayah = $canAccessAllWilayah ? 'SEMUA WILAYAH' : salamWilayahLogin();
$alamatWilayahResmi = array_values(salamDaftarWilayahResmi());
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Billing <?= htmlspecialchars($dashboardWilayah); ?> / UKOOMED</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="icon" href="logo_cleon.png" type="image/png">
    <link rel="shortcut icon" href="logo_cleon.png" type="image/png">
    <link rel="apple-touch-icon" sizes="180x180" href="logo_cleon.png">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #3498db;
            --secondary: #2c3e50;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --light: #f8f9fa;
            --dark: #343a40;
            --gray: #6c757d;
            --light-gray: #e9ecef;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7f9;
            color: #333;
            line-height: 1.6;
        }
        
        /* Header Styles */
        .header {
            background: linear-gradient(135deg, var(--secondary) 0%, #1a2530 100%);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.1);
            padding: 8px 15px;
            border-radius: 20px;
        }
        
        .logout-btn {
            background-color: var(--danger);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        
        .logout-btn:hover {
            background-color: #c0392b;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .report-btn {
            background-color: #8e44ad;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }

        .report-btn:hover {
            background-color: #6d3187;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .manage-btn {
            background-color: #16a085;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        .manage-btn:hover {
            background-color: #117864;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .monitor-btn {
            background-color: #2980b9;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        .monitor-btn:hover {
            background-color: #21618c;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        /* Container Styles */
        .container {
            padding: 30px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        /* Stats Cards - MODIFIED */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            cursor: pointer; /* Menandakan bisa diklik */
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.1);
        }
        
        /* Style untuk filter yang aktif - BARU */
        .stat-card.active-filter {
            box-shadow: 0 0 0 3px var(--primary), 0 8px 16px rgba(0,0,0,0.1);
            transform: translateY(-5px);
        }
        
        .stat-card .icon {
            font-size: 32px;
            padding: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        .icon.bg-primary { background: var(--primary); }
        .icon.bg-success { background: var(--success); }
        .icon.bg-danger { background: var(--danger); }
        .icon.bg-active { background: #16a085; }
        .icon.bg-inactive { background: #7f8c8d; }

        .stat-card.static-stat {
            cursor: default;
        }
        
        .stat-card h3 {
            font-size: 28px;
            margin: 0;
            color: var(--secondary);
        }
        
        .stat-card p {
            color: var(--gray);
            font-weight: 500;
            margin: 0;
        }
        
        /* Table Styles - (No major changes) */
        .table-section {
            background: white;
            border-radius: 10px;
            overflow-x: auto;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }
        
        .table-header {
            padding: 20px;
            border-bottom: 1px solid var(--light-gray);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .table-header h2 { font-size: 20px; color: var(--secondary); }
        
        .search-box {
            display: flex; align-items: center; background: var(--light); border: 1px solid var(--light-gray);
            padding: 8px 15px; border-radius: 25px; width: 300px;
        }
        
        .search-box input { border: none; background: transparent; padding: 5px; width: 100%; outline: none; }
        
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        
        .modern-table thead th {
            background: var(--primary); /* Diubah menjadi warna biru utama */
            color: white; /* Diubah menjadi warna putih */
            font-weight: 600;
            padding: 14px 18px;
            text-align: left;
            text-transform: uppercase;
            font-size: 13px;
            letter-spacing: .4px;
            /* Ganti border agar serasi dengan background biru */
            border-bottom: 2px solid #2980b9; 
        }
    /* Kolom Dashboard Salam sama seperti dashboard admin lain. */
    .modern-table { min-width: 1320px; table-layout: fixed; }
    .modern-table th:nth-child(1) { width: 4%; }   /* No */
    .modern-table th:nth-child(2) { width: 10%; }  /* ID Pelanggan */
    .modern-table th:nth-child(3) { width: 15%; }  /* Nama Pelanggan */
    .modern-table th:nth-child(4) { width: 10%; }  /* Status Pelanggan */
    .modern-table th:nth-child(5) { width: 10%; }  /* Paket */
    .modern-table th:nth-child(6) { width: 12%; }  /* Masa Aktif Sampai */
    .modern-table th:nth-child(7) { width: 10%; }  /* Tagihan Bulan */
    .modern-table th:nth-child(8) { width: 10%; }  /* Tagihan */
    .modern-table th:nth-child(9) { width: 10%; }  /* Status Bayar */
    .modern-table th:nth-child(10) { width: 19%; min-width: 300px; text-align: center; } /* Aksi */
        .modern-table td { padding: 14px 18px; border-bottom: 1px solid var(--light-gray); vertical-align: middle; color: #333; word-wrap: break-word; }
        .id-pill { display: inline-block; padding: 6px 10px; background: var(--light-gray); border-radius: 15px; color: var(--secondary); font-weight: 600; font-size: 13px; }
        .name-cell { font-weight: 600; color: var(--dark); }
        .muted { color: var(--gray); font-size: 13px; }
        .badge { display: inline-block; padding: 6px 12px; border-radius: 15px; font-size: 13px; font-weight: 700; color: white; cursor: pointer; transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .badge:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .badge-success { background: var(--success); }
        .badge-danger { background: var(--danger); }
    .action-btn { padding: 8px 12px; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; display: inline-flex; align-items: center; gap: 8px; transition: all 140ms ease; color: #fff; text-decoration: none; margin: 6px 4px; }
    .action-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.1); }
    .btn-tagihan { background: var(--success); }
    .btn-resi { background: var(--warning); }
    .btn-print { background: #6f42c1; }
    .btn-edit { background: var(--primary); }
    .action-btn.btn-delete { background: var(--danger); color: #fff; }

        /* Pagination & Modal Styles (Unchanged) */
    .pagination { display: flex; justify-content: center; align-items: center; gap: 10px; margin-top: 30px; margin-bottom: 40px; }
        .pagination button { padding: 10px 16px; background-color: white; color: var(--dark); border: 1px solid var(--light-gray); border-radius: 6px; cursor: pointer; transition: all 0.2s ease; }
        .pagination button:hover:not(:disabled) { background-color: var(--primary); color: white; border-color: var(--primary); }
        .pagination button:disabled { opacity: 0.6; cursor: not-allowed; }
        .pagination button.active { background-color: var(--primary); color: white; border-color: var(--primary); }
        #edit-modal { position: fixed; inset: 0; display: flex; align-items: center; justify-content: center; z-index: 2000; pointer-events: none; opacity: 0; transition: opacity 280ms cubic-bezier(.2,.9,.2,1); }
        #edit-modal .backdrop { position: absolute; inset: 0; background: rgba(6,12,24,0.56); backdrop-filter: blur(6px); opacity: 0; transition: opacity 280ms cubic-bezier(.2,.9,.2,1); }
        #edit-modal .modal-box { position: relative; width: 90%; max-width: 900px; height: 80vh; background: #fff; border-radius: 10px; transform: translateY(12px) scale(.98); opacity: 0; transition: transform 320ms cubic-bezier(.2,.9,.2,1), opacity 260ms ease; box-shadow: 0 30px 60px rgba(8,15,30,0.35); overflow: hidden; display: flex; flex-direction: column; }
        #edit-modal.open { pointer-events: auto; opacity: 1; }
        #edit-modal.open .backdrop { opacity: 1; }
        #edit-modal.open .modal-box { transform: translateY(0) scale(1); opacity: 1; }
        .modal-header { display:flex;justify-content:space-between;align-items:center;padding:14px 18px;background:#f7f9fb;border-bottom:1px solid #eef3f6; }
        .modal-title { font-weight:700;color:#222; }
        .modal-close { background:transparent;border-radius:8px;border:none;width:36px;height:36px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;transition:transform 180ms ease, background 180ms ease; }
        .modal-close:hover { transform: rotate(90deg) scale(1.06); background:#f0f4f8; }
        .modal-iframe { width:100%; flex:1 1 auto; border:0; display:block; }

        .table-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .modern-table td.actions-cell {
            display: flex;
            flex-direction: row;
            justify-content: flex-start;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            flex-wrap: nowrap;
            overflow: visible;
        }
        .modern-table td.actions-cell .action-row { display: inline-flex; gap: 6px; flex-wrap: nowrap; }

        .btn-create {
            background: var(--primary); /* Warna biru agar serasi dengan tombol Edit */
        }

        /* -- Styles untuk Add Modal (BARU) -- */
    #add-modal {
        position: fixed; 
        inset: 0; 
        display: none; /* Awalnya disembunyikan */
        align-items: center; 
        justify-content: center; 
        z-index: 2000; 
        pointer-events: none; 
        opacity: 0; 
        transition: opacity 280ms cubic-bezier(.2,.9,.2,1);
    }
    #add-modal.open {
        pointer-events: auto; 
        opacity: 1;
    }
    #add-modal .backdrop { 
        position: absolute; 
        inset: 0; 
        background: rgba(6,12,24,0.56); 
        backdrop-filter: blur(6px); 
        opacity: 0; 
        transition: opacity 280ms cubic-bezier(.2,.9,.2,1); 
    }
    #add-modal.open .backdrop { 
        opacity: 1; 
    }
    #add-modal .modal-box {
        position: relative; 
        width: 90%; 
        max-width: 700px; /* Lebar modal bisa disesuaikan */
        background: #fff; 
        border-radius: 10px; 
        transform: translateY(12px) scale(.98); 
        opacity: 0; 
        transition: transform 320ms cubic-bezier(.2,.9,.2,1), opacity 260ms ease; 
        box-shadow: 0 30px 60px rgba(8,15,30,0.35); 
        overflow: hidden;
    }
    #add-modal.open .modal-box { 
        transform: translateY(0) scale(1); 
        opacity: 1; 
    }
    /* Style tambahan untuk form di dalam modal (opsional, tapi disarankan) */
    #add-modal .grid { display: flex; gap: 12px; }
    #add-modal .col { flex: 1; }
    #add-modal label.small { display: block; color: #6c757d; font-size: 13px; margin-bottom: 6px; }
    #add-modal input[type="text"],
    #add-modal input[type="number"] {
        width: 100%;
        padding: 10px 12px;
        border-radius: 8px;
        border: 1px solid #e6eef6;
        font-size: 14px;
        box-sizing: border-box;
    }
    #add-modal .note { font-size: 13px; color: #6c757d; margin-top: 12px; }
    #add-modal .actions { display: flex; gap: 10px; justify-content: flex-end; padding-top: 20px; }
    #add-modal .btn { padding: 10px 14px; border-radius: 8px; border: 0; cursor: pointer; font-weight: 600; }
    #add-modal .btn-primary { background: #f1f5f9; (90deg,var(--accent),#2980b9); color: #213; }
    #add-modal .btn-muted { background: #f1f5f9; color: #213; }

    /* -- Styles untuk Modal Pesan (khusus Salam) -- */
    #message-modal {
        position: fixed; 
        inset: 0; 
        display: none;
        align-items: center; 
        justify-content: center; 
        z-index: 2000; 
        pointer-events: none; 
        opacity: 0; 
        transition: opacity 280ms cubic-bezier(.2,.9,.2,1);
    }
    #message-modal.open {
        pointer-events: auto; 
        opacity: 1;
    }
    #message-modal .backdrop { 
        position: absolute; 
        inset: 0; 
        background: rgba(6,12,24,0.56); 
        backdrop-filter: blur(6px); 
        opacity: 0; 
        transition: opacity 280ms cubic-bezier(.2,.9,.2,1); 
    }
    #message-modal.open .backdrop { 
        opacity: 1; 
    }
    #message-modal .modal-box {
        position: relative; 
        width: 90%; 
        max-width: 700px;
        max-height: 90vh;
        background: #fff; 
        border-radius: 10px; 
        transform: translateY(12px) scale(.98); 
        opacity: 0; 
        transition: transform 320ms cubic-bezier(.2,.9,.2,1), opacity 260ms ease; 
        box-shadow: 0 30px 60px rgba(8,15,30,0.35); 
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }
    #message-modal.open .modal-box { 
        transform: translateY(0) scale(1); 
        opacity: 1; 
    }
    #message-content {
        background: #f5f5f5;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 15px;
        font-family: monospace;
        white-space: pre-wrap;
        word-wrap: break-word;
        line-height: 1.6;
        font-size: 13px;
        max-height: 400px;
        overflow-y: auto;
    }

    /* --- STYLES UNTUK RESPONSIVE --- */
/* Tambahkan kode ini di bagian paling bawah tag <style> Anda */

/* Untuk Tablet & Perangkat Lebih Kecil (di bawah 820px) */
@media (max-width: 820px) {
    .container {
        padding: 20px 15px; /* Kurangi padding di layar kecil */
    }

    .header h1 {
        font-size: 20px; /* Perkecil judul header */
    }

    .table-header {
        flex-direction: column; /* Susun judul dan aksi secara vertikal */
        align-items: flex-start; /* Rata kiri */
        gap: 20px;
    }

    .table-actions {
        width: 100%; /* Lebarkan grup aksi */
        justify-content: space-between;
    }
    
    .search-box {
        flex-grow: 1; /* Biarkan search box memanjang */
    }

    /* --- Mengubah Tabel Menjadi Tampilan "Kartu" --- */
    .modern-table thead {
        display: none; /* Sembunyikan header tabel asli di mobile */
    }

    .modern-table tr {
        display: block; /* Ubah baris menjadi blok */
        margin-bottom: 15px; /* Jarak antar "kartu" */
        border: 1px solid var(--light-gray);
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.04);
    }

    .modern-table td {
        display: block; /* Ubah sel menjadi blok */
        width: 100%;
        padding: 12px 15px;
        padding-left: 45%; /* Sediakan ruang kiri untuk label */
        position: relative;
        text-align: right; /* Rata kanan untuk isi data */
        border-bottom: 1px solid var(--light-gray);
    }

    .modern-table td:last-child {
        border-bottom: none;
    }
    
    /* Membuat label dari header tabel menggunakan pseudo-element ::before */
    .modern-table td::before {
        content: attr(data-label); /* Ini akan mengambil teks dari atribut data-label */
        position: absolute;
        left: 15px;
        width: 40%;
        text-align: left; /* Rata kiri untuk label */
        font-weight: 600;
        color: var(--secondary);
    }

    /* Menambahkan data-label secara dinamis via CSS */
    .modern-table td:nth-of-type(1)::before { content: "No"; }
    .modern-table td:nth-of-type(2)::before { content: "ID Pelanggan"; }
    .modern-table td:nth-of-type(3)::before { content: "Nama Pelanggan"; }
    .modern-table td:nth-of-type(4)::before { content: "Paket"; }
    .modern-table td:nth-of-type(5)::before { content: "Masa Aktif"; }
    .modern-table td:nth-of-type(6)::before { content: "Bulan"; }
    .modern-table td:nth-of-type(7)::before { content: "Tagihan"; }
    .modern-table td:nth-of-type(8)::before { content: "Status"; }
    .modern-table td:nth-of-type(9)::before { content: "Aksi"; }

    /* Penyesuaian untuk sel yang kontennya kompleks */
    .modern-table td .id-pill,
    .modern-table td .badge {
        float: right; /* Pastikan elemen ini tetap di kanan */
    }
    .modern-table td .name-cell,
    .modern-table td .muted {
        text-align: right;
    }
    .name-cell { display: block; word-break: break-word; overflow-wrap: anywhere; text-align: left; padding: 6px 0; border-bottom: none; }
    .modern-table td:nth-of-type(9) .action-btn {
       margin-bottom: 5px;
    }
    
    /* Membuat form di dalam modal menjadi responsif */
    #add-modal .grid,
    #edit-modal .grid { /* Target grid di kedua modal */
        flex-direction: column;
    }
    }

    /* Untuk Ponsel dengan Layar Sangat Kecil (di bawah 480px) */
    @media (max-width: 480px) {
        .header {
            flex-direction: column;
            gap: 15px;
            padding: 15px;
        }

        .user-info {
            width: 100%;
            justify-content: center;
        }
        
        .table-actions {
            flex-direction: column;
            align-items: stretch;
        }

        .btn-create {
            justify-content: center; /* Tombol tambah jadi rata tengah */
        }

        .modern-table td {
            padding-left: 15px;
            text-align: left; /* Di layar sangat kecil, semua rata kiri */
        }

        .modern-table td::before {
            position: static;
            display: block;
            width: 100%;
            margin-bottom: 5px;
            font-size: 12px;
            color: var(--gray);
        }
        
        .modern-table td .id-pill,
        .modern-table td .badge {
            float: none;
        }
        
        .modern-table td .name-cell,
        .modern-table td .muted {
            text-align: left;
        }
        .name-cell { word-break: break-word; overflow-wrap: anywhere; }
    }
    

        /* =========================================================
           TAMPILAN RAPi SEMUA ADMIN
           Tambahan visual saja: tidak mengubah proses data, login,
           status, sinkronisasi, maupun fungsi tombol yang sudah ada.
           ========================================================= */
        body {
            background: #f4f7fb;
            color: #1f2937;
        }

        .container {
            max-width: 1720px;
            padding: 28px clamp(16px, 3vw, 42px) 42px;
        }

        .header {
            padding: 14px clamp(16px, 3vw, 32px);
        }

        .header h1 {
            font-size: clamp(19px, 2vw, 24px);
        }

        .stats-cards {
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .stat-card {
            min-height: 112px;
            padding: 20px 22px;
            border: 1px solid #edf1f5;
            border-radius: 14px;
            box-shadow: 0 6px 18px rgba(31, 41, 55, .06);
        }

        .stat-card:hover,
        .stat-card.active-filter {
            transform: translateY(-3px);
        }

        .table-section {
            overflow: hidden;
            border: 1px solid #e8edf3;
            border-radius: 16px;
            box-shadow: 0 8px 22px rgba(31, 41, 55, .07);
            background: #fff;
        }

        .table-header {
            min-height: 84px;
            padding: 18px 22px;
            gap: 14px;
            background: #fff;
        }

        .table-header h2 {
            font-size: 21px;
            letter-spacing: -.2px;
        }

        .table-actions {
            margin-left: auto;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            min-width: min(100%, 430px);
        }

        .search-box {
            width: min(330px, 100%);
            min-height: 42px;
            padding: 8px 14px;
            border: 1px solid #dfe7ef;
            background: #f8fafc;
        }

        .search-box:focus-within {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, .12);
        }

        .btn-create {
            min-height: 42px;
            padding: 0 15px;
            border-radius: 8px;
            white-space: nowrap;
            box-shadow: 0 5px 12px rgba(52, 152, 219, .18);
        }

        /* Satu area scroll untuk tabel; tombol aksi tidak keluar dari header tabel. */
        #table-container {
            width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            scrollbar-color: #b8c6d5 #eef3f7;
            scrollbar-width: thin;
        }

        #table-container::-webkit-scrollbar { height: 10px; }
        #table-container::-webkit-scrollbar-track { background: #eef3f7; }
        #table-container::-webkit-scrollbar-thumb {
            background: #b8c6d5;
            border-radius: 999px;
            border: 2px solid #eef3f7;
        }

        .modern-table {
            width: 100%;
            min-width: 1540px;
            table-layout: auto;
            border-collapse: separate;
            border-spacing: 0;
        }

        .modern-table thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            padding: 15px 12px;
            font-size: 11px;
            line-height: 1.35;
            letter-spacing: .35px;
            white-space: nowrap;
            text-align: left;
            background: #3498db;
            border-bottom: 0;
        }

        .modern-table th:nth-child(1),
        .modern-table td:nth-child(1) { width: 54px; min-width: 54px; text-align: center; }

        .modern-table th:nth-child(2),
        .modern-table td:nth-child(2) { width: 130px; min-width: 130px; }

        .modern-table th:nth-child(3),
        .modern-table td:nth-child(3) { width: 175px; min-width: 175px; }

        .modern-table th:last-child,
        .modern-table td:last-child {
            width: 318px;
            min-width: 318px;
            text-align: center;
        }

        .modern-table td {
            padding: 14px 12px;
            font-size: 13px;
            line-height: 1.45;
            vertical-align: middle;
            border-bottom: 1px solid #edf1f5;
            white-space: normal;
            background: #fff;
        }

        .modern-table tbody tr:hover td {
            background: #f8fbff;
        }

        .modern-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .id-pill {
            padding: 6px 9px;
            font-size: 11px;
            border-radius: 999px;
            white-space: nowrap;
        }

        .name-cell {
            font-size: 13px;
            line-height: 1.45;
        }

        .muted {
            font-size: 12px;
            white-space: nowrap;
        }

        .badge {
            min-width: 72px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11px;
            line-height: 1.15;
            text-align: center;
            white-space: nowrap;
        }

        /* Perbaikan utama kolom AKSI: selalu satu baris di dalam tabel. */
        .modern-table td.actions-cell {
            display: table-cell;
            min-width: 318px;
            padding: 10px 12px;
            white-space: nowrap;
            text-align: center;
            overflow: visible;
        }

        .modern-table td.actions-cell .action-row {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            flex-wrap: nowrap;
            width: max-content;
        }

        .action-btn {
            min-height: 34px;
            margin: 0;
            padding: 7px 10px;
            border-radius: 7px;
            font-size: 11px;
            line-height: 1;
            gap: 5px;
            white-space: nowrap;
            box-shadow: none;
        }

        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 5px 12px rgba(31, 41, 55, .14);
        }

        .action-btn.icon-only {
            width: 34px;
            min-width: 34px;
            justify-content: center;
            padding: 0;
        }

        .pagination {
            margin: 22px 0 0;
        }

        @media (max-width: 900px) {
            .container { padding: 18px 14px 30px; }
            .stats-cards { grid-template-columns: 1fr; gap: 12px; }
            .table-header { align-items: stretch; }
            .table-actions {
                width: 100%;
                min-width: 0;
                justify-content: stretch;
                flex-wrap: wrap;
            }
            .search-box { flex: 1 1 220px; }
            .btn-create { flex: 0 0 auto; }
        }

        @media (max-width: 560px) {
            .header { align-items: flex-start; gap: 10px; }
            .header-actions { width: 100%; justify-content: space-between; }
            .table-header h2 { font-size: 18px; }
            .table-actions { gap: 8px; }
            .search-box { flex-basis: 100%; }
            .btn-create { width: 100%; justify-content: center; }
        }


        /* =========================================================
           RAPATKAN TAMPILAN TABEL SALAM
           Perubahan visual saja: tidak mengubah data, tombol,
           database, status, maupun proses dashboard lain.
           ========================================================= */
        .table-section {
            background: transparent !important;
            border: 0 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            overflow: visible !important;
            margin-bottom: 18px !important;
        }

        .table-header {
            min-height: auto !important;
            padding: 0 0 16px !important;
            background: transparent !important;
            border-bottom: 0 !important;
        }

        #table-container {
            overflow-x: auto !important;
            overflow-y: visible !important;
            padding: 0 0 10px !important;
            background: transparent !important;
            border: 0 !important;
        }

        .modern-table {
            min-width: 1560px !important;
            width: 100% !important;
            table-layout: fixed !important;
            border-collapse: separate !important;
            border-spacing: 0 !important;
        }

        .modern-table thead th {
            padding: 14px 10px !important;
        }

        .modern-table thead th:first-child {
            border-top-left-radius: 8px;
        }

        .modern-table thead th:last-child {
            border-top-right-radius: 8px;
        }

        .modern-table td {
            background: transparent !important;
            padding: 14px 10px !important;
        }

        .modern-table tbody tr:nth-child(even) td {
            background: rgba(255, 255, 255, .28) !important;
        }

        .modern-table tbody tr:hover td {
            background: rgba(255, 255, 255, .68) !important;
        }

        /* Lebar kolom dibuat cukup supaya tombol Hapus tidak terpotong. */
        .modern-table th:nth-child(1),
        .modern-table td:nth-child(1) { width: 44px !important; min-width: 44px !important; }

        .modern-table th:nth-child(2),
        .modern-table td:nth-child(2) { width: 132px !important; min-width: 132px !important; }

        .modern-table th:nth-child(3),
        .modern-table td:nth-child(3) { width: 182px !important; min-width: 182px !important; }

        .modern-table th:last-child,
        .modern-table td:last-child {
            width: 410px !important;
            min-width: 410px !important;
            padding-left: 12px !important;
            padding-right: 18px !important;
            text-align: center !important;
        }

        .modern-table td.actions-cell {
            display: table-cell !important;
            overflow: visible !important;
            white-space: nowrap !important;
        }

        .modern-table td.actions-cell .action-row {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 7px !important;
            width: max-content !important;
            flex-wrap: nowrap !important;
        }

        .modern-table td.actions-cell .action-btn {
            margin: 0 !important;
            flex: 0 0 auto !important;
        }

        .modern-table td.actions-cell .action-btn.icon-only {
            width: 34px !important;
            min-width: 34px !important;
            height: 34px !important;
            padding: 0 !important;
            justify-content: center !important;
        }

    
        /* =========================================================
           RESPONSIVE MOBILE - TABEL TETAP RAPI
           Hanya tampilan layar kecil. Data dan fungsi tidak diubah.
           Pada HP tabel digeser ke samping, tidak diubah menjadi kartu.
           ========================================================= */
        @media (max-width: 820px) {
            .container {
                padding: 16px 12px 28px !important;
            }

            .header {
                padding: 12px 14px !important;
                gap: 10px;
                flex-wrap: wrap;
            }

            .header h1 {
                font-size: 18px !important;
                line-height: 1.3;
            }

            .header-actions {
                margin-left: auto;
                gap: 8px;
            }

            .user-info {
                padding: 7px 10px;
                font-size: 12px;
            }

            .logout-btn {
                padding: 8px 11px;
                font-size: 12px;
            }

            .stats-cards {
                grid-template-columns: 1fr !important;
                gap: 10px;
                margin-bottom: 18px;
            }

            .stat-card {
                min-height: 88px;
                padding: 16px !important;
                gap: 14px !important;
            }

            .stat-card .icon {
                font-size: 24px;
                padding: 12px;
            }

            .stat-card h3 {
                font-size: 24px;
            }

            .table-header {
                padding: 14px 0 !important;
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 12px !important;
            }

            .table-header h2 {
                font-size: 19px !important;
            }

            .table-actions {
                width: 100% !important;
                min-width: 0 !important;
                margin-left: 0 !important;
                display: flex !important;
                align-items: center !important;
                justify-content: stretch !important;
                flex-wrap: nowrap !important;
                gap: 8px !important;
            }

            .search-box {
                width: auto !important;
                min-width: 0 !important;
                flex: 1 1 auto !important;
                min-height: 42px;
            }

            .btn-create {
                flex: 0 0 auto !important;
                min-height: 42px;
                padding: 0 12px !important;
            }

            /* Tabel dipertahankan sebagai tabel, lalu dapat digeser horizontal. */
            #table-container {
                width: 100% !important;
                overflow-x: auto !important;
                overflow-y: hidden !important;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: thin;
                touch-action: pan-x pan-y;
                border-radius: 10px;
            }

            #table-container::-webkit-scrollbar {
                height: 8px;
            }

            .modern-table {
                width: 1280px !important;
                min-width: 1280px !important;
                table-layout: auto !important;
                border-collapse: separate !important;
                border-spacing: 0 !important;
            }

            .modern-table thead {
                display: table-header-group !important;
            }

            .modern-table tbody {
                display: table-row-group !important;
            }

            .modern-table tr {
                display: table-row !important;
                margin: 0 !important;
                border: 0 !important;
                border-radius: 0 !important;
                box-shadow: none !important;
            }

            .modern-table th,
            .modern-table td {
                display: table-cell !important;
                width: auto !important;
                position: static !important;
                text-align: left !important;
                vertical-align: middle !important;
            }

            .modern-table thead th {
                padding: 13px 10px !important;
                font-size: 10px !important;
                white-space: nowrap !important;
            }

            .modern-table td {
                padding: 12px 10px !important;
                font-size: 12px !important;
                border-bottom: 1px solid #edf1f5 !important;
                background: #fff !important;
            }

            .modern-table td::before {
                content: none !important;
                display: none !important;
            }

            .modern-table td .id-pill,
            .modern-table td .badge {
                float: none !important;
            }

            .modern-table td .name-cell,
            .modern-table td .muted {
                text-align: left !important;
            }

            .modern-table td.actions-cell {
                display: table-cell !important;
                width: 318px !important;
                min-width: 318px !important;
                padding: 10px !important;
                text-align: center !important;
                white-space: nowrap !important;
            }

            .modern-table td.actions-cell .action-row {
                display: inline-flex !important;
                flex-wrap: nowrap !important;
                align-items: center !important;
                justify-content: center !important;
                gap: 5px !important;
            }

            .action-btn {
                min-height: 34px;
                margin: 0 !important;
                padding: 7px 9px !important;
                font-size: 11px !important;
            }

            .action-btn.icon-only {
                width: 34px !important;
                min-width: 34px !important;
                padding: 0 !important;
            }

            #add-modal .modal-box,
            #edit-modal .modal-box,
            #message-modal .modal-box {
                width: 94% !important;
                max-height: 88vh !important;
            }

            #add-modal .grid,
            #edit-modal .grid {
                flex-direction: column !important;
            }

            .pagination {
                width: 100%;
                overflow-x: auto;
                justify-content: flex-start;
                padding-bottom: 4px;
            }
        }

        @media (max-width: 480px) {
            .header {
                flex-direction: column !important;
                align-items: stretch !important;
            }

            .header-actions {
                width: 100%;
                margin-left: 0;
                justify-content: space-between;
            }

            .table-actions {
                flex-direction: column !important;
                align-items: stretch !important;
            }

            .search-box,
            .btn-create {
                width: 100% !important;
                flex: 1 1 auto !important;
            }

            .btn-create {
                justify-content: center;
            }
        }

    

        /* =========================================================
           RESPONSIVE HP - MODE KARTU
           Hanya aktif pada layar kecil. Tampilan desktop, database,
           tombol, dan logika aplikasi tidak diubah.
           ========================================================= */
        @media (max-width: 820px) {
            .container {
                padding: 16px 12px 28px !important;
            }

            .header {
                padding: 12px 14px !important;
                gap: 10px !important;
                flex-wrap: wrap !important;
            }

            .header h1 {
                font-size: 18px !important;
                line-height: 1.3 !important;
            }

            .header-actions {
                margin-left: auto !important;
                gap: 8px !important;
            }

            .user-info {
                padding: 7px 10px !important;
                font-size: 12px !important;
            }

            .logout-btn {
                padding: 8px 11px !important;
                font-size: 12px !important;
            }

            .stats-cards {
                grid-template-columns: 1fr !important;
                gap: 10px !important;
                margin-bottom: 18px !important;
            }

            .stat-card {
                min-height: 88px !important;
                padding: 16px !important;
                gap: 14px !important;
            }

            .stat-card .icon {
                font-size: 24px !important;
                padding: 12px !important;
            }

            .stat-card h3 {
                font-size: 24px !important;
            }

            .table-header {
                padding: 14px 0 !important;
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 12px !important;
            }

            .table-header h2 {
                font-size: 19px !important;
            }

            .table-actions {
                width: 100% !important;
                min-width: 0 !important;
                margin-left: 0 !important;
                display: flex !important;
                align-items: center !important;
                justify-content: stretch !important;
                flex-wrap: nowrap !important;
                gap: 8px !important;
            }

            .search-box {
                width: auto !important;
                min-width: 0 !important;
                flex: 1 1 auto !important;
                min-height: 42px !important;
            }

            .btn-create {
                flex: 0 0 auto !important;
                min-height: 42px !important;
                padding: 0 12px !important;
            }

            /* Ubah tabel menjadi kartu pelanggan pada HP. */
            #table-container {
                width: 100% !important;
                overflow: visible !important;
                overflow-x: visible !important;
                overflow-y: visible !important;
                padding-bottom: 0 !important;
                border-radius: 0 !important;
                touch-action: auto !important;
            }

            #table-container .modern-table {
                width: 100% !important;
                min-width: 0 !important;
                table-layout: auto !important;
                border-collapse: separate !important;
                border-spacing: 0 !important;
                background: transparent !important;
            }

            #table-container .modern-table thead {
                display: none !important;
            }

            #table-container .modern-table tbody {
                display: block !important;
            }

            #table-container .modern-table tbody tr {
                display: block !important;
                width: 100% !important;
                margin: 0 0 14px !important;
                padding: 0 !important;
                border: 1px solid #e5ebf2 !important;
                border-radius: 10px !important;
                overflow: hidden !important;
                background: #fff !important;
                box-shadow: 0 2px 8px rgba(25, 45, 67, .05) !important;
            }

            #table-container .modern-table tbody td {
                display: grid !important;
                grid-template-columns: minmax(112px, 42%) minmax(0, 1fr) !important;
                align-items: center !important;
                column-gap: 10px !important;
                width: 100% !important;
                min-height: 56px !important;
                padding: 12px 14px !important;
                position: relative !important;
                text-align: right !important;
                vertical-align: middle !important;
                border: 0 !important;
                border-bottom: 1px solid #edf1f5 !important;
                background: transparent !important;
                font-size: 14px !important;
            }

            #table-container .modern-table tbody td:last-child {
                border-bottom: 0 !important;
            }

            #table-container .modern-table tbody td::before {
                content: attr(data-label) !important;
                position: static !important;
                display: block !important;
                width: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                text-align: left !important;
                font-weight: 700 !important;
                color: var(--secondary) !important;
                font-size: 13px !important;
                line-height: 1.35 !important;
            }

            #table-container .modern-table tbody td .id-pill,
            #table-container .modern-table tbody td .badge {
                float: none !important;
                justify-self: end !important;
            }

            #table-container .modern-table tbody td .name-cell,
            #table-container .modern-table tbody td .muted {
                display: block !important;
                max-width: 100% !important;
                text-align: right !important;
                justify-self: end !important;
                word-break: break-word !important;
                overflow-wrap: anywhere !important;
            }

            #table-container .modern-table tbody td.actions-cell {
                display: grid !important;
                grid-template-columns: minmax(112px, 42%) minmax(0, 1fr) !important;
                text-align: right !important;
                width: 100% !important;
                min-width: 0 !important;
            }

            #table-container .modern-table tbody td.actions-cell .action-row {
                display: flex !important;
                width: 100% !important;
                min-width: 0 !important;
                justify-content: flex-end !important;
                align-items: center !important;
                flex-wrap: wrap !important;
                gap: 7px !important;
            }

            #table-container .modern-table tbody td.actions-cell .action-btn {
                margin: 0 !important;
                flex: 0 0 auto !important;
            }

            #table-container .modern-table tbody td.actions-cell .action-btn.icon-only {
                width: 38px !important;
                min-width: 38px !important;
                height: 38px !important;
                padding: 0 !important;
                justify-content: center !important;
            }

            /* Pesan tabel kosong tetap normal, tidak menjadi kartu. */
            #table-container .modern-table tbody td[colspan] {
                display: block !important;
                min-height: 0 !important;
                text-align: center !important;
                padding: 28px 14px !important;
            }

            #table-container .modern-table tbody td[colspan]::before {
                content: none !important;
                display: none !important;
            }
        }

        @media (max-width: 480px) {
            .table-actions {
                align-items: stretch !important;
            }

            .btn-create {
                font-size: 12px !important;
                padding: 0 10px !important;
            }

            #table-container .modern-table tbody td {
                grid-template-columns: minmax(98px, 40%) minmax(0, 1fr) !important;
                padding: 12px 12px !important;
            }

            #table-container .modern-table tbody td.actions-cell .action-row {
                gap: 6px !important;
            }
        }


        /* =========================================================
           PERBAIKAN KHUSUS KOLOM AKSI DESKTOP
           Hanya CSS: tombol Hapus tampil penuh, tabel tetap tanpa
           kotak putih, kolom tetap rapi. Tidak mengubah PHP/JS/data.
           ========================================================= */
        @media (min-width: 1050px) {
            .table-section {
                background: transparent !important;
                border: 0 !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                overflow: visible !important;
            }

            #table-container {
                width: 100% !important;
                overflow: visible !important;
                padding: 0 !important;
                background: transparent !important;
            }

            #table-container .modern-table {
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                table-layout: fixed !important;
                border-collapse: collapse !important;
            }

            #table-container .modern-table th,
            #table-container .modern-table td {
                min-width: 0 !important;
                padding: 11px 7px !important;
                font-size: 12px !important;
                vertical-align: middle !important;
                overflow-wrap: anywhere !important;
            }

            #table-container .modern-table thead th {
                white-space: nowrap !important;
                font-size: 10px !important;
            }

            /* Pembagian kolom untuk dashboard: No, ID, Nama, Status
               Pelanggan, Paket, Masa Aktif, Bulan, Tagihan, Status, Aksi. */
            #table-container .modern-table th:nth-child(1),
            #table-container .modern-table td:nth-child(1) {
                width: 3.5% !important;
                text-align: center !important;
            }

            #table-container .modern-table th:nth-child(2),
            #table-container .modern-table td:nth-child(2) {
                width: 8% !important;
            }

            #table-container .modern-table th:nth-child(3),
            #table-container .modern-table td:nth-child(3) {
                width: 15% !important;
            }

            #table-container .modern-table th:nth-child(4),
            #table-container .modern-table td:nth-child(4) {
                width: 9% !important;
            }

            #table-container .modern-table th:nth-child(5),
            #table-container .modern-table td:nth-child(5) {
                width: 10% !important;
            }

            #table-container .modern-table th:nth-child(6),
            #table-container .modern-table td:nth-child(6) {
                width: 10% !important;
            }

            #table-container .modern-table th:nth-child(7),
            #table-container .modern-table td:nth-child(7) {
                width: 9% !important;
            }

            #table-container .modern-table th:nth-child(8),
            #table-container .modern-table td:nth-child(8) {
                width: 8% !important;
            }

            #table-container .modern-table th:nth-child(9),
            #table-container .modern-table td:nth-child(9) {
                width: 7% !important;
                text-align: center !important;
            }

            /* Kolom Aksi sengaja dilebarkan agar Edit dan Hapus utuh. */
            #table-container .modern-table th:last-child,
            #table-container .modern-table td:last-child {
                width: 20.5% !important;
                min-width: 0 !important;
                padding-left: 4px !important;
                padding-right: 4px !important;
                text-align: center !important;
                overflow: visible !important;
            }

            #table-container .modern-table td.actions-cell {
                display: table-cell !important;
                white-space: nowrap !important;
                overflow: visible !important;
            }

            #table-container .modern-table td.actions-cell .action-row {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                flex-wrap: nowrap !important;
                gap: 3px !important;
                width: auto !important;
                max-width: 100% !important;
            }

            #table-container .modern-table td.actions-cell .action-btn {
                min-height: 32px !important;
                margin: 0 !important;
                padding: 6px 6px !important;
                border-radius: 7px !important;
                font-size: 10px !important;
                line-height: 1 !important;
                white-space: nowrap !important;
                flex: 0 0 auto !important;
            }

            #table-container .modern-table td.actions-cell .action-btn.icon-only {
                width: 30px !important;
                min-width: 30px !important;
                height: 32px !important;
                padding: 0 !important;
                justify-content: center !important;
            }
        }



        /* =========================================================
           PERBAIKAN FINAL KOLOM STATUS DESKTOP
           Hanya CSS: merapatkan jarak kolom Status ke Aksi setelah
           ada kolom Alamat. Tidak mengubah PHP, JS, data, tombol,
           fitur, database, atau alur dashboard.
           ========================================================= */
        @media (min-width: 1050px) {
            #table-container .modern-table {
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                table-layout: fixed !important;
            }

            #table-container .modern-table th,
            #table-container .modern-table td {
                padding-left: 6px !important;
                padding-right: 6px !important;
            }

            /* Urutan kolom setelah update Alamat:
               No, ID, Nama, Alamat, Status Pelanggan, Paket,
               Masa Aktif, Tagihan Bulan, Tagihan, Status, Aksi. */
            #table-container .modern-table th:nth-child(1),
            #table-container .modern-table td:nth-child(1) { width: 3.3% !important; text-align: center !important; }

            #table-container .modern-table th:nth-child(2),
            #table-container .modern-table td:nth-child(2) { width: 7.6% !important; }

            #table-container .modern-table th:nth-child(3),
            #table-container .modern-table td:nth-child(3) { width: 12.4% !important; }

            #table-container .modern-table th:nth-child(4),
            #table-container .modern-table td:nth-child(4) { width: 8.8% !important; }

            #table-container .modern-table th:nth-child(5),
            #table-container .modern-table td:nth-child(5) { width: 8.0% !important; text-align: center !important; }

            #table-container .modern-table th:nth-child(6),
            #table-container .modern-table td:nth-child(6) { width: 8.2% !important; }

            #table-container .modern-table th:nth-child(7),
            #table-container .modern-table td:nth-child(7) { width: 8.2% !important; }

            #table-container .modern-table th:nth-child(8),
            #table-container .modern-table td:nth-child(8) { width: 7.6% !important; }

            #table-container .modern-table th:nth-child(9),
            #table-container .modern-table td:nth-child(9) { width: 7.0% !important; }

            #table-container .modern-table th:nth-child(10),
            #table-container .modern-table td:nth-child(10) {
                width: 7.0% !important;
                min-width: 0 !important;
                text-align: center !important;
                padding-left: 4px !important;
                padding-right: 4px !important;
            }

            #table-container .modern-table th:nth-child(11),
            #table-container .modern-table td:nth-child(11) {
                width: 21.9% !important;
                min-width: 0 !important;
                text-align: left !important;
                padding-left: 4px !important;
                padding-right: 4px !important;
            }

            #table-container .modern-table td:nth-child(10) .badge {
                margin: 0 !important;
                min-width: 70px !important;
            }

            #table-container .modern-table td.actions-cell {
                display: table-cell !important;
                white-space: nowrap !important;
                overflow: visible !important;
            }

            #table-container .modern-table td.actions-cell .action-row {
                justify-content: flex-start !important;
                gap: 3px !important;
                width: 100% !important;
                max-width: 100% !important;
            }

            #table-container .modern-table td.actions-cell .action-btn {
                min-height: 31px !important;
                padding: 6px 5px !important;
                font-size: 10px !important;
                border-radius: 7px !important;
            }

            #table-container .modern-table td.actions-cell .action-btn.icon-only {
                width: 29px !important;
                min-width: 29px !important;
                height: 31px !important;
                padding: 0 !important;
            }
        }

    </style>
</head>
<body>

    <div class="header">
        <h1><i class="fas fa-file-invoice-dollar"></i> Dashboard Billing <?= htmlspecialchars($dashboardWilayah); ?> / UKOOMED</h1>
        <div class="header-actions">
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span><?= htmlspecialchars($_SESSION['username']); ?></span>
            </div>
            <a href="monitoring_pppoe.php" class="monitor-btn">
                <i class="fas fa-map-location-dot"></i> Monitoring PPPoE
            </a>
            <?php if ($isSuperAdminSalam): ?>
                <a href="kelola_admin.php" class="manage-btn">
                    <i class="fas fa-user-gear"></i> Kelola Admin
                </a>
                <a href="laporan_salam.php" class="report-btn">
                    <i class="fas fa-file-export"></i> Laporan
                </a>
            <?php endif; ?>
            <a href="#" onclick="confirmLogout(event)" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>

    <div class="container">

        <div class="stats-cards">
            <div class="stat-card" data-filter="all" onclick="filterByStatus('all')">
                <div class="icon bg-primary"><i class="fas fa-users"></i></div>
                <div class="stat-info">
                    <h3 id="total-pelanggan">...</h3>
                    <p>Total Pelanggan</p>
                </div>
            </div>
            <div class="stat-card" data-filter="aktif" onclick="filterByStatus('aktif')">
                <div class="icon bg-active"><i class="fas fa-user-check"></i></div>
                <div class="stat-info">
                    <h3 id="pelanggan-aktif">...</h3>
                    <p>Pelanggan Aktif</p>
                </div>
            </div>
            <div class="stat-card" data-filter="tidak aktif" onclick="filterByStatus('tidak aktif')">
                <div class="icon bg-inactive"><i class="fas fa-user-slash"></i></div>
                <div class="stat-info">
                    <h3 id="pelanggan-tidak-aktif">...</h3>
                    <p>Pelanggan Tidak Aktif</p>
                </div>
            </div>
            <div class="stat-card" data-filter="lunas" onclick="filterByStatus('lunas')">
                <div class="icon bg-success"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <h3 id="tagihan-lunas">...</h3>
                    <p>Lunas Bulan Ini</p>
                </div>
            </div>
            <div class="stat-card" data-filter="belum lunas" onclick="filterByStatus('belum lunas')">
                <div class="icon bg-danger"><i class="fas fa-clock"></i></div>
                <div class="stat-info">
                    <h3 id="tagihan-belum-lunas">...</h3>
                    <p>Belum Lunas Bulan Ini</p>
                </div>
            </div>
        </div>

       <div class="table-header">
    <h2><i class="fas fa-list"></i> Daftar Billing Pelanggan</h2>
    
        <div class="table-actions">
                <?php if ($canAccessAllWilayah): ?>
                <div class="search-box" style="min-width:220px;">
                    <i class="fas fa-map-marker-alt"></i>
                    <select id="wilayah-filter" aria-label="Filter wilayah" style="width:100%;border:0;outline:0;background:transparent;padding:8px 4px;">
                        <option value="all">Semua Wilayah</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="search" placeholder="Cari pelanggan / alamat...">
                </div>
                <button class="action-btn btn-create" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Tambah Pelanggan
                </button>
            </div>
        </div>
            <div id="add-modal" style="display:none;">
    <div class="backdrop" onclick="closeAddModal()"></div>
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-title">Tambah Pelanggan Baru</div>
            <button class="modal-close" onclick="closeAddModal()"><i class="fas fa-times"></i></button>
        </div>
                    <div style="padding: 20px;">
                        <?php if ($isSuperAdminSalam): ?>
                        <div id="add-customer-tabs" style="display:flex;gap:8px;margin-bottom:18px;background:#f3f6f9;padding:5px;border-radius:10px;">
                            <button type="button" id="tab-add-manual" onclick="switchAddCustomerMode('manual')" style="flex:1;border:0;border-radius:7px;padding:10px 12px;cursor:pointer;font-weight:700;background:#3498db;color:#fff;">
                                <i class="fas fa-user-plus"></i> Tambah Manual
                            </button>
                            <button type="button" id="tab-add-import" onclick="switchAddCustomerMode('import')" style="flex:1;border:0;border-radius:7px;padding:10px 12px;cursor:pointer;font-weight:700;background:transparent;color:#52606d;">
                                <i class="fas fa-file-excel"></i> Import Excel
                            </button>
                        </div>
                        <?php endif; ?>

                        <div id="manual-add-panel">
                        <form id="add-customer-form" enctype="multipart/form-data">
                            <div class="grid">
                                <div class="col">
                                    <label class="small">ID Pelanggan</label>
                                    <input type="text" name="id_pelanggan">
                                </div>
                                <div class="col">
                                    <label class="small">Nama Lengkap</label>
                                    <input type="text" name="nama" required>
                                </div>
                            </div>
                            <div class="grid" style="margin-top:12px">
                                <div class="col">
                                    <label class="small">Paket</label>
                                    <input type="text" name="paket" required placeholder="">
                                </div>
                                <div class="col">
                                    <label class="small">Nomor WhatsApp</label>
                                    <input type="text" name="nomor_pelanggan" placeholder="Contoh: 628123456789">
                                </div>
                            </div>
                            <div class="grid" style="margin-top:12px">
                                <div class="col">
                                    <label class="small">Kode Pelanggan</label>
                                    <input type="text" name="kode_pelanggan" placeholder="Contoh: SLM001">
                                </div>
                                <div class="col">
                                    <label class="small">Alamat</label>
                                    <input type="text" name="alamat" list="alamat-salam-options" autocomplete="off" value="<?= $canAccessAllWilayah ? '' : htmlspecialchars(salamWilayahLogin()); ?>" placeholder="<?= $canAccessAllWilayah ? 'Pilih alamat atau ketik manual' : 'Wilayah akun'; ?>" <?= $canAccessAllWilayah ? '' : 'readonly'; ?>>
                                </div>
                            </div>
                            <datalist id="alamat-salam-options">
                                <?php foreach ($alamatWilayahResmi as $wilayahOption): ?>
                                    <option value="<?= htmlspecialchars($wilayahOption); ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <div class="grid" style="margin-top:12px">
                                <div class="col">
                                    <label class="small">Tarif Langganan / Tagihan Awal</label>
                                    <input type="number" name="tagihan" value="" required>
                                </div>
                            </div>
                            <div style="margin-top:18px;padding-top:15px;border-top:1px solid #e9eef3;">
                                <div style="font-weight:700;color:#2c3e50;margin-bottom:10px;"><i class="fas fa-id-card"></i> Data Identitas & Lokasi <span style="font-weight:400;color:#7f8c8d;font-size:12px;">(opsional)</span></div>
                                <div class="grid">
                                    <div class="col">
                                        <label class="small">Nama sesuai KTP</label>
                                        <input type="text" name="nama_ktp">
                                    </div>
                                    <div class="col">
                                        <label class="small">NIK</label>
                                        <input type="text" name="nik" inputmode="numeric" maxlength="32">
                                    </div>
                                </div>
                                <div class="grid" style="margin-top:12px">
                                    <div class="col">
                                        <label class="small">Foto Rumah <span style="font-weight:400;color:#7f8c8d;">(opsional)</span></label>
                                        <input type="file" name="foto_rumah" accept="image/jpeg,image/png,image/webp">
                                        <div class="note">Jika belum ada foto, biarkan kosong. Sistem menampilkan placeholder dan foto dapat ditambahkan nanti melalui Edit Pelanggan.</div>
                                    </div>
                                </div>
                                <div class="note" style="margin-top:10px;background:#f8fafc;border:1px solid #e2e8f0;padding:10px;border-radius:8px;">
                                    <b>Data teknis PPPoE tidak diinput dari Billing.</b> ID teknis, username PPPoE, koordinat, IP, dan status dicocokkan/dibaca otomatis dari PPPoE asli.
                                </div>
                                <div class="note">Maksimal foto 5 MB (JPG/PNG/WEBP).</div>
                            </div>
                            <div class="note">status pelanggan otomatis menjadi "Belum Lunas" jika taguhan > 0</div>

                            <div class="actions">
                                <button type="button" class="btn btn-muted" onclick="closeAddModal()">Batal</button>
                                <button type="submit" class="btn btn-primary">Simpan</button>
                            </div>
                        </form>
                        </div>

                        <?php if ($isSuperAdminSalam): ?>
                        <div id="import-add-panel" style="display:none;">
                            <div style="background:#f8fbfd;border:1px solid #dbe7ef;border-radius:10px;padding:16px;margin-bottom:14px;">
                                <div style="font-weight:700;color:#2c3e50;margin-bottom:6px;">
                                    <i class="fas fa-file-excel" style="color:#1f9d55;"></i> Import Banyak Pelanggan dari Excel
                                </div>
                                <div style="font-size:13px;color:#657786;line-height:1.55;">
                                    Gunakan template Excel agar nama kolom sesuai. Import hanya menambah pelanggan baru dan tidak mengubah data pelanggan yang sudah ada.
                                </div>
                            </div>

                            <form id="import-customer-form" enctype="multipart/form-data">
                                <label class="small">File Excel (.xlsx)</label>
                                <input type="file" name="file_import" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>

                                <div class="note" style="margin-top:10px;">
                                    Kolom wajib: Paket, Alamat, Tarif Langganan, serta Nama Lengkap atau Nama KTP. Maksimal 1.000 pelanggan / 5 MB.
                                    Data teknis PPPoE tidak diimpor karena Billing menyesuaikan otomatis ke PPPoE asli. Foto rumah juga tidak diimpor dari Excel; foto ditambahkan dari Billing melalui Tambah/Edit Pelanggan.
                                </div>

                                <div style="margin-top:14px;padding:14px;border:1px solid #dbe7ef;border-radius:10px;background:#f8fbfd;">
                                    <div style="font-weight:700;color:#2c3e50;margin-bottom:8px;">
                                        <i class="fas fa-table"></i> Format Template Excel
                                    </div>

                                    <div style="font-size:13px;color:#5f6b76;line-height:1.6;margin-bottom:10px;">
                                        Gunakan urutan kolom berikut agar proses import sesuai:
                                    </div>

                                    <div style="overflow-x:auto;">
                                        <table style="width:100%;border-collapse:collapse;font-size:12px;min-width:760px;">
                                            <thead>
                                                <tr style="background:#eef4f8;color:#34495e;">
                                                    <th style="padding:8px;border:1px solid #dbe7ef;text-align:left;">ID Pelanggan</th>
                                                    <th style="padding:8px;border:1px solid #dbe7ef;text-align:left;">Nama Lengkap</th>
                                                    <th style="padding:8px;border:1px solid #dbe7ef;text-align:left;">Nama KTP</th>
                                                    <th style="padding:8px;border:1px solid #dbe7ef;text-align:left;">NIK</th>
                                                    <th style="padding:8px;border:1px solid #dbe7ef;text-align:left;">Nomor WhatsApp</th>
                                                    <th style="padding:8px;border:1px solid #dbe7ef;text-align:left;">Kode Pelanggan</th>
                                                    <th style="padding:8px;border:1px solid #dbe7ef;text-align:left;">Alamat</th>
                                                    <th style="padding:8px;border:1px solid #dbe7ef;text-align:left;">Paket</th>
                                                    <th style="padding:8px;border:1px solid #dbe7ef;text-align:left;">Tarif Langganan</th>
                                                </tr>
                                            </thead>
                                        </table>
                                    </div>

                                    <div style="margin-top:10px;font-size:12px;color:#64748b;line-height:1.55;">
                                        <b>Tidak perlu dicantumkan di Excel:</b> Foto Rumah, Username PPPoE, ID teknis PPPoE, Koordinat X/Y, IP, dan Status Online/Offline.
                                    </div>

                                    <a href="template_import_pelanggan_FINAL.xlsx"
                                       download
                                       style="display:inline-flex;align-items:center;gap:8px;margin-top:12px;padding:9px 13px;background:#1f9d55;color:#fff;border-radius:8px;font-weight:700;text-decoration:none;">
                                        <i class="fas fa-download"></i> Download Template Excel
                                    </a>
                                </div>

                                <div class="actions">
                                    <button type="button" class="btn btn-muted" onclick="closeAddModal()">Batal</button>
                                    <button type="submit" class="btn btn-primary" style="background:#1f9d55;">
                                        <i class="fas fa-file-import"></i> Import Pelanggan
                                    </button>
                                </div>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div id="table-container">
                </div>
        </div>

        <div class="pagination" id="pagination">
            </div>

        <!-- Modal untuk Menampilkan Pesan (Khusus Salam) -->
        <div id="message-modal" style="display:none;">
            <div class="backdrop" onclick="closeMessageModal()"></div>
            <div class="modal-box">
                <div class="modal-header">
                    <div class="modal-title">Pesan Tagihan WhatsApp</div>
                    <button class="modal-close" onclick="closeMessageModal()"><i class="fas fa-times"></i></button>
                </div>
                <div style="padding: 20px; overflow-y: auto; flex: 1;">
                    <div id="message-content"></div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px;">
                        <button onclick="copyMessageToClipboard(event)" style="flex: 1; padding: 12px; background: #27ae60; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px;">
                            Salin ke Clipboard
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Modal logic (unchanged)
        function createEditModalIfNeeded() { if (document.getElementById('edit-modal')) return; const modal = document.createElement('div'); modal.id = 'edit-modal'; modal.innerHTML = `<div class="backdrop"></div><div class="modal-box"><div class="modal-header"><div class="modal-title">Edit Tagihan</div><button class="modal-close" onclick="closeEditModal()"><i class="fas fa-times"></i></button></div><iframe id="edit-iframe" class="modal-iframe" src="about:blank"></iframe></div>`; modal.querySelector('.backdrop').addEventListener('click', closeEditModal); document.body.appendChild(modal); document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeEditModal(); }); }
        function openEditModal(id) { createEditModalIfNeeded(); const modal = document.getElementById('edit-modal'); const iframe = document.getElementById('edit-iframe'); iframe.src = `edit_salam.php?id=${id}&modal=1`; modal.classList.add('open'); }
        function closeEditModal() { const modal = document.getElementById('edit-modal'); if (!modal) return; modal.classList.remove('open'); setTimeout(() => { document.getElementById('edit-iframe').src = 'about:blank'; loadData(currentPage, currentSearch, currentFilter); loadStats(); }, 320); }
        window.addEventListener('message', (e) => { try { const data = JSON.parse(e.data); if (data && data.action === 'close') closeEditModal(); } catch (err) {} });
        
        // --- SCRIPT UTAMA DIMODIFIKASI ---
        let currentPage = 1;
        const itemsPerPage = 10;
        let currentSearch = "";
        let currentFilter = "all"; // Filter status
        let currentWilayah = "all"; // Khusus Super Admin

        // Fungsi BARU untuk menangani filter
        function filterByStatus(status) {
            currentFilter = status;

            // Perbarui tampilan visual kartu yang aktif
            document.querySelectorAll('.stat-card').forEach(card => {
                card.classList.remove('active-filter');
            });
            document.querySelector(`.stat-card[data-filter="${status}"]`).classList.add('active-filter');

            currentPage = 1; // Selalu kembali ke halaman 1 saat filter diubah
            loadData(currentPage, currentSearch, currentFilter);
        }

        function loadStats() {
            // Statistik lama tetap memakai endpoint lama.
            fetch(`get_stats_salam.php?wilayah=${encodeURIComponent(currentWilayah)}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('total-pelanggan').innerText = data.total_pelanggan;
                    document.getElementById('tagihan-lunas').innerText = data.lunas_bulan_ini;
                    document.getElementById('tagihan-belum-lunas').innerText = data.belum_lunas_bulan_ini;
                })
                .catch(err => console.error('Error loading payment stats:', err));

            // Statistik Aktif/Tidak Aktif dipisahkan agar get_stats_salam.php lama tidak perlu diganti.
            fetch(`get_status_pelanggan_stats_salam.php?wilayah=${encodeURIComponent(currentWilayah)}`)
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.message || 'Statistik status pelanggan gagal dimuat.');
                    }

                    document.getElementById('pelanggan-aktif').innerText = data.pelanggan_aktif;
                    document.getElementById('pelanggan-tidak-aktif').innerText = data.pelanggan_tidak_aktif;
                })
                .catch(err => {
                    console.error('Error loading customer status stats:', err);
                    document.getElementById('pelanggan-aktif').innerText = '-';
                    document.getElementById('pelanggan-tidak-aktif').innerText = '-';
                });
        }

        // Fungsi loadData DIMODIFIKASI untuk menyertakan parameter filter
        function loadData(page, search = "", filter = "all") {
            // remember current page so other actions (like closing edit modal) can reload the same page
            currentPage = page;
            currentSearch = search;
            currentFilter = filter;
            document.getElementById('table-container').innerHTML = `<div style="padding: 40px; text-align: center; color: var(--gray);"><i class="fas fa-spinner fa-spin fa-2x"></i></div>`;
            
            // Tambahkan parameter &filter= ke URL fetch
            const fetchUrl = `get_data_salam.php?page=${page}&limit=${itemsPerPage}&search=${encodeURIComponent(search)}&filter=${encodeURIComponent(filter)}&wilayah=${encodeURIComponent(currentWilayah)}`;

            fetch(fetchUrl)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('table-container').innerHTML = data.table;
                    renderPagination(data.totalPages, page);
                })
                .catch(err => {
                    document.getElementById('table-container').innerHTML = `<div style="padding: 40px; text-align: center; color: var(--danger);">Error memuat data.</div>`;
                });
        }
        
        function updateStatus(id, currentStatus) {
            const newStatus = currentStatus === 'Lunas' ? 'Belum Lunas' : 'Lunas';
            Swal.fire({
                title: 'Konfirmasi Perubahan Status', text: `Anda yakin ingin mengubah status menjadi "${newStatus}"?`, icon: 'warning', showCancelButton: true,
                confirmButtonColor: '#3085d6', cancelButtonColor: '#d33', confirmButtonText: 'Ya, ubah!', cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('update_status_salam.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ id: id, status: newStatus }) })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('Berhasil!', 'Status telah berhasil diperbarui.', 'success');
                            loadData(currentPage, currentSearch, currentFilter);
                            loadStats();
                        } else {
                            Swal.fire('Gagal!', 'Terjadi kesalahan saat memperbarui status.', 'error');
                        }
                    }).catch(err => { Swal.fire('Error!', 'Tidak dapat terhubung ke server.', 'error'); });
                }
            });
        }


        // Tambahan: status pelanggan Aktif/Tidak Aktif. Tidak mengubah tagihan atau status pembayaran.
        function updateStatusPelanggan(id, currentStatus) {
            const newStatus = currentStatus === 'Aktif' ? 'Tidak Aktif' : 'Aktif';
            Swal.fire({
                title: 'Ubah Status Pelanggan?',
                text: `Status pelanggan akan menjadi "${newStatus}". Data tagihan tidak diubah.`,
                icon: 'question', showCancelButton: true,
                confirmButtonColor: '#3085d6', cancelButtonColor: '#d33',
                confirmButtonText: 'Ya, ubah', cancelButtonText: 'Batal'
            }).then((result) => {
                if (!result.isConfirmed) return;
                fetch('update_pelanggan_status_salam.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: id, status_pelanggan: newStatus })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Berhasil', data.message || 'Status pelanggan diperbarui.', 'success');
                        loadData(currentPage, currentSearch, currentFilter);
                        loadStats();
                    } else {
                        Swal.fire('Gagal', data.message || 'Status pelanggan gagal diperbarui.', 'error');
                    }
                })
                .catch(() => Swal.fire('Error', 'Tidak dapat terhubung ke server.', 'error'));
            });
        }

        function renderPagination(totalPages, currentPage) {
            const paginationDiv = document.getElementById('pagination');
            paginationDiv.innerHTML = '';
            if (totalPages <= 1) return;

            const createButton = (text, page, isDisabled = false, isActive = false) => {
                const btn = document.createElement('button');
                btn.innerHTML = text;
                btn.disabled = isDisabled;
                if (isActive) btn.classList.add('active');
                btn.onclick = () => { loadData(page, currentSearch, currentFilter); };
                return btn;
            };

            // tombol prev
            paginationDiv.appendChild(createButton('<i class="fas fa-chevron-left"></i>', currentPage - 1, currentPage === 1));

            let maxVisible = 5; // jumlah angka yang ditampilkan di sekitar currentPage
            let start = Math.max(1, currentPage - Math.floor(maxVisible / 2));
            let end = Math.min(totalPages, start + maxVisible - 1);

            if (start > 1) {
                paginationDiv.appendChild(createButton(1, 1));
                if (start > 2) paginationDiv.appendChild(createButton('...', null, true));
            }

            for (let i = start; i <= end; i++) {
                paginationDiv.appendChild(createButton(i, i, false, i === currentPage));
            }

            if (end < totalPages) {
                if (end < totalPages - 1) paginationDiv.appendChild(createButton('...', null, true));
                paginationDiv.appendChild(createButton(totalPages, totalPages));
            }

            // tombol next
            paginationDiv.appendChild(createButton('<i class="fas fa-chevron-right"></i>', currentPage + 1, currentPage === totalPages));
        }

        function normaliseWhatsAppNumber(phoneNumber) {
            let number = String(phoneNumber || '').replace(/\D/g, '');

            // Terima nomor dengan format 08..., 8..., +62..., 62..., atau 0062...
            if (number.startsWith('00')) number = number.slice(2);
            if (number.startsWith('0')) {
                number = '62' + number.slice(1);
            } else if (number.startsWith('8')) {
                number = '62' + number;
            }

            // Nomor WhatsApp Indonesia harus diawali 62 dan memiliki jumlah digit wajar.
            return /^62\d{8,14}$/.test(number) ? number : '';
        }

        function sendReceipt(id, tujuan, currentStatus) {
            const nomorWhatsApp = normaliseWhatsAppNumber(tujuan);
            if (!nomorWhatsApp) {
                Swal.fire({icon:'error', title:'Nomor WhatsApp tidak valid', text:'Isi nomor pelanggan dengan format 08xxxxxxxxxx atau 62xxxxxxxxxx terlebih dahulu.'});
                return;
            }
            const textKonfirmasi = currentStatus === 'Lunas'
                ? 'Kirim ulang resi pembayaran ke chat WhatsApp pelanggan?'
                : 'Mengirim resi akan mengubah status tagihan ini menjadi LUNAS. Lanjutkan?';
            Swal.fire({
                title:'Konfirmasi Pengiriman Resi', text:textKonfirmasi, icon:'question', showCancelButton:true,
                confirmButtonColor:'#27ae60', cancelButtonColor:'#d33',
                confirmButtonText: currentStatus === 'Lunas' ? 'Ya, Kirim Resi' : 'Ya, Kirim & LUNASKAN!',
                cancelButtonText:'Batal'
            }).then((result) => {
                if (!result.isConfirmed) return;
                const whatsappWindow = window.open('', '_blank');
                if (whatsappWindow) {
                    whatsappWindow.document.write('<!doctype html><html><head><title>Membuka WhatsApp</title></head><body style="font-family:Arial,sans-serif;padding:24px">Membuka chat WhatsApp pelanggan...</body></html>');
                    whatsappWindow.document.close();
                }
                fetch('update_status_salam.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({id:id,status:'Lunas'})})
                    .then(r => r.json())
                    .then(data => {
                        if (!data.success) throw new Error(data.message || 'Status gagal diperbarui.');
                        const r = data.receipt;
                        const pesan = `Halo Bapak/Ibu ${r.nama},\n\n${r.nama_layanan} telah dikonfirmasi pembayarannya.\n\nID Pelanggan: ${r.id_pelanggan}\nPaket: ${r.paket}\nPeriode Tagihan: ${r.periode}\nMasa Aktif Sampai: ${r.masa_aktif}\nNominal Dibayar: ${r.nominal}\nTanggal Bayar: ${r.tanggal_bayar}\nStatus: Lunas\n\nTerima kasih.`;
                        const url = 'https://wa.me/' + nomorWhatsApp + '?text=' + encodeURIComponent(pesan);
                        if (whatsappWindow && !whatsappWindow.closed) whatsappWindow.location.href = url;
                        else window.open(url, '_blank');
                        Swal.fire({icon:'success',title:'Berhasil!',text:'Status Lunas tersimpan dan chat WhatsApp pelanggan dibuka.',timer:2200,showConfirmButton:false});
                        loadData(currentPage,currentSearch,currentFilter); loadStats();
                    })
                    .catch(err => {
                        if (whatsappWindow && !whatsappWindow.closed) whatsappWindow.close();
                        Swal.fire('Gagal', err.message || 'Tidak dapat mengirim resi.', 'error');
                    });
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('search');
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    currentPage = 1;
                    loadData(currentPage, searchInput.value.trim(), currentFilter);
                }, 300);
            });
            const wilayahSelect = document.getElementById('wilayah-filter');
            if (wilayahSelect) {
                wilayahSelect.addEventListener('change', function() {
                    currentWilayah = this.value || 'all';
                    currentPage = 1;
                    loadData(currentPage, currentSearch, currentFilter);
                    loadStats();
                });
                loadWilayahFilterOptions().finally(() => {
                    loadData(currentPage, currentSearch, currentFilter);
                    loadStats();
                });
            } else {
                loadData(currentPage, currentSearch, currentFilter);
                loadStats();
            }
            document.querySelector('.stat-card[data-filter="all"]').classList.add('active-filter');
        });

        function confirmLogout(event) {
            event.preventDefault(); // Mencegah link berpindah halaman secara langsung

            Swal.fire({
                title: 'Konfirmasi Logout',
                text: "Anda yakin ingin keluar?",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Ya, Logout!',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Jika pengguna menekan "Ya", arahkan ke halaman logout
                    window.location.href = 'logout.php';
                }
            });
        }

        function confirmDelete(id) {
            Swal.fire({
                title: 'Hapus data?',
                text: 'Data yang dihapus tidak dapat dikembalikan. Yakin ingin melanjutkan?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Ya, Hapus',
                cancelButtonText: 'Batal',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch(`delete_salam.php?id=${id}`)
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                Swal.fire('Terhapus', data.message, 'success');
                                // refresh table and stats using current pagination/search/filter
                                if (typeof loadData === 'function') loadData(currentPage, currentSearch, currentFilter);
                                if (typeof loadStats === 'function') loadStats();
                            } else {
                                Swal.fire('Gagal', data.message || 'Delete gagal', 'error');
                            }
                        }).catch(err => {
                            Swal.fire('Error', 'Terjadi kesalahan jaringan', 'error');
                        });
                }
            });
        }

                // Fungsi untuk membuka dan menutup modal tambah data
        const ALAMAT_SALAM_DEFAULTS = <?= json_encode($alamatWilayahResmi, JSON_UNESCAPED_UNICODE); ?>;

        function escapeOptionValue(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }

        function renderAlamatOptions(items) {
            const datalist = document.getElementById('alamat-salam-options');
            if (!datalist) return;

            const seen = new Set();
            const merged = [];
            [...ALAMAT_SALAM_DEFAULTS, ...(Array.isArray(items) ? items : [])].forEach(item => {
                const value = String(item || '').trim();
                if (!value || value.toLowerCase() === 'semua' || value.toLowerCase() === 'semua alamat') return;
                const key = value.toLowerCase().replace(/\s+/g, ' ');
                if (seen.has(key)) return;
                seen.add(key);
                merged.push(value);
            });

            datalist.innerHTML = merged.map(item => `<option value="${escapeOptionValue(item)}"></option>`).join('');
        }

        function loadAlamatSuggestions() {
            renderAlamatOptions(ALAMAT_SALAM_DEFAULTS);
            fetch('get_alamat_salam.php')
                .then(response => response.json())
                .then(data => {
                    const items = Array.isArray(data.alamat) ? data.alamat : [];
                    renderAlamatOptions(items);
                })
                .catch(() => renderAlamatOptions(ALAMAT_SALAM_DEFAULTS));
        }

        function loadWilayahFilterOptions() {
            const select = document.getElementById('wilayah-filter');
            if (!select) return Promise.resolve();
            const previous = currentWilayah;
            return fetch('get_alamat_salam.php')
                .then(response => response.json())
                .then(data => {
                    const items = Array.isArray(data.alamat) ? data.alamat : [];
                    const options = ['<option value="all">Semua Wilayah</option>'];
                    const seen = new Set();
                    items.forEach(item => {
                        const value = String(item || '').trim();
                        const key = value.toLowerCase().replace(/\s+/g, '');
                        if (!value || seen.has(key)) return;
                        seen.add(key);
                        options.push(`<option value="${escapeOptionValue(value)}">${escapeOptionValue(value)}</option>`);
                    });
                    select.innerHTML = options.join('');
                    const exists = [...select.options].some(option => option.value === previous);
                    currentWilayah = exists ? previous : 'all';
                    select.value = currentWilayah;
                });
        }

        function switchAddCustomerMode(mode) {
            const manualPanel = document.getElementById('manual-add-panel');
            const importPanel = document.getElementById('import-add-panel');
            const manualTab = document.getElementById('tab-add-manual');
            const importTab = document.getElementById('tab-add-import');

            if (!manualPanel || !importPanel || !manualTab || !importTab) return;

            const importMode = mode === 'import';

            manualPanel.style.display = importMode ? 'none' : 'block';
            importPanel.style.display = importMode ? 'block' : 'none';

            manualTab.style.background = importMode ? 'transparent' : '#3498db';
            manualTab.style.color = importMode ? '#52606d' : '#fff';

            importTab.style.background = importMode ? '#1f9d55' : 'transparent';
            importTab.style.color = importMode ? '#fff' : '#52606d';
        }

        function openAddModal() {
            // Reset form setiap kali dibuka
            document.getElementById('add-customer-form').reset();

            const importForm = document.getElementById('import-customer-form');
            if (importForm) importForm.reset();

            switchAddCustomerMode('manual');
            const alamatInput = document.querySelector('#add-customer-form [name="alamat"]');
            if (alamatInput && alamatInput.readOnly) alamatInput.value = <?= json_encode(salamWilayahLogin(), JSON_UNESCAPED_UNICODE); ?>;
            loadAlamatSuggestions();
            const modal = document.getElementById('add-modal');
            modal.style.display = 'flex';
            // Gunakan timeout agar transisi CSS berjalan
            setTimeout(() => modal.classList.add('open'), 10);
        }

        function closeAddModal() {
            const modal = document.getElementById('add-modal');
            modal.classList.remove('open');
            // Sembunyikan elemen setelah transisi selesai
            setTimeout(() => modal.style.display = 'none', 320);
        }

        // ===== FUNGSI-FUNGSI UNTUK MODAL PESAN (Khusus Salam) =====
        let currentMessageData = {
            pesan: ''
        };

        // Event listener untuk tombol "Kirim Tagihan" - buka modal pesan
        document.addEventListener('click', function(e) {
            const button = e.target.closest('.kirim-tagihan-btn');
            if (button) {
                e.preventDefault();
                const pesan = button.getAttribute('data-pesan');
                
                if (pesan) {
                    currentMessageData.pesan = pesan;
                    openMessageModal(pesan);
                }
            }
        });

        function openMessageModal(pesan) {
            const modal = document.getElementById('message-modal');
            const messageContent = document.getElementById('message-content');

            if (!modal || !messageContent) {
                Swal.fire('Error', 'Elemen modal tidak ditemukan', 'error');
                return;
            }

            currentMessageText = pesan; // SIMPAN PESAN
            messageContent.textContent = pesan;

            modal.style.display = 'flex';
            setTimeout(() => modal.classList.add('open'), 10);
        }

        function closeMessageModal() {
            const modal = document.getElementById('message-modal');
            if (!modal) return;
            modal.classList.remove('open');
            setTimeout(() => {
                modal.style.display = 'none';
            }, 320);
        }

        function fallbackCopyText(text) {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            textarea.style.left = '-9999px'; // Tambahkan ini untuk pastikan tidak terlihat
            textarea.style.top = '0';
            textarea.setAttribute('readonly', ''); // Tambahkan readonly
            document.body.appendChild(textarea);

            // Pilih teks dengan lebih hati-hati
            textarea.select();
            textarea.setSelectionRange(0, 99999); // Untuk mobile devices

            try {
                const successful = document.execCommand('copy');
                if (!successful) {
                    throw new Error('Copy command failed');
                }
            } catch (err) {
                console.error('Fallback copy gagal', err);
                return false;
            } finally {
                document.body.removeChild(textarea);
            }
            return true;
        }

        function copyMessageToClipboard(e) {
            // Cegah event bubbling jika perlu
            e.preventDefault();
            e.stopPropagation();
            
            const button = e.target.closest('button');
            if (!button) return;
            
            const originalHTML = button.innerHTML;
            const buttonText = button.querySelector('.button-text') || button;

            // Cek apakah currentMessageText ada dan valid
            if (!currentMessageText || typeof currentMessageText !== 'string' || currentMessageText.trim() === '') {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Pesan kosong atau tidak valid'
                });
                return;
            }

            // Simpan text yang akan dicopy
            const textToCopy = currentMessageText.trim();

            // Tampilkan loading state
            if (buttonText.textContent) {
                buttonText.textContent = 'Menyalin...';
            }
            button.disabled = true;

            // Fungsi untuk reset button state
            const resetButton = () => {
                if (buttonText.textContent) {
                    buttonText.textContent = originalHTML;
                }
                button.disabled = false;
            };

            // Fungsi untuk menampilkan sukses
            const showSuccess = () => {
                if (buttonText.textContent) {
                    buttonText.textContent = '✓ Disalin!';
                }
                
                // Reset setelah 2 detik
                setTimeout(() => {
                    resetButton();
                }, 2000);
                
                // Tampilkan notifikasi sukses (opsional)
                Swal.fire({
                    icon: 'success',
                    title: 'Berhasil!',
                    text: 'Pesan telah disalin ke clipboard',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2000,
                    timerProgressBar: true
                });
            };

            // Fungsi untuk menangani error
            const handleError = (error) => {
                console.error('Gagal menyalin:', error);
                resetButton();
                Swal.fire({
                    icon: 'error',
                    title: 'Gagal',
                    text: 'Tidak dapat menyalin pesan. Silakan coba lagi.'
                });
            };

            // Coba Clipboard API modern (hanya di HTTPS)
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(textToCopy)
                    .then(() => {
                        showSuccess();
                    })
                    .catch((err) => {
                        console.warn('Clipboard API gagal, mencoba fallback:', err);
                        // Coba fallback method
                        if (fallbackCopyText(textToCopy)) {
                            showSuccess();
                        } else {
                            handleError(err);
                        }
                    });
            } else {
                // Gunakan fallback method untuk HTTP atau browser lama
                if (fallbackCopyText(textToCopy)) {
                    showSuccess();
                } else {
                    handleError(new Error('Fallback method failed'));
                }
            }
        }

        // Tambahkan juga listener yang lebih baik
        document.addEventListener('DOMContentLoaded', function() {
            // Pastikan semua button dengan class copy-button mendapatkan event listener
            const copyButtons = document.querySelectorAll('.copy-button');
            copyButtons.forEach(button => {
                // Hapus listener lama jika ada
                button.removeEventListener('click', copyMessageToClipboard);
                // Tambah listener baru
                button.addEventListener('click', copyMessageToClipboard);
            });
        });
        // Event listener untuk menangani submit form tambah pelanggan
        document.getElementById('add-customer-form').addEventListener('submit', function(event) {
            event.preventDefault(); // Mencegah form submit cara biasa

            const formData = new FormData(this);
            const submitButton = this.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = 'Menyimpan...';

            fetch('add_data_salam.php', {
                method: 'POST',
                body: formData
            })
            .then(async response => {
                const text = await response.text();
                let data = null;
                try { data = JSON.parse(text); } catch(e) { data = null; }
                if (!response.ok) {
                    const msg = data && data.message ? data.message : (text || 'Terjadi kesalahan server');
                    throw new Error(msg);
                }
                return data;
            })
            .then(data => {
                if (data && data.success) {
                    Swal.fire('Berhasil!', data.message || 'Data pelanggan baru telah ditambahkan.', 'success');
                    closeAddModal();
                    loadData(1, '', currentFilter); // Muat ulang data ke halaman pertama
                    loadStats(); // Muat ulang statistik
                    loadWilayahFilterOptions();
                } else {
                    Swal.fire('Gagal!', data && data.message ? data.message : 'Terjadi kesalahan.', 'error');
                }
            })
            .catch(error => {
                Swal.fire('Error!', error.message || 'Tidak dapat terhubung ke server.', 'error');
            })
            .finally(() => {
                submitButton.disabled = false;
                submitButton.innerHTML = 'Simpan';
            });
        });

        const importCustomerForm = document.getElementById('import-customer-form');

        if (importCustomerForm) {
            importCustomerForm.addEventListener('submit', function(event) {
                event.preventDefault();

                const fileInput = this.querySelector('input[name="file_import"]');

                if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                    Swal.fire('Belum ada file', 'Pilih file Excel .xlsx terlebih dahulu.', 'warning');
                    return;
                }

                const submitButton = this.querySelector('button[type="submit"]');
                const originalHtml = submitButton.innerHTML;

                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Mengimpor...';

                const formData = new FormData(this);

                fetch('import_pelanggan_salam.php', {
                    method: 'POST',
                    body: formData
                })
                .then(async response => {
                    const text = await response.text();
                    let data = null;

                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        data = null;
                    }

                    if (!response.ok) {
                        const message = data && data.message
                            ? data.message
                            : (text || 'Terjadi kesalahan server saat import.');

                        throw new Error(message);
                    }

                    return data;
                })
                .then(data => {
                    if (!data || !data.success) {
                        throw new Error(
                            data && data.message
                                ? data.message
                                : 'Import gagal.'
                        );
                    }

                    const errors = Array.isArray(data.errors) ? data.errors : [];

                    let detail =
                        `${data.message || 'Import selesai.'}` +
                        `\nBerhasil: ${Number(data.imported || 0)}` +
                        `\nDilewati: ${Number(data.skipped || 0)}`;

                    if (errors.length > 0) {
                        detail += '\n\nCatatan:\n- ' + errors.slice(0, 10).join('\n- ');

                        if (errors.length > 10) {
                            detail += `\n- dan ${errors.length - 10} catatan lainnya`;
                        }
                    }

                    Swal.fire({
                        icon: Number(data.imported || 0) > 0 ? 'success' : 'warning',
                        title: 'Import Excel Selesai',
                        text: detail
                    });

                    if (Number(data.imported || 0) > 0) {
                        this.reset();
                        closeAddModal();
                        currentPage = 1;
                        loadData(1, '', currentFilter);
                        loadStats();
                        loadWilayahFilterOptions();
                    }
                })
                .catch(error => {
                    Swal.fire(
                        'Import gagal',
                        error.message || 'Tidak dapat memproses file Excel.',
                        'error'
                    );
                })
                .finally(() => {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalHtml;
                });
            });
        }
    </script>


<script>
/* Menyesuaikan label kartu mobile dengan kolom tabel yang sedang tampil. */
(function () {
    function pasangLabelKartuMobile() {
        var table = document.querySelector('#table-container .modern-table');
        if (!table) return;

        var headers = Array.prototype.slice.call(table.querySelectorAll('thead th')).map(function (th) {
            return (th.textContent || '').replace(/\s+/g, ' ').trim();
        });

        var rows = table.querySelectorAll('tbody tr');
        Array.prototype.forEach.call(rows, function (tr) {
            var tds = Array.prototype.filter.call(tr.children, function (child) {
                return child && child.tagName === 'TD';
            });

            if (tds.length === 1 && tds[0].hasAttribute('colspan')) return;

            tds.forEach(function (td, index) {
                td.setAttribute('data-label', headers[index] || '');
            });
        });
    }

    function mulaiLabelKartuMobile() {
        pasangLabelKartuMobile();
        var container = document.getElementById('table-container');
        if (!container || container.dataset.mobileCardObserver === '1') return;

        var observer = new MutationObserver(function () {
            pasangLabelKartuMobile();
        });
        observer.observe(container, { childList: true, subtree: true });
        container.dataset.mobileCardObserver = '1';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mulaiLabelKartuMobile);
    } else {
        mulaiLabelKartuMobile();
    }
})();
</script>

</body>
</html>