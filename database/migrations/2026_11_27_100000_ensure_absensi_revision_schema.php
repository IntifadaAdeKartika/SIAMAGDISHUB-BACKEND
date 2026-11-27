<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Menjamin kelengkapan kolom fitur revisi absensi pada database yang
 * mungkin sudah pernah menjalankan sebagian migration.
 *
 * Migration ini hanya menambah kolom/index yang belum ada dan tidak
 * mengubah maupun menghapus data yang sudah tersimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        $this->tambahkanKolom('absensi', 'alasan_penolakan', fn (Blueprint $table) => $table->text('alasan_penolakan')->nullable());
        $this->tambahkanKolom('absensi', 'jumlah_resubmit', fn (Blueprint $table) => $table->unsignedInteger('jumlah_resubmit')->default(0));
        $this->tambahkanKolom('absensi', 'riwayat_penolakan', fn (Blueprint $table) => $driver === 'pgsql'
            ? $table->jsonb('riwayat_penolakan')->nullable()
            : $table->json('riwayat_penolakan')->nullable());

        $this->tambahkanKolom('absensi', 'catatan_pembimbing', fn (Blueprint $table) => $table->text('catatan_pembimbing')->nullable());
        $this->tambahkanKolom('absensi', 'revisi_diminta', fn (Blueprint $table) => $table->boolean('revisi_diminta')->default(false));
        $this->tambahkanKolom('absensi', 'revisi_diminta_pada', fn (Blueprint $table) => $table->timestamp('revisi_diminta_pada')->nullable());
        $this->tambahkanKolom('absensi', 'revisi_dikirim_pada', fn (Blueprint $table) => $table->timestamp('revisi_dikirim_pada')->nullable());
        $this->tambahkanKolom('absensi', 'riwayat_revisi', fn (Blueprint $table) => $driver === 'pgsql'
            ? $table->jsonb('riwayat_revisi')->nullable()
            : $table->json('riwayat_revisi')->nullable());

        // Notifikasi hasil revisi menyimpan referensi absensi.
        $this->tambahkanKolom('notifikasi', 'id_absensi', fn (Blueprint $table) => $table->unsignedBigInteger('id_absensi')->nullable());

        if (Schema::hasTable('absensi')
            && Schema::hasColumn('absensi', 'status_verifikasi')
            && Schema::hasColumn('absensi', 'revisi_diminta')
            && ! Schema::hasIndex('absensi', 'absensi_status_revisi_index')) {
            Schema::table('absensi', function (Blueprint $table): void {
                $table->index(['status_verifikasi', 'revisi_diminta'], 'absensi_status_revisi_index');
            });
        }
    }

    /**
     * Sengaja tidak menghapus kolom: rollback tidak boleh menghilangkan
     * data absensi maupun riwayat revisi.
     */
    public function down(): void
    {
        //
    }

    private function tambahkanKolom(string $tabel, string $kolom, Closure $definisi): void
    {
        if (! Schema::hasTable($tabel) || Schema::hasColumn($tabel, $kolom)) {
            return;
        }

        Schema::table($tabel, function (Blueprint $table) use ($definisi): void {
            $definisi($table);
        });
    }
};
