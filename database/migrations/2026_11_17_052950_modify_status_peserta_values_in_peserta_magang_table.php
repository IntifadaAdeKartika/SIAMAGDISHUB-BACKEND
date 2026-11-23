<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        // 1. Hapus CHECK constraint lama (hanya PostgreSQL — SQLite tidak memilikinya).
        if ($driver !== 'sqlite') {
            DB::statement('
                ALTER TABLE peserta_magang
                DROP CONSTRAINT peserta_magang_status_peserta_check
            ');
        }

        // 2. Konversi status lama ke status baru.
        DB::statement("
            UPDATE peserta_magang
            SET status_peserta = CASE
                WHEN status_peserta IN ('menunggu', 'pending')
                    THEN 'belum mulai'
                WHEN status_peserta IN ('selesai', 'keluar')
                    THEN 'tidak aktif'
                ELSE status_peserta
            END
        ");

        // 3. Tambahkan CHECK constraint baru (hanya PostgreSQL).
        if ($driver !== 'sqlite') {
            DB::statement("
                ALTER TABLE peserta_magang
                ADD CONSTRAINT peserta_magang_status_peserta_check
                CHECK (
                    status_peserta IN (
                        'belum mulai',
                        'aktif',
                        'tidak aktif'
                    )
                )
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        // 1. Hapus CHECK constraint baru (hanya PostgreSQL).
        if ($driver !== 'sqlite') {
            DB::statement('
                ALTER TABLE peserta_magang
                DROP CONSTRAINT peserta_magang_status_peserta_check
            ');
        }

        // 2. Kembalikan status ke format lama.
        DB::statement("
            UPDATE peserta_magang
            SET status_peserta = CASE
                WHEN status_peserta = 'belum mulai'
                    THEN 'pending'
                WHEN status_peserta = 'tidak aktif'
                    THEN 'selesai'
                ELSE status_peserta
            END
        ");

        // 3. Pulihkan CHECK constraint lama (hanya PostgreSQL).
        if ($driver !== 'sqlite') {
            DB::statement("
                ALTER TABLE peserta_magang
                ADD CONSTRAINT peserta_magang_status_peserta_check
                CHECK (
                    status_peserta IN (
                        'aktif',
                        'selesai',
                        'keluar',
                        'menunggu',
                        'pending'
                    )
                )
            ");
        }
    }
};
