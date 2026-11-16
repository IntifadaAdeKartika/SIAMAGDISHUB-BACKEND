<?php

namespace Tests\Feature\Api\Pembimbing;

use App\Models\Absensi;
use App\Models\Pembimbing;
use App\Models\Pengguna;
use App\Models\PesertaMagang;
use App\Models\UnitKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VerifikasiAbsensiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pembimbing_can_verify_absensi_with_uploaded_signature_image(): void
    {
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);

        $pembimbing = $this->createPembimbing();
        $absensi = $this->createAbsensi();
        $signature = UploadedFile::fake()->createWithContent(
            'paraf.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l2cAAAAASUVORK5CYII='),
        );

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->post("/backend-api/verifikasi-absensi/{$absensi->id}", [
                'paraf_digital' => $signature,
                'status_verifikasi' => 'approved',
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status_verifikasi', 'approved');

        $verifiedAbsensi = $absensi->fresh();
        $this->assertSame('approved', $verifiedAbsensi->status_verifikasi);
        $this->assertNotNull($verifiedAbsensi->paraf_pembimbing);
        Storage::disk('public')->assertExists($verifiedAbsensi->paraf_pembimbing);
        $this->assertDatabaseHas('log_aktivitas', [
            'id_pengguna' => $pembimbing->id_pengguna,
            'aksi' => 'VERIFIKASI_ABSENSI',
        ]);
    }

    public function test_pembimbing_can_verify_absensi_with_base64_signature(): void
    {
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);

        $pembimbing = $this->createPembimbing();
        $absensi = $this->createAbsensi();
        $signature = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l2cAAAAASUVORK5CYII=';

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->postJson("/backend-api/verifikasi-absensi/{$absensi->id}", [
                'paraf_digital' => $signature,
                'status_verifikasi' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('data.status_verifikasi', 'approved');

        $verifiedAbsensi = $absensi->fresh();
        $this->assertNotNull($verifiedAbsensi->paraf_pembimbing);
        Storage::disk('public')->assertExists($verifiedAbsensi->paraf_pembimbing);
    }

    public function test_verifikasi_requires_signature_image(): void
    {
        $pembimbing = $this->createPembimbing();
        $absensi = $this->createAbsensi();

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->postJson("/backend-api/verifikasi-absensi/{$absensi->id}", [
                'status_verifikasi' => 'approved',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paraf_digital');

        $this->assertDatabaseHas('absensi', [
            'id' => $absensi->id,
            'status_verifikasi' => 'pending',
            'paraf_pembimbing' => null,
        ]);
    }

    public function test_verifikasi_rejects_non_image_signature_upload(): void
    {
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);

        $pembimbing = $this->createPembimbing();
        $absensi = $this->createAbsensi();

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->post("/backend-api/verifikasi-absensi/{$absensi->id}", [
                'paraf_digital' => UploadedFile::fake()->create('paraf.txt', 1, 'text/plain'),
                'status_verifikasi' => 'approved',
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paraf_digital');

        $this->assertDatabaseHas('absensi', [
            'id' => $absensi->id,
            'status_verifikasi' => 'pending',
            'paraf_pembimbing' => null,
        ]);
        Storage::disk('public')->assertDirectoryEmpty('paraf/absensi');
    }

    private function createPembimbing(): Pembimbing
    {
        $unitKerja = UnitKerja::create([
            'kode_unit' => 'UNIT-VERIFY',
            'nama_unit' => 'Unit Verifikasi',
            'tipe' => 'unit',
            'status' => 'aktif',
        ]);
        $pengguna = Pengguna::create([
            'username' => 'pembimbing-verify',
            'nama_lengkap' => 'Pembimbing Verifikasi',
            'password' => 'password',
            'role' => 'pembimbing',
            'status' => 'aktif',
        ]);

        return Pembimbing::create([
            'id_pengguna' => $pengguna->id,
            'id_unit_kerja' => $unitKerja->id,
            'nip' => 'NIP-VERIFY',
            'jabatan' => 'Pembimbing Lapangan',
            'status_pembimbing' => 'aktif',
        ]);
    }

    private function createAbsensi(): Absensi
    {
        $pengguna = Pengguna::create([
            'username' => 'peserta-verify',
            'nama_lengkap' => 'Peserta Verifikasi',
            'password' => 'password',
            'role' => 'peserta_magang',
            'status' => 'aktif',
        ]);
        $peserta = PesertaMagang::create([
            'id_pengguna' => $pengguna->id,
            'jenis_peserta' => 'siswa',
            'prodi_jurusan' => 'Teknik Kendaraan',
            'asal_sekolah_kampus' => 'SMK Contoh',
            'kelas_semester' => 'XII',
            'status_peserta' => 'aktif',
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31',
            'status_dokumen' => 'pending',
        ]);

        return Absensi::create([
            'id_peserta_magang' => $peserta->id,
            'tanggal_jam' => '2026-11-12 08:00:00',
            'foto_selfie' => 'absensi/selfie.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'masuk',
            'status_verifikasi' => 'pending',
        ]);
    }
}
