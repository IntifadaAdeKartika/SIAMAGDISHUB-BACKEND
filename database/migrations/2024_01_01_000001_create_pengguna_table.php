<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengguna', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->unique();
            $table->string('nama_lengkap');
            $table->string('password');
            $table->enum('role', ['admin', 'peserta_magang', 'pembimbing']);
            $table->string('no_hp')->nullable();
            $table->string('status')->default('aktif');
            $table->foreignId('dibuat_oleh')->nullable()->constrained('pengguna')->nullOnDelete();
            $table->timestamp('terakhir_login')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengguna');
    }
};
