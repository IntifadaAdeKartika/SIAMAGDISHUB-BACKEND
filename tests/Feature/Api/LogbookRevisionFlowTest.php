<?php

namespace Tests\Feature\Api;

use App\Models\Logbook;
use App\Models\Pembimbing;
use App\Models\Pengguna;
use App\Models\PesertaMagang;
use App\Models\UnitKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LogbookRevisionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);
    }

    public function test_supervisor_revision_request_keeps_status_pending_and_saves_note(): void
    {
        [$unitKerja, $pembimbing, $penggunaPembimbing] = $this->createUnitWithPembimbing('revisi-unit');
        $peserta = $this->createPeserta($unitKerja, 'peserta-revisi');
        $logbook = $this->createLogbook($peserta);

        $this->actingAs($penggunaPembimbing, 'sanctum')
            ->putJson("/backend-api/pembimbing/logbook/{$logbook->id}/minta-revisi", [
                'catatan_pembimbing' => 'Lengkapi uraian kegiatan.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status_verifikasi', 'pending')
            ->assertJsonPath('data.revisi_diminta', true)
            ->assertJsonPath('data.catatan_pembimbing', 'Lengkapi uraian kegiatan.');

        $fresh = $logbook->fresh();
        $this->assertSame('pending', $fresh->status_verifikasi);
        $this->assertTrue($fresh->revisi_diminta);
        $this->assertNotNull($fresh->revisi_diminta_pada);
        $this->assertSame('Lengkapi uraian kegiatan.', $fresh->catatan_pembimbing);
        $this->assertSame($pembimbing->id, $fresh->diverifikasi_oleh);
        $this->assertSame('Lengkapi uraian kegiatan.', $fresh->riwayat_revisi[0]['catatan']);
    }

    public function test_legacy_rejected_payload_from_correction_endpoint_is_treated_as_revision(): void
    {
        [$unitKerja, , $penggunaPembimbing] = $this->createUnitWithPembimbing('legacy-unit');
        $peserta = $this->createPeserta($unitKerja, 'peserta-legacy');
        $logbook = $this->createLogbook($peserta);

        $this->actingAs($penggunaPembimbing, 'sanctum')
            ->putJson("/backend-api/koreksi-logbook/{$logbook->id}", [
                'status_verifikasi' => 'rejected',
                'catatan_pembimbing' => 'Perbaiki foto kegiatan.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status_verifikasi', 'pending')
            ->assertJsonPath('data.revisi_diminta', true);

        $this->assertDatabaseHas('logbook', [
            'id' => $logbook->id,
            'status_verifikasi' => 'pending',
            'revisi_diminta' => true,
            'catatan_pembimbing' => 'Perbaiki foto kegiatan.',
        ]);
        $this->assertDatabaseMissing('logbook', [
            'id' => $logbook->id,
            'status_verifikasi' => 'rejected',
        ]);
    }

    public function test_revision_notification_is_sent_to_the_logbook_owner(): void
    {
        [$unitKerja, , $penggunaPembimbing] = $this->createUnitWithPembimbing('notif-unit');
        $peserta = $this->createPeserta($unitKerja, 'peserta-pemilik');
        $pesertaLain = $this->createPeserta($unitKerja, 'peserta-lain');
        $logbook = $this->createLogbook($peserta, ['tanggal' => '2026-11-20']);

        $this->actingAs($penggunaPembimbing, 'sanctum')
            ->putJson("/backend-api/pembimbing/logbook/{$logbook->id}/minta-revisi", [
                'catatan_pembimbing' => 'Tuliskan rincian pekerjaan.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $peserta->id_pengguna,
            'judul' => 'Logbook Perlu Direvisi',
            'pesan' => "Logbook Anda untuk tanggal 2026-11-20 (ID #{$logbook->id}) perlu direvisi. Catatan Pembimbing: Tuliskan rincian pekerjaan.",
            'tipe' => 'warning',
            'link' => '/peserta/logbook.html',
        ]);
        $this->assertDatabaseMissing('notifikasi', [
            'id_pengguna' => $pesertaLain->id_pengguna,
            'judul' => 'Logbook Perlu Direvisi',
        ]);
    }

    public function test_participant_can_edit_revision_logbook_and_resubmit_it(): void
    {
        [$unitKerja, $pembimbing, $penggunaPembimbing] = $this->createUnitWithPembimbing('resubmit-unit');
        $peserta = $this->createPeserta($unitKerja, 'peserta-resubmit');
        $logbook = $this->createLogbook($peserta, ['tanggal' => '2026-11-20']);

        $this->actingAs($penggunaPembimbing, 'sanctum')
            ->putJson("/backend-api/pembimbing/logbook/{$logbook->id}/minta-revisi", [
                'catatan_pembimbing' => 'Perbaiki tanggal dan uraian kegiatan.',
            ])
            ->assertOk();

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->putJson("/backend-api/logbook/{$logbook->id}", [
                'tanggal' => '2026-11-22',
                'jam_mulai' => '10:00',
                'jam_selesai' => '12:00',
                'kegiatan' => 'Kegiatan yang telah diperbaiki.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status_verifikasi', 'pending')
            ->assertJsonPath('data.revisi_diminta', false)
            ->assertJsonPath('data.tanggal', '2026-11-22')
            ->assertJsonPath('data.kegiatan', 'Kegiatan yang telah diperbaiki.')
            ->assertJsonPath('data.catatan_pembimbing', 'Perbaiki tanggal dan uraian kegiatan.');

        $fresh = $logbook->fresh();
        $this->assertSame('pending', $fresh->status_verifikasi);
        $this->assertFalse($fresh->revisi_diminta);
        $this->assertNotNull($fresh->revisi_dikirim_pada);
        $this->assertSame('Perbaiki tanggal dan uraian kegiatan.', $fresh->catatan_pembimbing);
        $this->assertCount(1, $fresh->riwayat_revisi);
        $this->assertNotNull($fresh->riwayat_revisi[0]['dikirim_pada']);

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $penggunaPembimbing->id,
            'judul' => 'Revisi Logbook Dikirim',
            'pesan' => 'Peserta peserta-resubmit telah mengirimkan revisi logbook untuk tanggal 2026-11-22. Mohon diperiksa kembali.',
            'tipe' => 'info',
            'link' => '/pembimbing/logbook.html',
        ]);
        $this->assertSame('pending', $logbook->fresh()->status_verifikasi);
        $this->assertSame($pembimbing->id, $logbook->fresh()->diverifikasi_oleh);
    }

    public function test_participant_can_replace_photo_and_old_photo_is_kept_when_not_replaced(): void
    {
        Storage::disk('public')->put('logbook/foto/awal.jpg', 'foto-awal');

        [$unitKerja, , $penggunaPembimbing] = $this->createUnitWithPembimbing('foto-unit');
        $peserta = $this->createPeserta($unitKerja, 'peserta-foto-revisi');
        $logbook = $this->createLogbook($peserta, [
            'foto_kegiatan' => 'logbook/foto/awal.jpg',
        ]);

        $this->actingAs($penggunaPembimbing, 'sanctum')
            ->putJson("/backend-api/pembimbing/logbook/{$logbook->id}/minta-revisi", [
                'catatan_pembimbing' => 'Catatan pertama.',
            ])
            ->assertOk();

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->putJson("/backend-api/logbook/{$logbook->id}", [
                'kegiatan' => 'Revisi tanpa mengganti foto.',
            ])
            ->assertOk();

        $logbook->refresh();
        $this->assertSame('logbook/foto/awal.jpg', $logbook->foto_kegiatan);
        Storage::disk('public')->assertExists('logbook/foto/awal.jpg');

        $this->actingAs($penggunaPembimbing, 'sanctum')
            ->putJson("/backend-api/pembimbing/logbook/{$logbook->id}/minta-revisi", [
                'catatan_pembimbing' => 'Catatan kedua.',
            ])
            ->assertOk();

        $fotoBaru = UploadedFile::fake()->createWithContent(
            'foto-baru.jpg',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l2cAAAAASUVORK5CYII='),
        );

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->put("/backend-api/logbook/{$logbook->id}", [
                'kegiatan' => 'Revisi dengan foto baru.',
                'foto_kegiatan' => $fotoBaru,
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $logbook->refresh();
        $this->assertNotSame('logbook/foto/awal.jpg', $logbook->foto_kegiatan);
        Storage::disk('public')->assertMissing('logbook/foto/awal.jpg');
        Storage::disk('public')->assertExists($logbook->foto_kegiatan);

        $this->assertCount(2, $logbook->riwayat_revisi);
        $this->assertSame('Catatan pertama.', $logbook->riwayat_revisi[0]['catatan']);
        $this->assertSame('Catatan kedua.', $logbook->riwayat_revisi[1]['catatan']);
        $this->assertNotNull($logbook->riwayat_revisi[0]['dikirim_pada']);
        $this->assertNotNull($logbook->riwayat_revisi[1]['dikirim_pada']);
    }

    public function test_supervisor_can_view_the_resubmitted_logbook_and_approve_it(): void
    {
        [$unitKerja, $pembimbing, $penggunaPembimbing] = $this->createUnitWithPembimbing('approve-unit');
        $peserta = $this->createPeserta($unitKerja, 'peserta-approve');
        $logbook = $this->createLogbook($peserta, ['tanggal' => '2026-11-20']);

        $this->actingAs($penggunaPembimbing, 'sanctum')
            ->putJson("/backend-api/pembimbing/logbook/{$logbook->id}/minta-revisi", [
                'catatan_pembimbing' => 'Mohon dilengkapi.',
            ])
            ->assertOk();

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->putJson("/backend-api/logbook/{$logbook->id}", [
                'kegiatan' => 'Kegiatan hasil revisi peserta.',
            ])
            ->assertOk();

        $this->actingAs($penggunaPembimbing, 'sanctum')
            ->getJson('/backend-api/pembimbing/logbook')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $logbook->id)
            ->assertJsonPath('data.data.0.kegiatan', 'Kegiatan hasil revisi peserta.')
            ->assertJsonPath('data.data.0.catatan_pembimbing', 'Mohon dilengkapi.')
            ->assertJsonPath('data.data.0.revisi_diminta', false);

        $this->actingAs($penggunaPembimbing, 'sanctum')
            ->putJson("/backend-api/koreksi-logbook/{$logbook->id}", [
                'status_verifikasi' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('data.status_verifikasi', 'approved');

        $fresh = $logbook->fresh();
        $this->assertSame('approved', $fresh->status_verifikasi);
        $this->assertFalse($fresh->revisi_diminta);
        $this->assertSame($pembimbing->id, $fresh->diverifikasi_oleh);
        $this->assertNotNull($fresh->diverifikasi_pada);
        $this->assertSame('Mohon dilengkapi.', $fresh->catatan_pembimbing);

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $peserta->id_pengguna,
            'judul' => 'Logbook Diverifikasi',
            'pesan' => 'Logbook Anda untuk tanggal 2026-11-20 telah disetujui oleh Pembimbing.',
            'tipe' => 'success',
        ]);
    }

    public function test_participant_cannot_edit_an_approved_logbook(): void
    {
        [$unitKerja, , $penggunaPembimbing] = $this->createUnitWithPembimbing('locked-unit');
        $peserta = $this->createPeserta($unitKerja, 'peserta-locked');
        $logbook = $this->createLogbook($peserta);

        $this->actingAs($penggunaPembimbing, 'sanctum')
            ->putJson("/backend-api/koreksi-logbook/{$logbook->id}", [
                'status_verifikasi' => 'approved',
            ])
            ->assertOk();

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->putJson("/backend-api/logbook/{$logbook->id}", [
                'kegiatan' => 'Percobaan mengubah logbook approved.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Logbook tidak dapat diubah karena telah diverifikasi (approved).');

        $this->assertSame('Mengerjakan laporan.', $logbook->fresh()->kegiatan);
    }

    public function test_supervisor_cannot_request_revision_for_an_approved_logbook(): void
    {
        [$unitKerja, , $penggunaPembimbing] = $this->createUnitWithPembimbing('approved-lock-unit');
        $peserta = $this->createPeserta($unitKerja, 'peserta-approved-lock');
        $logbook = $this->createLogbook($peserta);

        $this->actingAs($penggunaPembimbing, 'sanctum')
            ->putJson("/backend-api/koreksi-logbook/{$logbook->id}", [
                'status_verifikasi' => 'approved',
            ])
            ->assertOk();

        $this->actingAs($penggunaPembimbing, 'sanctum')
            ->putJson("/backend-api/pembimbing/logbook/{$logbook->id}/minta-revisi", [
                'catatan_pembimbing' => 'Minta revisi setelah approve.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertSame('approved', $logbook->fresh()->status_verifikasi);
        $this->assertFalse($logbook->fresh()->revisi_diminta);
    }

    public function test_participant_cannot_edit_another_participants_logbook(): void
    {
        [$unitKerja, , $penggunaPembimbing] = $this->createUnitWithPembimbing('owner-unit');
        $pemilik = $this->createPeserta($unitKerja, 'peserta-pemilik-logbook');
        $pesertaLain = $this->createPeserta($unitKerja, 'peserta-bukan-pemilik');
        $logbook = $this->createLogbook($pemilik);

        $this->actingAs($penggunaPembimbing, 'sanctum')
            ->putJson("/backend-api/pembimbing/logbook/{$logbook->id}/minta-revisi", [
                'catatan_pembimbing' => 'Mohon diperbaiki pemilik.',
            ])
            ->assertOk();

        $this->actingAs($pesertaLain->pengguna, 'sanctum')
            ->putJson("/backend-api/logbook/{$logbook->id}", [
                'kegiatan' => 'Percobaan mengubah logbook peserta lain.',
            ])
            ->assertForbidden();

        $this->assertSame('Mengerjakan laporan.', $logbook->fresh()->kegiatan);
    }

    public function test_supervisor_outside_the_unit_cannot_manage_logbook(): void
    {
        [$unitKerja] = $this->createUnitWithPembimbing('unit-asal');
        [, , $penggunaPembimbingLain] = $this->createUnitWithPembimbing('unit-lain');
        $peserta = $this->createPeserta($unitKerja, 'peserta-scope');
        $logbook = $this->createLogbook($peserta);

        $this->actingAs($penggunaPembimbingLain, 'sanctum')
            ->putJson("/backend-api/pembimbing/logbook/{$logbook->id}/minta-revisi", [
                'catatan_pembimbing' => 'Bukan kewenangan saya.',
            ])
            ->assertNotFound();

        $this->actingAs($penggunaPembimbingLain, 'sanctum')
            ->putJson("/backend-api/pembimbing/logbook/{$logbook->id}/tolak", [
                'catatan_pembimbing' => 'Bukan kewenangan saya.',
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('logbook', [
            'id' => $logbook->id,
            'status_verifikasi' => 'pending',
            'revisi_diminta' => false,
            'catatan_pembimbing' => null,
        ]);
    }

    public function test_final_rejection_sets_rejected_status_and_notifies_participant(): void
    {
        [$unitKerja, $pembimbing, $penggunaPembimbing] = $this->createUnitWithPembimbing('final-reject-unit');
        $peserta = $this->createPeserta($unitKerja, 'peserta-final-reject');
        $logbook = $this->createLogbook($peserta, ['tanggal' => '2026-11-20']);

        $this->actingAs($penggunaPembimbing, 'sanctum')
            ->putJson("/backend-api/pembimbing/logbook/{$logbook->id}/tolak", [
                'catatan_pembimbing' => 'Logbook tidak dapat diterima.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status_verifikasi', 'rejected');

        $fresh = $logbook->fresh();
        $this->assertSame('rejected', $fresh->status_verifikasi);
        $this->assertFalse($fresh->revisi_diminta);
        $this->assertSame($pembimbing->id, $fresh->diverifikasi_oleh);

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $peserta->id_pengguna,
            'judul' => 'Logbook Ditolak',
            'pesan' => 'Logbook Anda untuk tanggal 2026-11-20 telah ditolak oleh Pembimbing.',
            'tipe' => 'danger',
        ]);

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->putJson("/backend-api/logbook/{$logbook->id}", [
                'kegiatan' => 'Percobaan mengubah logbook ditolak.',
            ])
            ->assertUnprocessable();
    }

    /**
     * @return array{0: UnitKerja, 1: Pembimbing, 2: Pengguna}
     */
    private function createUnitWithPembimbing(string $username): array
    {
        $unitKerja = UnitKerja::create([
            'kode_unit' => strtoupper($username),
            'nama_unit' => strtoupper($username),
            'tipe' => 'unit',
            'status' => 'aktif',
        ]);

        $penggunaPembimbing = Pengguna::create([
            'username' => $username,
            'nama_lengkap' => 'Pembimbing '.$username,
            'password' => 'password',
            'role' => 'pembimbing',
            'status' => 'aktif',
        ]);

        $pembimbing = Pembimbing::create([
            'id_pengguna' => $penggunaPembimbing->id,
            'id_unit_kerja' => $unitKerja->id,
            'nip' => $username,
            'jabatan' => 'Pembimbing Lapangan',
            'status_pembimbing' => 'aktif',
        ]);

        $unitKerja->update(['id_pembimbing' => $pembimbing->id]);

        return [$unitKerja, $pembimbing, $penggunaPembimbing];
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
            'asal_sekolah_kampus' => 'SMK Contoh',
            'kelas_semester' => 'XII',
            'status_peserta' => 'aktif',
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31',
            'status_dokumen' => 'pending',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createLogbook(PesertaMagang $peserta, array $overrides = []): Logbook
    {
        return Logbook::create(array_merge([
            'id_peserta_magang' => $peserta->id,
            'tanggal' => '2026-11-19',
            'jam_mulai' => '08:00:00',
            'jam_selesai' => '09:00:00',
            'kegiatan' => 'Mengerjakan laporan.',
        ], $overrides));
    }
}
