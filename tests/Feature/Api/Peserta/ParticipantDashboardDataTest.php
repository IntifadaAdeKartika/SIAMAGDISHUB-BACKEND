<?php

namespace Tests\Feature\Api\Peserta;

use App\Models\Absensi;
use App\Models\Logbook;
use App\Models\Pengguna;
use App\Models\PesertaMagang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ParticipantDashboardDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_check_in_appears_in_today_status_and_attendance_history(): void
    {
        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);
        $this->travelTo('2026-11-13 08:00:00');
        $peserta = $this->createPeserta('peserta-checkin');

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/check-in', [
                'latitude' => -7.867828,
                'longitude' => 111.462034,
                'foto_selfie' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l2cAAAAASUVORK5CYII=',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'masuk');

        $absensi = Absensi::firstOrFail();
        $this->getJson('/backend-api/absensi/today')
            ->assertOk()
            ->assertJsonPath('data.has_checked_in', true)
            ->assertJsonPath('data.check_in.id', $absensi->id);
        $this->getJson('/backend-api/riwayat')
            ->assertOk()
            ->assertJsonPath('data.riwayat_absensi.0.id', $absensi->id);

        $this->postJson('/backend-api/check-in', [
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'foto_selfie' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l2cAAAAASUVORK5CYII=',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Anda sudah melakukan check-in untuk hari ini.');

        $this->assertDatabaseCount('absensi', 1);
        Storage::disk('public')->assertExists($absensi->foto_selfie);
    }

    public function test_new_and_updated_logbook_appears_in_participant_logbook_list(): void
    {
        $this->travelTo('2026-11-13 08:00:00');
        $peserta = $this->createPeserta('peserta-logbook');
        $absensi = Absensi::create([
            'id_peserta_magang' => $peserta->id,
            'tanggal_jam' => now(),
            'foto_selfie' => 'absensi/selfie.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'masuk',
            'status_verifikasi' => 'approved',
            'diverifikasi_pada' => now(),
        ]);

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->postJson('/backend-api/logbook', [
                'id_absensi' => $absensi->id,
                'tanggal' => '2026-11-13',
                'jam_mulai' => '08:00',
                'jam_selesai' => '09:00',
                'kegiatan' => 'Mengerjakan laporan operasional',
            ])
            ->assertCreated()
            ->assertJsonPath('data.kegiatan', 'Mengerjakan laporan operasional')
            ->assertJsonPath('data.is_draft', false);

        $logbook = Logbook::firstOrFail();
        $this->putJson("/backend-api/logbook/{$logbook->id}", [
            'kegiatan' => 'Memperbarui laporan operasional',
        ])->assertOk()
            ->assertJsonPath('data.kegiatan', 'Memperbarui laporan operasional');

        $this->getJson('/backend-api/logbook')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $logbook->id,
                'kegiatan' => 'Memperbarui laporan operasional',
            ]);
    }

    public function test_rejected_attendance_history_includes_the_rejection_reason(): void
    {
        $peserta = $this->createPeserta('peserta-rejected');
        $absensi = Absensi::create([
            'id_peserta_magang' => $peserta->id,
            'tanggal_jam' => '2026-11-12 08:00:00',
            'foto_selfie' => 'absensi/selfie.jpg',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'masuk',
            'status_verifikasi' => 'rejected',
            'alasan_penolakan' => 'Foto selfie kurang jelas.',
        ]);

        $this->actingAs($peserta->pengguna, 'sanctum')
            ->getJson('/backend-api/riwayat')
            ->assertOk()
            ->assertJsonPath('data.riwayat_absensi.0.id', $absensi->id)
            ->assertJsonPath('data.riwayat_absensi.0.alasan_penolakan', 'Foto selfie kurang jelas.');
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
