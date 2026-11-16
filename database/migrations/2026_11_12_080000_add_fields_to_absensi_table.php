<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('absensi', function (Blueprint $table) {
            $table->text('alasan_penolakan')->nullable()->after('status_verifikasi');
            $table->unsignedInteger('jumlah_resubmit')->default(0)->after('alasan_penolakan');
        });
    }

    public function down(): void
    {
        Schema::table('absensi', function (Blueprint $table) {
            $table->dropColumn(['alasan_penolakan', 'jumlah_resubmit']);
        });
    }
};
