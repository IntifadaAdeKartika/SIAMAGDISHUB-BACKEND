<?php

use App\Http\Controllers\Api\Admin\AdminAbsensiController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminLogAktivitasController;
use App\Http\Controllers\Api\Admin\AdminLogbookController;
use App\Http\Controllers\Api\Admin\AdminPengajuanIzinController;
use App\Http\Controllers\Api\Admin\ExportLaporanController;
use App\Http\Controllers\Api\Admin\MonitoringDokumenController;
use App\Http\Controllers\Api\Admin\PembimbingController;
use App\Http\Controllers\Api\Admin\PesertaMagangController;
use App\Http\Controllers\Api\Admin\ResetPasswordController;
use App\Http\Controllers\Api\Admin\UnitKerjaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NotifikasiController;
use App\Http\Controllers\Api\Pembimbing\ApproveIzinController;
use App\Http\Controllers\Api\Pembimbing\DokumenPembimbingController;
use App\Http\Controllers\Api\Pembimbing\KoreksiLogbookController;
use App\Http\Controllers\Api\Pembimbing\LaporanPembimbingController;
use App\Http\Controllers\Api\Pembimbing\MonitoringController;
use App\Http\Controllers\Api\Pembimbing\PembimbingLogbookController;
use App\Http\Controllers\Api\Pembimbing\PembimbingPengajuanIzinController;
use App\Http\Controllers\Api\Pembimbing\PesertaBimbinganController;
use App\Http\Controllers\Api\Pembimbing\RekanPembimbingController;
use App\Http\Controllers\Api\Pembimbing\VerifikasiAbsensiController;
use App\Http\Controllers\Api\Peserta\AbsensiController;
use App\Http\Controllers\Api\Peserta\DokumenPesertaController;
use App\Http\Controllers\Api\Peserta\LogbookController;
use App\Http\Controllers\Api\Peserta\PengajuanIzinController;
use App\Http\Controllers\Api\Peserta\RiwayatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SIAMAGDISHUB Backend API Routes
| Prefix: /backend-api (dikonfigurasi via bootstrap/app.php)
|--------------------------------------------------------------------------
*/

// ==========================================
// A. AUTH & UMUM (Tanpa Middleware Auth)
// ==========================================
Route::post('/login', [AuthController::class, 'login']);

