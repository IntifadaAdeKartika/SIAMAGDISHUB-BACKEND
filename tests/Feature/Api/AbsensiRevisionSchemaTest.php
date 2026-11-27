<?php

namespace Tests\Feature\Api;

use App\Models\Absensi;
use App\Models\Pengguna;
use App\Models\PesertaMagang;
use App\Models\UnitKerja;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AbsensiRevisionSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const KOLOM_REVISI = [
        'alasan_penolakan',
        'jumlah_resubmit',
        'riwayat_penolakan',
        'catatan_pembimbing',
        'revisi_diminta',
        'revisi_diminta_pada',
        'revisi_dikirim_pada',
        'riwayat_revisi',
    ];

    public function test_kolom_dan_index_revisi_absensi_tersedia_setelah_migration(): void
    {
        foreach (self::KOLOM_REVISI as $kolom) {
            $this->assertTrue(
                Schema::hasColumn('absensi', $kolom),
                "Kolom absensi.{$kolom} belum tersedia setelah migration.",
            );
        }

        $this->assertTrue(Schema::hasColumn('notifikasi', 'id_absensi'));
        $this->assertTrue(Schema::hasIndex('absensi', 'absensi_status_revisi_index'));
    }

    public function test_migration_aman_dijalankan_ulang_dan_melengkapi_kolom_yang_hilang(): void
    {
        $absensi = $this->buatAbsensi();

        Schema::table('absensi', function (Blueprint $table): void {
            $table->dropIndex('absensi_status_revisi_index');
            $table->dropColumn(['catatan_pembimbing', 'revisi_dikirim_pada']);
        });

        $this->assertFalse(Schema::hasColumn('absensi', 'catatan_pembimbing'));
        $this->assertFalse(Schema::hasColumn('absensi', 'revisi_dikirim_pada'));
        $this->assertFalse(Schema::hasIndex('absensi', 'absensi_status_revisi_index'));

        $migration = require database_path('migrations/2026_11_27_100000_ensure_absensi_revision_schema.php');
        $migration->up();
        $migration->up();

        foreach (self::KOLOM_REVISI as $kolom) {
            $this->assertTrue(Schema::hasColumn('absensi', $kolom));
        }

        $this->assertTrue(Schema::hasIndex('absensi', 'absensi_status_revisi_index'));

        $tersimpan = Absensi::query()->find($absensi->id);

        $this->assertNotNull($tersimpan);
        $this->assertSame('absensi/selfie.jpg', $tersimpan->foto_selfie);
        $this->assertSame('approved', $tersimpan->status_verifikasi);
        $this->assertSame('Catatan revisi lama.', $tersimpan->riwayat_revisi[0]['catatan']);
    }

    private function buatAbsensi(): Absensi
    {
        $unitKerja = UnitKerja::create([
            'kode_unit' => 'UNIT-SCHEMA',
            'nama_unit' => 'Unit Schema',
            'tipe' => 'unit',
            'status' => 'aktif',
        ]);

        $pengguna = Pengguna::create([
            'username' => 'peserta-schema',
            'nama_lengkap' => 'Peserta Schema',
            'password' => 'password',
            'role' => 'peserta_magang',
            'status' => 'aktif',
        ]);

        $peserta = PesertaMagang::create([
            'id_pengguna' => $pengguna->id,
            'id_unit_kerja' => $unitKerja->id,
            'jenis_peserta' => 'siswa',
            'prodi_jurusan' => 'Teknik Kendaraan',
            'asal_sekolah_kampus' => 'SMK Schema',
            'kelas_semester' => 'XII',
            'status_peserta' => 'aktif',
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31',
            'status_dokumen' => 'pending',
        ]);

        return Absensi::create([
            'id_peserta_magang' => $peserta->id,
            'id_unit_kerja' => $unitKerja->id,
            'tanggal_jam' => '2026-11-27 08:00:00',
            'foto_selfie' => 'absensi/selfie.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'masuk',
            'status_verifikasi' => 'approved',
            'riwayat_revisi' => [
                [
                    'bagian' => 'masuk',
                    'catatan' => 'Catatan revisi lama.',
                    'status' => 'disetujui',
                ],
            ],
        ]);
    }
}
