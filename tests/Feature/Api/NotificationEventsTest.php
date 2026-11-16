<?php

namespace Tests\Feature\Api;

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

class NotificationEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_check_in_notifies_the_assigned_supervisor(): void
    {
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);
        $this->travelTo('2026-11-16 08:00:00');

        [$unitKerja, , $penggunaPembimbing] = $this->createUnitWithPembimbing('check-in-supervisor');
        $peserta = $this->createPeserta($unitKerja, 'check-in-participant', 'Nama Peserta');
        $payload = [
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'foto_selfie' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l2cAAAAASUVORK5CYII=',
        ];

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/check-in', $payload)
            ->assertCreated();

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $penggunaPembimbing->id,
            'judul' => 'Presensi Baru',
            'pesan' => 'Peserta Nama Peserta baru saja melakukan check-in. Mohon segera diverifikasi.',
            'tipe' => 'info',
            'link' => '/pembimbing/absensi.html',
            'sudah_dibaca' => false,
        ]);

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/check-in', $payload)
            ->assertUnprocessable();

        $this->assertDatabaseCount('notifikasi', 1);
    }

    public function test_non_draft_logbook_notifies_the_assigned_supervisor_but_draft_does_not(): void
    {
        [$unitKerja, , $penggunaPembimbing] = $this->createUnitWithPembimbing('logbook-supervisor');
        $peserta = $this->createPeserta($unitKerja, 'logbook-participant', 'Nama Logbook');
        $tanggal = now()->toDateString();
        $absensi = Absensi::create([
            'id_peserta_magang' => $peserta->id,
            'id_unit_kerja' => $unitKerja->id,
            'tanggal_jam' => now(),
            'foto_selfie' => 'absensi/selfie.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'masuk',
            'status_verifikasi' => 'approved',
            'diverifikasi_pada' => now(),
        ]);
        $payload = [
            'id_absensi' => $absensi->id,
            'tanggal' => $tanggal,
            'jam_mulai' => '08:00',
            'jam_selesai' => '09:00',
            'kegiatan' => 'Mengerjakan laporan.',
        ];

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/logbook', $payload)
            ->assertCreated();

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $penggunaPembimbing->id,
            'judul' => 'Logbook Baru',
            'pesan' => "Peserta Nama Logbook telah mengisi logbook untuk tanggal {$tanggal}. Mohon diverifikasi.",
            'tipe' => 'info',
            'sudah_dibaca' => false,
        ]);

        $this->postJson('/backend-api/logbook', array_merge($payload, [
            'is_draft' => true,
        ]))->assertCreated();

        $this->assertDatabaseCount('notifikasi', 1);
    }

    public function test_logbook_approval_and_rejection_notify_the_participant(): void
    {
        [$unitKerja, $pembimbing] = $this->createUnitWithPembimbing('review-supervisor');
        $peserta = $this->createPeserta($unitKerja, 'review-participant', 'Nama Logbook');
        $tanggal = now()->toDateString();
        $approvedLogbook = $this->createLogbook($peserta, $tanggal);
        $rejectedLogbook = $this->createLogbook($peserta, $tanggal);

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->putJson("/backend-api/koreksi-logbook/{$approvedLogbook->id}", [
                'status_verifikasi' => 'approved',
            ])
            ->assertOk();

        $this->putJson("/backend-api/koreksi-logbook/{$rejectedLogbook->id}", [
            'status_verifikasi' => 'rejected',
            'catatan_pembimbing' => 'Mohon diperbaiki.',
        ])->assertOk();

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $peserta->id_pengguna,
            'judul' => 'Logbook Diverifikasi',
            'pesan' => "Logbook Anda untuk tanggal {$tanggal} telah disetujui oleh Pembimbing.",
            'tipe' => 'success',
            'link' => '/peserta/logbook.html',
        ]);
        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $peserta->id_pengguna,
            'judul' => 'Logbook Ditolak',
            'pesan' => "Logbook Anda untuk tanggal {$tanggal} telah ditolak oleh Pembimbing.",
            'tipe' => 'danger',
            'link' => '/peserta/logbook.html',
        ]);
    }

    public function test_new_izin_notifies_the_assigned_supervisor(): void
    {
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);

        [$unitKerja, , $penggunaPembimbing] = $this->createUnitWithPembimbing('izin-supervisor');
        $peserta = $this->createPeserta($unitKerja, 'izin-participant', 'Nama Izin');

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->post('/backend-api/pengajuan-izin', [
                'jenis' => 'izin',
                'tanggal_mulai' => '2026-11-20',
                'tanggal_selesai' => '2026-11-21',
                'keterangan' => 'Keperluan keluarga.',
                'file_surat' => UploadedFile::fake()->createWithContent('surat.pdf', "%PDF-1.4\nSurat izin\n"),
            ], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $penggunaPembimbing->id,
            'judul' => 'Pengajuan Izin Baru',
            'pesan' => 'Peserta Nama Izin mengajukan izin untuk tanggal 2026-11-20 s/d 2026-11-21.',
            'tipe' => 'warning',
            'sudah_dibaca' => false,
        ]);
    }

    public function test_izin_approval_and_rejection_notify_the_participant(): void
    {
        [$unitKerja, $pembimbing] = $this->createUnitWithPembimbing('izin-review-supervisor');
        $peserta = $this->createPeserta($unitKerja, 'izin-review-participant', 'Nama Izin');
        $approvedIzin = $this->createIzin($peserta, 'izin');
        $rejectedIzin = $this->createIzin($peserta, 'sakit');

        $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->postJson("/backend-api/approve-izin/{$approvedIzin->id}", [
                'status' => 'approved',
            ])
            ->assertOk();

        $this->postJson("/backend-api/approve-izin/{$rejectedIzin->id}", [
            'status' => 'rejected',
        ])->assertOk();

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $peserta->id_pengguna,
            'judul' => 'Pengajuan Izin Disetujui',
            'pesan' => 'Pengajuan izin Anda telah disetujui.',
            'tipe' => 'success',
        ]);
        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $peserta->id_pengguna,
            'judul' => 'Pengajuan Izin Ditolak',
            'pesan' => 'Pengajuan sakit Anda telah ditolak.',
            'tipe' => 'danger',
        ]);
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
        $penggunaPembimbing = $this->createUser($username, 'pembimbing');
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

    private function createPeserta(UnitKerja $unitKerja, string $username, string $nama): PesertaMagang
    {
        $pengguna = $this->createUser($username, 'peserta_magang', $nama);

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

    private function createUser(string $username, string $role, ?string $nama = null): Pengguna
    {
        return Pengguna::create([
            'username' => $username,
            'nama_lengkap' => $nama ?? $username,
            'password' => 'password',
            'role' => $role,
            'status' => 'aktif',
        ]);
    }

    private function createLogbook(PesertaMagang $peserta, string $tanggal): Logbook
    {
        return Logbook::create([
            'id_peserta_magang' => $peserta->id,
            'tanggal' => $tanggal,
            'jam_mulai' => '08:00:00',
            'jam_selesai' => '09:00:00',
            'kegiatan' => 'Mengerjakan laporan.',
        ]);
    }

    private function createIzin(PesertaMagang $peserta, string $jenis): PengajuanIzin
    {
        return PengajuanIzin::create([
            'id_peserta_magang' => $peserta->id,
            'jenis' => $jenis,
            'tanggal_mulai' => '2026-11-20',
            'tanggal_selesai' => '2026-11-21',
            'keterangan' => 'Keterangan izin.',
        ]);
    }
}
