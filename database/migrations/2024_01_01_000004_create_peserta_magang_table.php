<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('peserta_magang', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('id_pengguna')->unique()->constrained('pengguna')->cascadeOnDelete();
            $table->foreignId('id_unit_kerja')->nullable()->constrained('unit_kerja')->nullOnDelete();
            $table->enum('jenis_peserta', ['siswa', 'mahasiswa']);
            $table->string('prodi_jurusan');
            $table->string('asal_sekolah_kampus');
            $table->string('kelas_semester');
            $table->string('pembimbing_akademik')->nullable();
            $table->string('nip_pembimbing_akademik')->nullable();
            $table->string('no_hp_pembimbing_akademik')->nullable();
            $table->enum('status_peserta', ['aktif', 'selesai', 'keluar', 'menunggu'])->default('aktif');
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->string('file_surat_permohonan')->nullable();
            $table->string('file_surat_balasan')->nullable();
            $table->enum('status_dokumen', ['pending', 'lengkap'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('peserta_magang');
    }
};