// Group Rute Terproteksi Sanctum & Session Inactivity Timeout (1 menit)
Route::middleware(['auth:sanctum', 'inactivity'])->group(function (): void {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/notifikasi', [NotifikasiController::class, 'index']);
    Route::put('/notifikasi/{id}/read', [NotifikasiController::class, 'markAsRead'])->whereNumber('id');
    Route::put('/notifikasi/read-all', [NotifikasiController::class, 'markAllAsRead']);

    // ==========================================
    // B. ADMIN (Role: admin)
    // ==========================================
    Route::middleware('role:admin')->group(function (): void {
        // Monitoring Read-Only
        Route::get('/admin/dashboard', [AdminDashboardController::class, 'index']);
        Route::get('/admin/absensi', [AdminAbsensiController::class, 'index']);
        Route::get('/admin/logbook', [AdminLogbookController::class, 'index']);
        Route::get('/admin/pengajuan-izin', [AdminPengajuanIzinController::class, 'index']);
        Route::get('/admin/log-aktivitas', [AdminLogAktivitasController::class, 'index']);

        // CRUD Master Data
        Route::apiResource('admin/peserta-magang', PesertaMagangController::class);
        Route::apiResource('admin/pembimbing', PembimbingController::class);
        Route::apiResource('admin/unit-kerja', UnitKerjaController::class);

        // Reset Password Pengguna
        Route::post('/reset-password/{id}', [ResetPasswordController::class, 'reset']);

        // Export Laporan
        Route::get('/admin/export/absensi/pdf', [ExportLaporanController::class, 'exportPdf']);
        Route::get('/admin/export/absensi/excel', [ExportLaporanController::class, 'exportExcel']);

        // Monitoring Dokumen (Strict Read-Only)
        Route::get('/admin/dokumen', [MonitoringDokumenController::class, 'index']);
        Route::get('/admin/dokumen/surat-permohonan/{id_peserta_magang}', [MonitoringDokumenController::class, 'downloadSuratPermohonan']);
        Route::get('/admin/dokumen/surat-balasan/{id_peserta_magang}', [MonitoringDokumenController::class, 'downloadSuratBalasan']);
    });

    // ==========================================
    // C. PESERTA MAGANG (Role: peserta_magang)
    // ==========================================
    Route::middleware('role:peserta_magang')->group(function (): void {
        // Presensi & Geofencing
        Route::post('/check-in', [AbsensiController::class, 'checkIn']);
        Route::post('/check-out', [AbsensiController::class, 'checkOut']);
        Route::get('/absensi/today', [AbsensiController::class, 'today']);

        // Logbook Harian
        Route::get('/logbook', [LogbookController::class, 'index']);
        Route::post('/logbook', [LogbookController::class, 'store']);
        Route::get('/logbook/{logbook}', [LogbookController::class, 'show']);
        Route::put('/logbook/{logbook}', [LogbookController::class, 'update']);

        // Pengajuan Izin / Sakit
        Route::get('/pengajuan-izin', [PengajuanIzinController::class, 'index']);
        Route::post('/pengajuan-izin', [PengajuanIzinController::class, 'store']);
        Route::put('/pengajuan-izin/{izin}', [PengajuanIzinController::class, 'update']);
        Route::put('/pengajuan-izin/{izin}/cancel', [PengajuanIzinController::class, 'cancel']);

        // Manajemen Dokumen Peserta
        Route::post('/upload-surat-permohonan', [DokumenPesertaController::class, 'uploadSuratPermohonan']);
        Route::get('/download-surat-permohonan', [DokumenPesertaController::class, 'downloadSuratPermohonan']);
        Route::get('/download-surat-balasan', [DokumenPesertaController::class, 'downloadSuratBalasan']);

        // Riwayat Lengkap
        Route::get('/riwayat', [RiwayatController::class, 'index']);
    });

    // ==========================================
    // D. PEMBIMBING LAPANGAN (Role: pembimbing)
    // ==========================================
    Route::middleware('role:pembimbing')->group(function (): void {
        Route::get('/pembimbing/unit-kerja', [UnitKerjaController::class, 'listForFilter']);
        Route::get('/pembimbing/rekan', [RekanPembimbingController::class, 'index']);
        Route::get('/pembimbing/peserta-bimbingan', [PesertaBimbinganController::class, 'index']);
        Route::get('/pembimbing/rekap-absensi', [MonitoringController::class, 'rekapAbsensi']);
        Route::get('/pembimbing/laporan', [LaporanPembimbingController::class, 'index']);

        // Antrean logbook dan pengajuan izin peserta pada unit kerja pembimbing
        Route::get('/pembimbing/logbook', [PembimbingLogbookController::class, 'index']);
        Route::get('/pembimbing/pengajuan-izin', [PembimbingPengajuanIzinController::class, 'index']);

        // Monitoring Absensi
        Route::get('/monitoring', [MonitoringController::class, 'index']);

        // Verifikasi Absensi dengan Paraf Digital
        Route::post('/verifikasi-absensi/{absensi}', [VerifikasiAbsensiController::class, 'verifikasi']);

        // Koreksi / Feedback Logbook
        Route::put('/koreksi-logbook/{logbook}', [KoreksiLogbookController::class, 'koreksi']);

        // Persetujuan Izin
        Route::post('/approve-izin/{pengajuanIzin}', [ApproveIzinController::class, 'approve']);

        // Manajemen Dokumen Pembimbing
        Route::get('/download-surat-permohonan/{id_peserta_magang}', [DokumenPembimbingController::class, 'downloadSuratPermohonan']);
        Route::post('/upload-surat-balasan/{id_peserta_magang}', [DokumenPembimbingController::class, 'uploadSuratBalasan']);
        Route::get('/download-surat-balasan/{id_peserta_magang}', [DokumenPembimbingController::class, 'downloadSuratBalasan']);
    });

});
