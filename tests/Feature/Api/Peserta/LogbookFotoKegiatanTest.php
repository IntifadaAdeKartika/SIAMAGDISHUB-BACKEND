<?php

namespace Tests\Feature\Api\Peserta;

use App\Models\Absensi;
use App\Models\Logbook;
use App\Models\Pengguna;
use App\Models\PesertaMagang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LogbookFotoKegiatanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);
    }

    public function test_logbook_list_and_detail_provide_foto_kegiatan_and_foto_kegiatan_url(): void
    {
        $this->travelTo('2026-11-24 08:00:00');
        $peserta = $this->createPeserta('peserta-foto-test');
        $absensi = $this->createApprovedAbsensi($peserta);

        $uploadedPhoto = $this->fakeImage('kegiatan.jpg');

        // 1. Store logbook with uploaded file
        $response = $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/logbook', [
                'id_absensi' => $absensi->id,
                'tanggal' => '2026-11-24',
                'jam_mulai' => '08:00',
                'jam_selesai' => '09:00',
                'kegiatan' => 'Mengecek kelistrikan kendaraan dinas',
                'foto_kegiatan' => $uploadedPhoto,
            ])
            ->assertCreated();

        $logbook = Logbook::firstOrFail();
        $this->assertNotNull($logbook->foto_kegiatan);
        Storage::disk('public')->assertExists($logbook->foto_kegiatan);

        $expectedUrl = Storage::disk('public')->url($logbook->foto_kegiatan);
        $response->assertJsonPath('data.foto_kegiatan', $expectedUrl)
            ->assertJsonPath('data.foto_kegiatan_url', $expectedUrl);

        // 2. Index endpoint returns both foto_kegiatan and foto_kegiatan_url
        $this->getJson('/backend-api/logbook')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $logbook->id)
            ->assertJsonPath('data.data.0.foto_kegiatan', $expectedUrl)
            ->assertJsonPath('data.data.0.foto_kegiatan_url', $expectedUrl);

        // 3. Show endpoint returns both foto_kegiatan and foto_kegiatan_url
        $this->getJson("/backend-api/logbook/{$logbook->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $logbook->id)
            ->assertJsonPath('data.foto_kegiatan', $expectedUrl)
            ->assertJsonPath('data.foto_kegiatan_url', $expectedUrl);
    }

    public function test_updating_logbook_without_photo_retains_existing_photo(): void
    {
        $this->travelTo('2026-11-24 08:00:00');
        $peserta = $this->createPeserta('peserta-retain-photo');
        $absensi = $this->createApprovedAbsensi($peserta);

        $photo = $this->fakeImage('kegiatan1.jpg');

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/logbook', [
                'id_absensi' => $absensi->id,
                'tanggal' => '2026-11-24',
                'jam_mulai' => '08:00',
                'jam_selesai' => '09:00',
                'kegiatan' => 'Kegiatan awal',
                'foto_kegiatan' => $photo,
            ])
            ->assertCreated();

        $logbook = Logbook::firstOrFail();
        $originalPhotoPath = $logbook->foto_kegiatan;
        Storage::disk('public')->assertExists($originalPhotoPath);

        // Update only kegiatan without sending foto_kegiatan
        $this->putJson("/backend-api/logbook/{$logbook->id}", [
            'kegiatan' => 'Kegiatan yang sudah direvisi',
        ])
            ->assertOk()
            ->assertJsonPath('data.kegiatan', 'Kegiatan yang sudah direvisi')
            ->assertJsonPath('data.foto_kegiatan_url', Storage::disk('public')->url($originalPhotoPath));

        $logbook->refresh();
        $this->assertSame($originalPhotoPath, $logbook->foto_kegiatan);
        Storage::disk('public')->assertExists($originalPhotoPath);
    }

    public function test_updating_logbook_sending_existing_photo_url_does_not_delete_or_corrupt_photo(): void
    {
        $this->travelTo('2026-11-24 08:00:00');
        $peserta = $this->createPeserta('peserta-resend-url');
        $absensi = $this->createApprovedAbsensi($peserta);

        $photo = $this->fakeImage('kegiatan_asli.jpg');

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/logbook', [
                'id_absensi' => $absensi->id,
                'tanggal' => '2026-11-24',
                'jam_mulai' => '08:00',
                'jam_selesai' => '09:00',
                'kegiatan' => 'Kegiatan awal',
                'foto_kegiatan' => $photo,
            ])
            ->assertCreated();

        $logbook = Logbook::firstOrFail();
        $originalPhotoPath = $logbook->foto_kegiatan;
        $existingPhotoUrl = Storage::disk('public')->url($originalPhotoPath);

        // Frontend re-sends the existing photo URL when saving edit form
        $this->putJson("/backend-api/logbook/{$logbook->id}", [
            'kegiatan' => 'Kegiatan diedit peserta',
            'foto_kegiatan' => $existingPhotoUrl,
        ])
            ->assertOk()
            ->assertJsonPath('data.kegiatan', 'Kegiatan diedit peserta')
            ->assertJsonPath('data.foto_kegiatan_url', $existingPhotoUrl);

        $logbook->refresh();
        $this->assertSame($originalPhotoPath, $logbook->foto_kegiatan);
        Storage::disk('public')->assertExists($originalPhotoPath);
    }

    public function test_updating_logbook_with_new_uploaded_file_replaces_old_photo(): void
    {
        $this->travelTo('2026-11-24 08:00:00');
        $peserta = $this->createPeserta('peserta-replace-photo');
        $absensi = $this->createApprovedAbsensi($peserta);

        $photo1 = $this->fakeImage('old_photo.jpg');

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/logbook', [
                'id_absensi' => $absensi->id,
                'tanggal' => '2026-11-24',
                'jam_mulai' => '08:00',
                'jam_selesai' => '09:00',
                'kegiatan' => 'Kegiatan awal',
                'foto_kegiatan' => $photo1,
            ])
            ->assertCreated();

        $logbook = Logbook::firstOrFail();
        $oldPhotoPath = $logbook->foto_kegiatan;
        Storage::disk('public')->assertExists($oldPhotoPath);

        $photo2 = $this->fakeImage('new_photo.jpg');

        $this->putJson("/backend-api/logbook/{$logbook->id}", [
            'kegiatan' => 'Kegiatan dengan foto baru',
            'foto_kegiatan' => $photo2,
        ])
            ->assertOk();

        $logbook->refresh();
        $this->assertNotSame($oldPhotoPath, $logbook->foto_kegiatan);
        Storage::disk('public')->assertMissing($oldPhotoPath);
        Storage::disk('public')->assertExists($logbook->foto_kegiatan);
    }

    public function test_base64_photo_is_provided_in_both_foto_kegiatan_and_foto_kegiatan_url(): void
    {
        $this->travelTo('2026-11-24 08:00:00');
        $peserta = $this->createPeserta('peserta-base64');
        $absensi = $this->createApprovedAbsensi($peserta);

        $base64Data = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l2cAAAAASUVORK5CYII=';

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/logbook', [
                'id_absensi' => $absensi->id,
                'tanggal' => '2026-11-24',
                'jam_mulai' => '08:00',
                'jam_selesai' => '09:00',
                'kegiatan' => 'Kegiatan dengan base64',
                'foto_kegiatan' => $base64Data,
            ])
            ->assertCreated()
            ->assertJsonPath('data.foto_kegiatan', $base64Data)
            ->assertJsonPath('data.foto_kegiatan_url', $base64Data);

        $logbook = Logbook::firstOrFail();

        // Re-saving with the same base64 does not alter or corrupt it
        $this->putJson("/backend-api/logbook/{$logbook->id}", [
            'kegiatan' => 'Kegiatan diedit',
            'foto_kegiatan' => $base64Data,
        ])
            ->assertOk()
            ->assertJsonPath('data.foto_kegiatan', $base64Data)
            ->assertJsonPath('data.foto_kegiatan_url', $base64Data);
    }

    private function fakeImage(string $filename = 'kegiatan.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $filename,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l2cAAAAASUVORK5CYII='),
            'image/jpeg'
        );
    }

    private function createApprovedAbsensi(PesertaMagang $peserta): Absensi
    {
        return Absensi::create([
            'id_peserta_magang' => $peserta->id,
            'tanggal_jam' => now(),
            'foto_selfie' => 'absensi/selfie.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'masuk',
            'status_verifikasi' => 'approved',
            'diverifikasi_pada' => now(),
        ]);
    }

    private function createPeserta(string $username): PesertaMagang
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
            'jenis_peserta' => 'siswa',
            'prodi_jurusan' => 'Teknik Kendaraan',
            'asal_sekolah_kampus' => 'SMK Contoh',
            'kelas_semester' => 'XII',
            'status_peserta' => 'aktif',
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31',
            'status_dokumen' => 'pending',
        ]);
    }
}
