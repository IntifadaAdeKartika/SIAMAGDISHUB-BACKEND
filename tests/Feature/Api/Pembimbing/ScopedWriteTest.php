<?php

namespace Tests\Feature\Api\Pembimbing;

use App\Models\Logbook;
use App\Models\Pembimbing;
use App\Models\PengajuanIzin;
use App\Models\Pengguna;
use App\Models\PesertaMagang;
use App\Models\UnitKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScopedWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_pembimbing_can_update_records_in_their_unit_and_get_verifier_details(): void
    {
        $unitKerja = $this->createUnitKerja('UNIT-OWN');
        $pembimbing = $this->createPembimbing($unitKerja, 'pembimbing-own');
        $peserta = $this->createPeserta($unitKerja, 'peserta-own');
        $logbook = $this->createLogbook($peserta);
        $izin = $this->createPengajuanIzin($peserta);

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->putJson("/backend-api/koreksi-logbook/{$logbook->id}", [
                'catatan_pembimbing' => 'Kegiatan sudah sesuai.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status_verifikasi', 'approved')
            ->assertJsonPath('data.catatan_pembimbing', 'Kegiatan sudah sesuai.')
            ->assertJsonPath('data.verifikator.pengguna.nama_lengkap', 'Pembimbing pembimbing-own');

        $this->assertDatabaseHas('logbook', [
            'id' => $logbook->id,
            'status_verifikasi' => 'approved',
            'catatan_pembimbing' => 'Kegiatan sudah sesuai.',
            'diverifikasi_oleh' => $pembimbing->id,
        ]);

        $this->postJson("/backend-api/approve-izin/{$izin->id}", [
            'status' => 'approved',
            'catatan_pembimbing' => 'Disetujui.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.verifikator.pengguna.nama_lengkap', 'Pembimbing pembimbing-own');

        $this->assertDatabaseHas('pengajuan_izin', [
            'id' => $izin->id,
            'status' => 'approved',
            'catatan_pembimbing' => 'Disetujui.',
            'diverifikasi_oleh' => $pembimbing->id,
        ]);
    }

    public function test_revision_request_via_rejected_status_requires_catatan_pembimbing(): void
    {
        $unitKerja = $this->createUnitKerja('UNIT-REJECT-NOTE');
        $pembimbing = $this->createPembimbing($unitKerja, 'pembimbing-reject-note');
        $peserta = $this->createPeserta($unitKerja, 'peserta-reject-note');
        $logbook = $this->createLogbook($peserta);

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->putJson("/backend-api/koreksi-logbook/{$logbook->id}", [
                'status_verifikasi' => 'rejected',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('catatan_pembimbing');

        $this->assertDatabaseHas('logbook', [
            'id' => $logbook->id,
            'status_verifikasi' => 'pending',
            'catatan_pembimbing' => null,
        ]);
    }

    public function test_approved_logbook_does_not_require_catatan_pembimbing(): void
    {
        $unitKerja = $this->createUnitKerja('UNIT-APPROVED-NO-NOTE');
        $pembimbing = $this->createPembimbing($unitKerja, 'pembimbing-approved-no-note');
        $peserta = $this->createPeserta($unitKerja, 'peserta-approved-no-note');
        $logbook = $this->createLogbook($peserta);

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->putJson("/backend-api/koreksi-logbook/{$logbook->id}", [
                'status_verifikasi' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('data.status_verifikasi', 'approved')
            ->assertJsonPath('data.catatan_pembimbing', null)
            ->assertJsonStructure([
                'data' => [
                    'catatan_pembimbing',
                    'paraf_pembimbing_url',
                ],
            ]);

        $this->assertDatabaseHas('logbook', [
            'id' => $logbook->id,
            'status_verifikasi' => 'approved',
            'catatan_pembimbing' => null,
        ]);
    }

    public function test_pembimbing_revision_request_keeps_status_pending_and_persists_catatan_pembimbing(): void
    {
        $unitKerja = $this->createUnitKerja('UNIT-REVISION');
        $pembimbing = $this->createPembimbing($unitKerja, 'pembimbing-revision');
        $peserta = $this->createPeserta($unitKerja, 'peserta-revision');
        $logbook = $this->createLogbook($peserta);

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->putJson("/backend-api/koreksi-logbook/{$logbook->id}", [
                'status_verifikasi' => 'rejected',
                'catatan_pembimbing' => 'Mohon lengkapi uraian kegiatan.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status_verifikasi', 'pending')
            ->assertJsonPath('data.revisi_diminta', true)
            ->assertJsonPath('data.catatan_pembimbing', 'Mohon lengkapi uraian kegiatan.')
            ->assertJsonStructure([
                'data' => [
                    'catatan_pembimbing',
                    'paraf_pembimbing_url',
                    'revisi_diminta_pada',
                ],
            ]);

        $this->assertDatabaseHas('logbook', [
            'id' => $logbook->id,
            'status_verifikasi' => 'pending',
            'revisi_diminta' => true,
            'catatan_pembimbing' => 'Mohon lengkapi uraian kegiatan.',
            'diverifikasi_oleh' => $pembimbing->id,
        ]);
        $this->assertNotSame('rejected', $logbook->fresh()->status_verifikasi);
    }

    public function test_pembimbing_cannot_update_records_from_another_unit(): void
    {
        $unitOwn = $this->createUnitKerja('UNIT-OWN');
        $unitOther = $this->createUnitKerja('UNIT-OTHER');
        $pembimbing = $this->createPembimbing($unitOwn, 'pembimbing-own');
        $pesertaOther = $this->createPeserta($unitOther, 'peserta-other');
        $logbookOther = $this->createLogbook($pesertaOther);
        $izinOther = $this->createPengajuanIzin($pesertaOther);

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->putJson("/backend-api/koreksi-logbook/{$logbookOther->id}", [
                'catatan_pembimbing' => 'Perubahan tidak diizinkan.',
            ])
            ->assertNotFound();

        $this->postJson("/backend-api/approve-izin/{$izinOther->id}", [
            'status' => 'approved',
        ])->assertNotFound();

        $this->assertDatabaseHas('logbook', [
            'id' => $logbookOther->id,
            'status_verifikasi' => 'pending',
            'catatan_pembimbing' => null,
            'diverifikasi_oleh' => null,
        ]);
        $this->assertDatabaseHas('pengajuan_izin', [
            'id' => $izinOther->id,
            'status' => 'pending',
            'catatan_pembimbing' => null,
            'diverifikasi_oleh' => null,
        ]);
    }

    public function test_pembimbing_uploads_logbook_signature_and_api_returns_its_public_url(): void
    {
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);

        $unitKerja = $this->createUnitKerja('UNIT-SIGNATURE');
        $pembimbing = $this->createPembimbing($unitKerja, 'pembimbing-signature');
        $peserta = $this->createPeserta($unitKerja, 'peserta-signature');
        $logbook = $this->createLogbook($peserta);
        $signature = UploadedFile::fake()->createWithContent(
            'paraf.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l2cAAAAASUVORK5CYII='),
        );

        $response = $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->put("/backend-api/koreksi-logbook/{$logbook->id}", [
                'catatan_pembimbing' => 'Logbook disetujui.',
                'status_verifikasi' => 'approved',
                'paraf_pembimbing' => $signature,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status_verifikasi', 'approved');

        $verifiedLogbook = $logbook->fresh();
        $this->assertNotNull($verifiedLogbook->paraf_pembimbing);
        Storage::disk('public')->assertExists($verifiedLogbook->paraf_pembimbing);
        $publicUrl = Storage::disk('public')->url($verifiedLogbook->paraf_pembimbing);
        $response->assertJsonPath('data.paraf_pembimbing', $publicUrl)
            ->assertJsonPath('data.paraf_pembimbing_url', $publicUrl);
        $this->assertDatabaseHas('logbook', [
            'id' => $logbook->id,
            'paraf_pembimbing' => $verifiedLogbook->paraf_pembimbing,
            'status_verifikasi' => 'approved',
        ]);

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->getJson('/backend-api/pembimbing/logbook')
            ->assertOk()
            ->assertJsonPath('data.data.0.catatan_pembimbing', 'Logbook disetujui.')
            ->assertJsonPath('data.data.0.paraf_pembimbing_url', $publicUrl);

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->getJson('/backend-api/logbook')
            ->assertOk()
            ->assertJsonPath('data.data.0.catatan_pembimbing', 'Logbook disetujui.')
            ->assertJsonPath('data.data.0.paraf_pembimbing', $publicUrl)
            ->assertJsonPath('data.data.0.paraf_pembimbing_url', $publicUrl);
    }

    public function test_pembimbing_can_submit_a_base64_logbook_signature(): void
    {
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);

        $unitKerja = $this->createUnitKerja('UNIT-BASE64-SIGNATURE');
        $pembimbing = $this->createPembimbing($unitKerja, 'pembimbing-base64-signature');
        $peserta = $this->createPeserta($unitKerja, 'peserta-base64-signature');
        $logbook = $this->createLogbook($peserta);

        $response = $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->putJson("/backend-api/koreksi-logbook/{$logbook->id}", [
                'catatan_pembimbing' => 'Logbook disetujui.',
                'paraf_digital' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l2cAAAAASUVORK5CYII=',
            ])
            ->assertOk()
            ->assertJsonPath('data.status_verifikasi', 'approved');

        $verifiedLogbook = $logbook->fresh();
        $this->assertNotNull($verifiedLogbook->paraf_pembimbing);
        Storage::disk('public')->assertExists($verifiedLogbook->paraf_pembimbing);
        $response->assertJsonPath(
            'data.paraf_pembimbing',
            Storage::disk('public')->url($verifiedLogbook->paraf_pembimbing),
        );
    }

    public function test_logbook_correction_rejects_non_image_signature_upload(): void
    {
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);

        $unitKerja = $this->createUnitKerja('UNIT-BAD-SIGNATURE');
        $pembimbing = $this->createPembimbing($unitKerja, 'pembimbing-bad-signature');
        $peserta = $this->createPeserta($unitKerja, 'peserta-bad-signature');
        $logbook = $this->createLogbook($peserta);

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->put("/backend-api/koreksi-logbook/{$logbook->id}", [
                'catatan_pembimbing' => 'Logbook disetujui.',
                'paraf_pembimbing' => UploadedFile::fake()->create('paraf.txt', 1, 'text/plain'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paraf_pembimbing');

        $this->assertDatabaseHas('logbook', [
            'id' => $logbook->id,
            'status_verifikasi' => 'pending',
            'paraf_pembimbing' => null,
        ]);
        Storage::disk('public')->assertDirectoryEmpty('paraf/logbook');
    }

    public function test_logbook_correction_rejects_signature_larger_than_two_megabytes(): void
    {
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);

        $unitKerja = $this->createUnitKerja('UNIT-LARGE-SIGNATURE');
        $pembimbing = $this->createPembimbing($unitKerja, 'pembimbing-large-signature');
        $peserta = $this->createPeserta($unitKerja, 'peserta-large-signature');
        $logbook = $this->createLogbook($peserta);
        $signature = UploadedFile::fake()->createWithContent(
            'paraf.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l2cAAAAASUVORK5CYII='),
        )->size(2049);

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->put("/backend-api/koreksi-logbook/{$logbook->id}", [
                'catatan_pembimbing' => 'Logbook disetujui.',
                'paraf_pembimbing' => $signature,
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paraf_pembimbing');

        $this->assertDatabaseHas('logbook', [
            'id' => $logbook->id,
            'status_verifikasi' => 'pending',
            'paraf_pembimbing' => null,
        ]);
        Storage::disk('public')->assertDirectoryEmpty('paraf/logbook');
    }

    private function createUnitKerja(string $kodeUnit): UnitKerja
    {
        return UnitKerja::create([
            'kode_unit' => $kodeUnit,
            'nama_unit' => $kodeUnit,
            'tipe' => 'unit',
            'status' => 'aktif',
        ]);
    }

    private function createPembimbing(UnitKerja $unitKerja, string $username): Pembimbing
    {
        $pengguna = Pengguna::create([
            'username' => $username,
            'nama_lengkap' => 'Pembimbing '.$username,
            'password' => 'password',
            'role' => 'pembimbing',
            'status' => 'aktif',
        ]);

        return Pembimbing::create([
            'id_pengguna' => $pengguna->id,
            'id_unit_kerja' => $unitKerja->id,
            'nip' => $username,
            'jabatan' => 'Pembimbing Lapangan',
            'status_pembimbing' => 'aktif',
        ]);
    }

    private function createPeserta(UnitKerja $unitKerja, string $username): PesertaMagang
    {
        $pengguna = Pengguna::create([
            'username' => $username,
            'nama_lengkap' => $username,
            'password' => 'password',
            'role' => 'peserta_magang',
            'status' => 'aktif',
        ]);

        return PesertaMagang::create([
            'id_pengguna' => $pengguna->id,
            'id_unit_kerja' => $unitKerja->id,
            'jenis_peserta' => 'siswa',
            'prodi_jurusan' => 'Teknik Kendaraan',
            'asal_sekolah_kampus' => 'SMK '.$unitKerja->kode_unit,
            'kelas_semester' => 'XII',
            'status_peserta' => 'aktif',
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31',
            'status_dokumen' => 'pending',
        ]);
    }

    private function createLogbook(PesertaMagang $peserta): Logbook
    {
        return Logbook::create([
            'id_peserta_magang' => $peserta->id,
            'tanggal' => '2026-11-13',
            'jam_mulai' => '08:00:00',
            'jam_selesai' => '09:00:00',
            'kegiatan' => 'Mengerjakan laporan.',
        ]);
    }

    private function createPengajuanIzin(PesertaMagang $peserta): PengajuanIzin
    {
        return PengajuanIzin::create([
            'id_peserta_magang' => $peserta->id,
            'jenis' => 'izin',
            'tanggal_mulai' => '2026-11-13',
            'tanggal_selesai' => '2026-11-13',
            'keterangan' => 'Keperluan keluarga.',
        ]);
    }
}
