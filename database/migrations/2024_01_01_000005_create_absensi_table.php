<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('absensi', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('id_peserta_magang')->constrained('peserta_magang')->cascadeOnDelete();
            $table->foreignId('id_unit_kerja')->nullable()->constrained('unit_kerja')->nullOnDelete();
            $table->timestamp('tanggal_jam');
            $table->string('foto_selfie');
            $table->string('lokasi')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->enum('status', ['masuk', 'keluar']);
            $table->text('keterangan')->nullable();
            $table->string('paraf_pembimbing')->nullable();
            $table->foreignId('diverifikasi_oleh')->nullable()->constrained('pembimbing')->nullOnDelete();
            $table->timestamp('diverifikasi_pada')->nullable();
            $table->enum('status_verifikasi', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamps();

            $table->unique(['id_peserta_magang', 'tanggal_jam']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('absensi');
    }
};
