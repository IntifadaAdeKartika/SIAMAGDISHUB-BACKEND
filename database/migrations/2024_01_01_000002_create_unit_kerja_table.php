<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_kerja', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('id_induk')->nullable()->constrained('unit_kerja')->nullOnDelete();
            $table->unsignedBigInteger('id_pembimbing')->nullable()->unique();
            $table->string('kode_unit')->unique();
            $table->string('nama_unit');
            $table->enum('tipe', ['bidang', 'seksi', 'sub_bagian', 'unit']);
            $table->string('status')->default('aktif');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_kerja');
    }
};
