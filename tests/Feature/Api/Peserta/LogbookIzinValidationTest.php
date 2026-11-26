<?php

namespace Tests\Feature\Api\Peserta;

use App\Models\Absensi;
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

class LogbookIzinValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);
    }

    public function test_participant_with_approved_check_in_can_fill_logbook(): void
    {
        $this->travelTo('2026-11-20 09:30:00');
        $unitKerja = $this->createUnitKerja('unit-checkin');
        $peserta = $this->createPeserta($unitKerja, 'peserta-checkin-logbook');
        $absensi = $this->createAbsensi($peserta, '2026-11-20 08:00:00');

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/logbook', $this->payload('2026-11-20', [
                'id_absensi' => $absensi->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.is_draft', false);

        $logbook = Logbook::firstOrFail();
        $this->assertSame('2026-11-20', $logbook->tanggal->toDateString());
        $this->assertSame($absensi->id, $logbook->id_absensi);
    }

    public function test_participant_with_approved_leave_can_fill_logbook_without_check_in(): void
    {
        $unitKerja = $this->createUnitKerja('unit-izin-approved');
        $peserta = $this->createPeserta($unitKerja, 'peserta-izin-approved');
        $this->createIzin($peserta, '2026-11-18', '2026-11-22', 'approved');

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/logbook', $this->payload('2026-11-20'))
            ->assertCreated()
            ->assertJsonPath('data.is_draft', false);

        $logbook = Logbook::firstOrFail();
        $this->assertSame('2026-11-20', $logbook->tanggal->toDateString());
        $this->assertNull($logbook->id_absensi);
        $this->assertDatabaseCount('absensi', 0);
    }

    public function test_pending_leave_is_not_automatically_accepted(): void
    {
        $unitKerja = $this->createUnitKerja('unit-izin-pending');
        $peserta = $this->createPeserta($unitKerja, 'peserta-izin-pending');
        $this->createIzin($peserta, '2026-11-18', '2026-11-22', 'pending');

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/logbook', $this->payload('2026-11-20'))
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'Tidak ada absensi masuk yang disetujui atau izin yang disetujui untuk tanggal 2026-11-20.',
            );

        $this->assertDatabaseCount('logbook', 0);
    }

    public function test_rejected_leave_cannot_be_used_as_logbook_basis(): void
    {
        $unitKerja = $this->createUnitKerja('unit-izin-rejected');
        $peserta = $this->createPeserta($unitKerja, 'peserta-izin-rejected');
        $this->createIzin($peserta, '2026-11-18', '2026-11-22', 'rejected');

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/logbook', $this->payload('2026-11-20'))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('logbook', 0);
    }

    public function test_participant_without_check_in_and_without_leave_cannot_fill_logbook(): void
    {
        $unitKerja = $this->createUnitKerja('unit-tanpa-akses');
        $peserta = $this->createPeserta($unitKerja, 'peserta-tanpa-akses');

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/logbook', $this->payload('2026-11-20'))
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Tidak ada absensi masuk yang disetujui atau izin yang disetujui untuk tanggal 2026-11-20.',
            );

        $this->assertDatabaseCount('logbook', 0);
    }

    public function test_participant_cannot_use_another_participants_leave(): void
    {
        $unitKerja = $this->createUnitKerja('unit-izin-milik-lain');
        $peserta = $this->createPeserta($unitKerja, 'peserta-pemakai-izin');
        $pesertaLain = $this->createPeserta($unitKerja, 'peserta-pemilik-izin');
        $this->createIzin($pesertaLain, '2026-11-18', '2026-11-22', 'approved');

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/logbook', $this->payload('2026-11-20'))
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertDatabaseCount('logbook', 0);
    }

    public function test_date_outside_approved_leave_range_cannot_use_leave_as_basis(): void
    {
        $unitKerja = $this->createUnitKerja('unit-izin-di-luar-rentang');
        $peserta = $this->createPeserta($unitKerja, 'peserta-izin-di-luar-rentang');
        $this->createIzin($peserta, '2026-11-18', '2026-11-19', 'approved');

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/logbook', $this->payload('2026-11-20'))
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Tidak ada absensi masuk yang disetujui atau izin yang disetujui untuk tanggal 2026-11-20.',
            );

        $this->assertDatabaseCount('logbook', 0);
    }

    public function test_non_draft_update_still_requires_check_in_or_approved_leave(): void
    {
        $unitKerja = $this->createUnitKerja('unit-update-tanpa-akses');
        $peserta = $this->createPeserta($unitKerja, 'peserta-update-tanpa-akses');
        $logbook = Logbook::create([
            'id_peserta_magang' => $peserta->id,
            'tanggal' => '2026-11-20',
            'jam_mulai' => '08:00',
            'jam_selesai' => '09:00',
            'kegiatan' => 'Kegiatan awal.',
            'is_draft' => false,
        ]);

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->putJson("/backend-api/logbook/{$logbook->id}", [
                'kegiatan' => 'Kegiatan tanpa dasar absensi atau izin.',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertSame('Kegiatan awal.', $logbook->fresh()->kegiatan);
    }

    public function test_participant_cannot_attach_another_participants_attendance(): void
    {
        $unitKerja = $this->createUnitKerja('unit-absensi-milik-lain');
        $peserta = $this->createPeserta($unitKerja, 'peserta-pemakai-absensi');
        $pesertaLain = $this->createPeserta($unitKerja, 'peserta-pemilik-absensi');
        $absensiLain = $this->createAbsensi($pesertaLain, '2026-11-20 08:00:00');
        $this->createIzin($peserta, '2026-11-18', '2026-11-22', 'approved');

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/logbook', $this->payload('2026-11-20', [
                'id_absensi' => $absensiLain->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Absensi tidak ditemukan untuk peserta ini.');

        $this->assertDatabaseCount('logbook', 0);
    }

    public function test_draft_logbook_can_be_saved_without_check_in_or_leave(): void
    {
        $unitKerja = $this->createUnitKerja('unit-draft');
        $peserta = $this->createPeserta($unitKerja, 'peserta-draft');

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/logbook', $this->payload('2026-11-20', ['is_draft' => true]))
            ->assertCreated()
            ->assertJsonPath('data.is_draft', true);

        $this->assertSame(true, Logbook::firstOrFail()->is_draft);
    }

    public function test_revision_and_photo_replacement_still_work_for_leave_based_logbook(): void
    {
        Storage::disk('public')->put('logbook/foto/awal.jpg', 'foto-awal');

        [$unitKerja, , $penggunaPembimbing] = $this->createUnitWithPembimbing('unit-revisi-izin');
        $peserta = $this->createPeserta($unitKerja, 'peserta-revisi-izin');
        $this->createIzin($peserta, '2026-11-18', '2026-11-22', 'approved');

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/logbook', $this->payload('2026-11-20'))
            ->assertCreated();

        $logbook = Logbook::firstOrFail();
        $logbook->update(['foto_kegiatan' => 'logbook/foto/awal.jpg']);

        $this->actingAs($penggunaPembimbing, 'sanctum')
            ->putJson("/backend-api/pembimbing/logbook/{$logbook->id}/minta-revisi", [
                'catatan_pembimbing' => 'Ganti foto kegiatan.',
            ])
            ->assertOk();

        $fotoBaru = UploadedFile::fake()->createWithContent(
            'foto-baru.jpg',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l2cAAAAASUVORK5CYII='),
        );

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->put("/backend-api/logbook/{$logbook->id}", [
                'kegiatan' => 'Kegiatan dengan foto baru.',
                'foto_kegiatan' => $fotoBaru,
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status_verifikasi', 'pending')
            ->assertJsonPath('data.revisi_diminta', false);

        $logbook->refresh();
        $this->assertSame('Kegiatan dengan foto baru.', $logbook->kegiatan);
        $this->assertNotSame('logbook/foto/awal.jpg', $logbook->foto_kegiatan);
        Storage::disk('public')->assertMissing('logbook/foto/awal.jpg');
        Storage::disk('public')->assertExists($logbook->foto_kegiatan);
        $this->assertCount(1, $logbook->riwayat_revisi);

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $penggunaPembimbing->id,
            'judul' => 'Revisi Logbook Dikirim',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(string $tanggal, array $overrides = []): array
    {
        return array_merge([
            'tanggal' => $tanggal,
            'jam_mulai' => '08:00',
            'jam_selesai' => '09:00',
            'kegiatan' => 'Kegiatan bimbingan proyek.',
        ], $overrides);
    }

    private function createAbsensi(PesertaMagang $peserta, string $tanggalJam): Absensi
    {
        return Absensi::create([
            'id_peserta_magang' => $peserta->id,
            'tanggal_jam' => $tanggalJam,
            'foto_selfie' => 'absensi/selfie.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'masuk',
            'status_verifikasi' => 'approved',
            'diverifikasi_pada' => now(),
        ]);
    }

    private function createIzin(PesertaMagang $peserta, string $mulai, string $selesai, string $status): PengajuanIzin
    {
        return PengajuanIzin::create([
            'id_peserta_magang' => $peserta->id,
            'jenis' => 'izin',
            'tanggal_mulai' => $mulai,
            'tanggal_selesai' => $selesai,
            'keterangan' => 'Kegiatan tetap berjalan selama izin.',
            'status' => $status,
        ]);
    }

    private function createUnitKerja(string $username): UnitKerja
    {
        return UnitKerja::create([
            'kode_unit' => strtoupper($username),
            'nama_unit' => strtoupper($username),
            'tipe' => 'unit',
            'status' => 'aktif',
        ]);
    }

    /**
     * @return array{0: UnitKerja, 1: Pembimbing, 2: Pengguna}
     */
    private function createUnitWithPembimbing(string $username): array
    {
        $unitKerja = $this->createUnitKerja($username);

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
}
