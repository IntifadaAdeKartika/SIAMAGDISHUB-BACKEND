<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absensi', function (Blueprint $table): void {
            $table->text('foto_selfie')->change();
            $table->text('paraf_pembimbing')->nullable()->change();
        });

        Schema::table('logbook', function (Blueprint $table): void {
            $table->text('foto_kegiatan')->nullable()->change();
            $table->text('paraf_pembimbing')->nullable()->change();
        });

        Schema::table('pengajuan_izin', function (Blueprint $table): void {
            $table->text('file_surat')->nullable()->change();
        });

        Schema::table('peserta_magang', function (Blueprint $table): void {
            $table->text('file_surat_permohonan')->nullable()->change();
            $table->text('file_surat_balasan')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('absensi', function (Blueprint $table): void {
            $table->string('foto_selfie')->change();
            $table->string('paraf_pembimbing')->nullable()->change();
        });

        Schema::table('logbook', function (Blueprint $table): void {
            $table->string('foto_kegiatan')->nullable()->change();
            $table->string('paraf_pembimbing')->nullable()->change();
        });

        Schema::table('pengajuan_izin', function (Blueprint $table): void {
            $table->string('file_surat')->nullable()->change();
        });

        Schema::table('peserta_magang', function (Blueprint $table): void {
            $table->string('file_surat_permohonan')->nullable()->change();
            $table->string('file_surat_balasan')->nullable()->change();
        });
    }
};
