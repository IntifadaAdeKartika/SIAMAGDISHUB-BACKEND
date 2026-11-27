<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absensi', function (Blueprint $table): void {
            $table->text('catatan_pembimbing')->nullable()->after('alasan_penolakan');
            $table->boolean('revisi_diminta')->default(false)->after('catatan_pembimbing');
            $table->timestamp('revisi_diminta_pada')->nullable()->after('revisi_diminta');
            $table->timestamp('revisi_dikirim_pada')->nullable()->after('revisi_diminta_pada');

            $riwayat = DB::connection()->getDriverName() === 'pgsql'
                ? $table->jsonb('riwayat_revisi')
                : $table->json('riwayat_revisi');

            $riwayat->nullable()->after('revisi_dikirim_pada');

            $table->index(['status_verifikasi', 'revisi_diminta'], 'absensi_status_revisi_index');
        });
    }

    public function down(): void
    {
        Schema::table('absensi', function (Blueprint $table): void {
            $table->dropIndex('absensi_status_revisi_index');
            $table->dropColumn([
                'catatan_pembimbing',
                'revisi_diminta',
                'revisi_diminta_pada',
                'revisi_dikirim_pada',
                'riwayat_revisi',
            ]);
        });
    }
};
