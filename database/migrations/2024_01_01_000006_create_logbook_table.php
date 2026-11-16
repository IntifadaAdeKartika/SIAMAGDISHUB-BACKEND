<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logbook', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('id_peserta_magang')->constrained('peserta_magang')->cascadeOnDelete();
            $table->foreignId('id_absensi')->nullable()->constrained('absensi')->nullOnDelete();
            $table->date('tanggal');
            $table->time('jam_mulai');
            $table->time('jam_selesai');
            $table->text('kegiatan');
            $table->string('foto_kegiatan')->nullable();
            $table->enum('status_verifikasi', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('catatan_pembimbing')->nullable();
            $table->string('paraf_pembimbing')->nullable();
            $table->foreignId('diverifikasi_oleh')->nullable()->constrained('pembimbing')->nullOnDelete();
            $table->timestamp('diverifikasi_pada')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logbook');
    }
};
