<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absensi', function (Blueprint $table): void {
            $table->json('riwayat_penolakan')->nullable();
        });

        Schema::table('notifikasi', function (Blueprint $table): void {
            $table->unsignedBigInteger('id_absensi')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notifikasi', function (Blueprint $table): void {
            $table->dropColumn('id_absensi');
        });

        Schema::table('absensi', function (Blueprint $table): void {
            $table->dropColumn('riwayat_penolakan');
        });
    }
};
