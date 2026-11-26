<?php

namespace Tests\Feature\Api;

use App\Models\Absensi;
use App\Models\Notifikasi;
use App\Models\Pembimbing;
use App\Models\Pengguna;
use App\Models\PesertaMagang;
use App\Models\UnitKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PresensiPenolakanFlowTest extends TestCase
{
    use RefreshDatabase;

    private UnitKerja $unit;

    private Pembimbing $pembimbing;

    private PesertaMagang $peserta;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);

        $this->unit = UnitKerja::create([
            'kode_unit' => 'UNIT-FLOW',
            'nama_unit' => 'Unit Alur Presensi',
            'tipe' => 'unit',
            'status' => 'aktif',
        ]);

        $penggunaPembimbing = Pengguna::create([
            'username' => 'pembimbing-flow',
            'nama_lengkap' => 'Pembimbing Alur',
            'password' => 'password',
            'role' => 'pembimbing',
            'status' => 'aktif',
        ]);

        $this->pembimbing = Pembimbing::create([
            'id_pengguna' => $penggunaPembimbing->id,
            'id_unit_kerja' => $this->unit->id,
            'nip' => 'NIP-FLOW',
            'jabatan' => 'Pembimbing Lapangan',
            'status_pembimbing' => 'aktif',
        ]);

        $penggunaPeserta = Pengguna::create([
            'username' => 'peserta-flow',
            'nama_lengkap' => 'Peserta Alur',
            'password' => 'password',
            'role' => 'peserta_magang',
            'status' => 'aktif',
        ]);

        $this->peserta = PesertaMagang::create([
            'id_pengguna' => $penggunaPeserta->id,
            'id_unit_kerja' => $this->unit->id,
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

    public function test_pembimbing_menolak_presensi_tanpa_paraf_dan_peserta_menerima_notifikasi(): void
    {
        $absensi = $this->buatAbsensi('masuk');

        $this->actingAs($this->pembimbing->pengguna, 'sanctum')
            ->postJson("/backend-api/verifikasi-absensi/{$absensi->id}", [
                'status_verifikasi' => 'rejected',
                'alasan_penolakan' => 'Foto kurang akurat',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status_verifikasi', 'rejected')
            ->assertJsonPath('data.alasan_penolakan', 'Foto kurang akurat');

        $absensi->refresh();
        $this->assertNull($absensi->paraf_pembimbing);
        $this->assertSame($this->pembimbing->id, $absensi->diverifikasi_oleh);
        $this->assertNotNull($absensi->diverifikasi_pada);
        $this->assertCount(1, $absensi->riwayat_penolakan);
        $this->assertSame('Foto kurang akurat', $absensi->riwayat_penolakan[0]['alasan']);
        $this->assertSame('check-in', $absensi->riwayat_penolakan[0]['jenis']);

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $this->peserta->id_pengguna,
            'judul' => 'Presensi Ditolak',
            'tipe' => 'danger',
            'id_absensi' => $absensi->id,
            'link' => '/peserta/absensi.html?perbaikan='.$absensi->id,
            'sudah_dibaca' => false,
        ]);

        $notifikasi = Notifikasi::where('id_absensi', $absensi->id)->firstOrFail();
        $this->assertStringContainsString('Check-in', $notifikasi->pesan);
        $this->assertStringContainsString('Foto kurang akurat', $notifikasi->pesan);

        $this->assertDatabaseHas('log_aktivitas', [
            'id_pengguna' => $this->pembimbing->id_pengguna,
            'aksi' => 'TOLAK_PRESENSI',
        ]);
    }

    public function test_penolakan_wajib_memiliki_alasan(): void
    {
        $absensi = $this->buatAbsensi('masuk');

        $this->actingAs($this->pembimbing->pengguna, 'sanctum')
            ->postJson("/backend-api/verifikasi-absensi/{$absensi->id}", [
                'status_verifikasi' => 'rejected',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('alasan_penolakan');

        $this->assertDatabaseHas('absensi', [
            'id' => $absensi->id,
            'status_verifikasi' => 'pending',
            'alasan_penolakan' => null,
        ]);
        $this->assertSame(0, Notifikasi::count());
    }

    public function test_penolakan_ganda_tidak_membuat_notifikasi_duplikat(): void
    {
        $absensi = $this->buatAbsensi('masuk');

        $payload = [
            'status_verifikasi' => 'rejected',
            'alasan_penolakan' => 'Wajah tidak terlihat',
        ];

        $this->actingAs($this->pembimbing->pengguna, 'sanctum')
            ->postJson("/backend-api/verifikasi-absensi/{$absensi->id}", $payload)
            ->assertOk();

        $this->actingAs($this->pembimbing->pengguna, 'sanctum')
            ->postJson("/backend-api/verifikasi-absensi/{$absensi->id}", $payload)
            ->assertUnprocessable();

        $this->assertSame(1, Notifikasi::where('judul', 'Presensi Ditolak')->count());
        $this->assertCount(1, $absensi->fresh()->riwayat_penolakan);
    }

    public function test_peserta_memperbaiki_check_in_lalu_kembali_ke_antrean_verifikasi(): void
    {
        $absensi = $this->buatAbsensi('masuk');
        $this->tolak($absensi);

        $this->actingAs($this->peserta->pengguna, 'sanctum')
            ->post("/backend-api/absensi/{$absensi->id}/perbaikan", [
                'latitude' => -7.867828,
                'longitude' => 111.462034,
                'lokasi' => 'Kantor Sekretariat Dishub',
                'keterangan' => 'Foto ulang',
                'foto_selfie' => UploadedFile::fake()->create('selfie-baru.jpg', 10, 'image/jpeg'),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status_verifikasi', 'pending')
            ->assertJsonPath('data.jumlah_resubmit', 1);

        $absensi->refresh();
        $this->assertSame('pending', $absensi->status_verifikasi);
        $this->assertSame(1, $absensi->jumlah_resubmit);
        $this->assertNull($absensi->diverifikasi_oleh);
        $this->assertNull($absensi->diverifikasi_pada);
        $this->assertCount(1, $absensi->riwayat_penolakan, 'Riwayat penolakan harus tetap tersimpan.');
        $this->assertSame('Foto kurang akurat', $absensi->riwayat_penolakan[0]['alasan']);
        $this->assertNotSame('absensi/selfie-lama.jpg', $absensi->foto_selfie);

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $this->pembimbing->id_pengguna,
            'judul' => 'Perbaikan Presensi',
            'id_absensi' => $absensi->id,
        ]);
        $this->assertDatabaseHas('log_aktivitas', ['aksi' => 'PERBAIKAN_PRESENSI']);

        // Presensi hasil perbaikan tampil kembali di monitoring pembimbing.
        $this->actingAs($this->pembimbing->pengguna, 'sanctum')
            ->getJson('/backend-api/monitoring')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $absensi->id,
                'status_verifikasi' => 'pending',
                'jumlah_resubmit' => 1,
            ]);
    }

    public function test_pembimbing_menyetujui_presensi_hasil_perbaikan_dengan_paraf(): void
    {
        $absensi = $this->buatAbsensi('masuk');
        $this->tolak($absensi);
        $this->perbaiki($absensi);

        $paraf = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l2cAAAAASUVORK5CYII=';

        $this->actingAs($this->pembimbing->pengguna, 'sanctum')
            ->postJson("/backend-api/verifikasi-absensi/{$absensi->id}", [
                'status_verifikasi' => 'approved',
                'paraf_digital' => $paraf,
            ])
            ->assertOk()
            ->assertJsonPath('data.status_verifikasi', 'approved');

        $absensi->refresh();
        $this->assertSame('approved', $absensi->status_verifikasi);
        $this->assertNotNull($absensi->paraf_pembimbing);
        $this->assertSame(1, $absensi->jumlah_resubmit);
        $this->assertCount(1, $absensi->riwayat_penolakan);
    }

    public function test_penolakan_check_out_tidak_mengubah_check_in_yang_sudah_disetujui(): void
    {
        $checkIn = $this->buatAbsensi('masuk', 'approved');
        $checkOut = $this->buatAbsensi('keluar', 'pending', Carbon::now()->addHours(7));

        $this->tolak($checkOut);

        $checkIn->refresh();
        $this->assertSame('approved', $checkIn->status_verifikasi);
        $this->assertNotNull($checkIn->paraf_pembimbing);

        $checkOut->refresh();
        $this->assertSame('rejected', $checkOut->status_verifikasi);

        $notifikasi = Notifikasi::where('id_absensi', $checkOut->id)->firstOrFail();
        $this->assertStringContainsString('Check-out', $notifikasi->pesan);
    }

    public function test_peserta_memperbaiki_check_out_tanpa_mengubah_check_in(): void
    {
        $checkIn = $this->buatAbsensi('masuk', 'approved');
        $checkOut = $this->buatAbsensi('keluar', 'pending', Carbon::now()->addHours(7));
        $this->tolak($checkOut);

        $this->perbaiki($checkOut);

        $checkIn->refresh();
        $this->assertSame('approved', $checkIn->status_verifikasi);

        $checkOut->refresh();
        $this->assertSame('pending', $checkOut->status_verifikasi);
        $this->assertSame(1, $checkOut->jumlah_resubmit);
        $this->assertCount(1, $checkOut->riwayat_penolakan);
    }

    public function test_peserta_tidak_bisa_memperbaiki_presensi_peserta_lain(): void
    {
        $absensi = $this->buatAbsensi('masuk');
        $this->tolak($absensi);

        $penggunaLain = Pengguna::create([
            'username' => 'peserta-lain',
            'nama_lengkap' => 'Peserta Lain',
            'password' => 'password',
            'role' => 'peserta_magang',
            'status' => 'aktif',
        ]);
        PesertaMagang::create([
            'id_pengguna' => $penggunaLain->id,
            'id_unit_kerja' => $this->unit->id,
            'jenis_peserta' => 'siswa',
            'prodi_jurusan' => 'Teknik',
            'asal_sekolah_kampus' => 'SMK Lain',
            'kelas_semester' => 'XI',
            'status_peserta' => 'aktif',
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31',
            'status_dokumen' => 'pending',
        ]);

        $this->actingAs($penggunaLain, 'sanctum')
            ->post("/backend-api/absensi/{$absensi->id}/perbaikan", [
                'latitude' => -7.867828,
                'longitude' => 111.462034,
                'foto_selfie' => UploadedFile::fake()->create('selfie.jpg', 10, 'image/jpeg'),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertSame('rejected', $absensi->fresh()->status_verifikasi);
    }

    public function test_peserta_tidak_bisa_memperbaiki_presensi_yang_sudah_disetujui_atau_pending(): void
    {
        $approved = $this->buatAbsensi('masuk', 'approved');
        $pending = $this->buatAbsensi('keluar', 'pending', Carbon::now()->addHours(7));

        foreach ([$approved, $pending] as $absensi) {
            $this->actingAs($this->peserta->pengguna, 'sanctum')
                ->post("/backend-api/absensi/{$absensi->id}/perbaikan", [
                    'latitude' => -7.867828,
                    'longitude' => 111.462034,
                    'foto_selfie' => UploadedFile::fake()->create('selfie.jpg', 10, 'image/jpeg'),
                ], ['Accept' => 'application/json'])
                ->assertUnprocessable();
        }
    }

    public function test_perbaikan_tetap_menjalankan_validasi_geofencing(): void
    {
        $absensi = $this->buatAbsensi('masuk');
        $this->tolak($absensi);

        $this->actingAs($this->peserta->pengguna, 'sanctum')
            ->post("/backend-api/absensi/{$absensi->id}/perbaikan", [
                'latitude' => -7.950000,
                'longitude' => 111.500000,
                'foto_selfie' => UploadedFile::fake()->create('selfie.jpg', 10, 'image/jpeg'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertSame('rejected', $absensi->fresh()->status_verifikasi);
    }

    public function test_tamu_tidak_bisa_memperbaiki_presensi(): void
    {
        $absensi = $this->buatAbsensi('masuk');

        $this->post("/backend-api/absensi/{$absensi->id}/perbaikan", [
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'foto_selfie' => UploadedFile::fake()->create('selfie.jpg', 10, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertUnauthorized();
    }

    private function buatAbsensi(string $status, string $statusVerifikasi = 'pending', ?Carbon $waktu = null): Absensi
    {
        return Absensi::create([
            'id_peserta_magang' => $this->peserta->id,
            'id_unit_kerja' => $this->unit->id,
            'tanggal_jam' => $waktu ?? Carbon::now(),
            'foto_selfie' => 'absensi/selfie-lama.jpg',
            'lokasi' => 'Kantor Sekretariat Dishub',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => $status,
            'status_verifikasi' => $statusVerifikasi,
            'paraf_pembimbing' => $statusVerifikasi === 'approved' ? 'paraf/absensi/lama.png' : null,
            'diverifikasi_oleh' => $statusVerifikasi === 'approved' ? $this->pembimbing->id : null,
            'diverifikasi_pada' => $statusVerifikasi === 'approved' ? Carbon::now() : null,
        ]);
    }

    private function tolak(Absensi $absensi): void
    {
        $this->actingAs($this->pembimbing->pengguna, 'sanctum')
            ->postJson("/backend-api/verifikasi-absensi/{$absensi->id}", [
                'status_verifikasi' => 'rejected',
                'alasan_penolakan' => 'Foto kurang akurat',
            ])
            ->assertOk();
    }

    private function perbaiki(Absensi $absensi): void
    {
        $this->actingAs($this->peserta->pengguna, 'sanctum')
            ->post("/backend-api/absensi/{$absensi->id}/perbaikan", [
                'latitude' => -7.867828,
                'longitude' => 111.462034,
                'lokasi' => 'Kantor Sekretariat Dishub',
                'foto_selfie' => UploadedFile::fake()->create('selfie-baru.jpg', 10, 'image/jpeg'),
            ], ['Accept' => 'application/json'])
            ->assertOk();
    }
}
