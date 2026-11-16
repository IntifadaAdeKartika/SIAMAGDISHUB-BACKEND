<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pembimbing', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('id_pengguna')->unique()->constrained('pengguna')->cascadeOnDelete();
            $table->foreignId('id_unit_kerja')->nullable()->constrained('unit_kerja')->nullOnDelete();
            $table->string('nip')->unique();
            $table->string('jabatan');
            $table->string('status_pembimbing')->default('aktif');
            $table->date('tanggal_mulai_tugas')->nullable();
            $table->date('tanggal_selesai_tugas')->nullable();
            $table->timestamps();
        });

        // Tambahkan foreign key constraint untuk id_pembimbing di tabel unit_kerja
        Schema::table('unit_kerja', function (Blueprint $table): void {
            $table->foreign('id_pembimbing')->references('id')->on('pembimbing')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('unit_kerja', function (Blueprint $table): void {
            $table->dropForeign(['id_pembimbing']);
        });

        Schema::dropIfExists('pembimbing');
    }
};
