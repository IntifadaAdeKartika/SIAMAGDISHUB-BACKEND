<?php

namespace Tests\Feature\Api\Peserta;

use App\Models\PengajuanIzin;
use App\Models\Pengguna;
use App\Models\PesertaMagang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PengajuanIzinActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_peserta_can_cancel_their_own_pending_izin(): void
    {
        $peserta = $this->createPeserta('peserta-cancel');
        $izin = $this->createPengajuanIzin($peserta);

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->putJson("/backend-api/pengajuan-izin/{$izin->id}/cancel")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('pengajuan_izin', [
            'id' => $izin->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_peserta_can_create_izin_and_read_it_from_their_list(): void
    {
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);

        $peserta = $this->createPeserta('peserta-create');

        $response = $this->actingAs($peserta->pengguna, 'sanctum')
            ->post('/backend-api/pengajuan-izin', [
                'jenis' => 'izin',
                'tanggal_mulai' => '2026-11-18',
                'tanggal_selesai' => '2026-11-18',
                'keterangan' => 'Keperluan keluarga',
                'file_surat' => UploadedFile::fake()->createWithContent(
                    'surat.pdf',
                    "%PDF-1.4\nSurat permohonan izin\n",
                ),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.keterangan', 'Keperluan keluarga');

        $izinId = $response->json('data.id');

        $this->assertDatabaseHas('pengajuan_izin', [
            'id' => $izinId,
            'id_peserta_magang' => $peserta->id,
            'status' => 'pending',
        ]);

        $this->getJson('/backend-api/pengajuan-izin')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $izinId,
                'keterangan' => 'Keperluan keluarga',
            ]);
    }

    public function test_peserta_can_update_their_own_pending_izin(): void
    {
        $peserta = $this->createPeserta('peserta-update');
        $izin = $this->createPengajuanIzin($peserta);

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->putJson("/backend-api/pengajuan-izin/{$izin->id}", [
                'jenis' => 'sakit',
                'tanggal_mulai' => '2026-11-15',
                'tanggal_selesai' => '2026-11-16',
                'keterangan' => 'Perlu diperiksa dokter',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.keterangan', 'Perlu diperiksa dokter');

        $this->assertDatabaseHas('pengajuan_izin', [
            'id' => $izin->id,
            'jenis' => 'sakit',
            'keterangan' => 'Perlu diperiksa dokter',
            'status' => 'pending',
        ]);

        $updatedIzin = $izin->fresh();
        $this->assertSame('2026-11-15', $updatedIzin->tanggal_mulai->toDateString());
        $this->assertSame('2026-11-16', $updatedIzin->tanggal_selesai->toDateString());

        $this->getJson('/backend-api/pengajuan-izin')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $izin->id,
                'keterangan' => 'Perlu diperiksa dokter',
            ]);
    }

    public function test_peserta_cannot_cancel_or_update_a_processed_izin(): void
    {
        $peserta = $this->createPeserta('peserta-processed');
        $izin = $this->createPengajuanIzin($peserta, 'approved');
        $payload = [
            'jenis' => 'sakit',
            'tanggal_mulai' => '2026-11-15',
            'tanggal_selesai' => '2026-11-16',
            'keterangan' => 'Keterangan baru',
        ];

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->putJson("/backend-api/pengajuan-izin/{$izin->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Tidak dapat dibatalkan karena status sudah diproses.');

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->putJson("/backend-api/pengajuan-izin/{$izin->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Tidak dapat mengubah izin karena sudah diproses.');

        $this->assertDatabaseHas('pengajuan_izin', [
            'id' => $izin->id,
            'status' => 'approved',
            'keterangan' => 'Keterangan awal',
        ]);
    }

    public function test_peserta_cannot_update_or_cancel_another_participants_izin(): void
    {
        $owner = $this->createPeserta('peserta-owner');
        $otherPeserta = $this->createPeserta('peserta-other');
        $izin = $this->createPengajuanIzin($owner);

        $this->actingAs($otherPeserta->pengguna, 'sanctum')
            ->putJson("/backend-api/pengajuan-izin/{$izin->id}/cancel")
            ->assertNotFound();

        $this->actingAs($otherPeserta->pengguna, 'sanctum')
            ->putJson("/backend-api/pengajuan-izin/{$izin->id}", [
                'jenis' => 'sakit',
                'tanggal_mulai' => '2026-11-15',
                'tanggal_selesai' => '2026-11-16',
                'keterangan' => 'Perubahan tidak sah',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('pengajuan_izin', [
            'id' => $izin->id,
            'id_peserta_magang' => $owner->id,
            'status' => 'pending',
            'keterangan' => 'Keterangan awal',
        ]);
    }

    public function test_izin_actions_require_authenticated_participant_role(): void
    {
        $peserta = $this->createPeserta('peserta-auth');
        $izin = $this->createPengajuanIzin($peserta);

        $this->putJson("/backend-api/pengajuan-izin/{$izin->id}/cancel")
            ->assertUnauthorized();

        $admin = Pengguna::create([
            'username' => 'admin-izin-test',
            'nama_lengkap' => 'Admin',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'aktif',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/backend-api/pengajuan-izin/{$izin->id}")
            ->assertForbidden();
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

    private function createPengajuanIzin(PesertaMagang $peserta, string $status = 'pending'): PengajuanIzin
    {
        return PengajuanIzin::create([
            'id_peserta_magang' => $peserta->id,
            'jenis' => 'izin',
            'tanggal_mulai' => '2026-11-12',
            'tanggal_selesai' => '2026-11-12',
            'keterangan' => 'Keterangan awal',
            'status' => $status,
        ]);
    }
}
