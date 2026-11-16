<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('peserta_magang', function (Blueprint $table): void {
            $table->enum('status_peserta', ['aktif', 'selesai', 'keluar', 'menunggu', 'pending'])
                ->default('aktif')
                ->change();
        });

        DB::table('peserta_magang')
            ->where('status_peserta', 'menunggu')
            ->update(['status_peserta' => 'pending']);

        Schema::table('peserta_magang', function (Blueprint $table): void {
            $table->enum('status_peserta', ['aktif', 'selesai', 'keluar', 'pending'])
                ->default('aktif')
                ->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('peserta_magang', function (Blueprint $table): void {
            $table->enum('status_peserta', ['aktif', 'selesai', 'keluar', 'pending', 'menunggu'])
                ->default('aktif')
                ->change();
        });

        DB::table('peserta_magang')
            ->where('status_peserta', 'pending')
            ->update(['status_peserta' => 'menunggu']);

        Schema::table('peserta_magang', function (Blueprint $table): void {
            $table->enum('status_peserta', ['aktif', 'selesai', 'keluar', 'menunggu'])
                ->default('aktif')
                ->change();
        });
    }
};
