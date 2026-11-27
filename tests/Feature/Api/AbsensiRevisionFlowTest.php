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

class AbsensiRevisionFlowTest extends TestCase
{
    use RefreshDatabase;

    private UnitKerja $unit;

    private Pengguna $penggunaPembimbing;

    private Pembimbing $pembimbing;

    private Pengguna $penggunaPeserta;

    private PesertaMagang $peserta;

    private string $paraf = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l2cAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'siamag.storage_disk' => 'public',
            'siamag.max_distance_meters' => 70,
        ]);

        $this->unit = UnitKerja::create([
            'kode_unit' => 'UNIT-REVISI',
            'nama_unit' => 'Unit Revisi Absensi',
            'tipe' => 'unit',
            'status' => 'aktif',
        ]);

        $this->penggunaPembimbing = Pengguna::create([
            'username' => 'pembimbing-revisi',
            'nama_lengkap' => 'Pembimbing Revisi',
            'password' => 'password',
            'role' => 'pembimbing',
            'status' => 'aktif',
        ]);

        $this->pembimbing = Pembimbing::create([
            'id_pengguna' => $this->penggunaPembimbing->id,
            'id_unit_kerja' => $this->unit->id,
            'nip' => 'NIP-REVISI',
            'jabatan' => 'Pembimbing Lapangan',
            'status_pembimbing' => 'aktif',
        ]);

        $this->unit->update(['id_pembimbing' => $this->pembimbing->id]);

        $this->penggunaPeserta = Pengguna::create([
            'username' => 'peserta-revisi',
            'nama_lengkap' => 'Peserta Revisi',
            'password' => 'password',
            'role' => 'peserta_magang',
            'status' => 'aktif',
        ]);

        $this->peserta = PesertaMagang::create([
            'id_pengguna' => $this->penggunaPeserta->id,
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

    public function test_pembimbing_meminta_revisi_check_in_dan_peserta_melihat_permintaan(): void
    {
        $absensi = $this->buatAbsensi('masuk');

        $this->actingAs($this->penggunaPembimbing, 'sanctum')
            ->postJson("/backend-api/pembimbing/absensi/{$absensi->id}/minta-revisi", [
                'bagian' => 'check-in',
                'catatan' => 'Foto kurang jelas, mohon ulangi.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.bagian', 'check-in')
            ->assertJsonPath('data.absensi.0.revisi_diminta', true)
            ->assertJsonPath('data.absensi.0.status_revisi', 'menunggu_revisi');

        $absensi->refresh();
        $this->assertTrue($absensi->revisi_diminta);
        $this->assertSame('pending', $absensi->status_verifikasi);
        $this->assertSame('Foto kurang jelas, mohon ulangi.', $absensi->catatan_pembimbing);
        $this->assertSame($this->pembimbing->id, $absensi->diverifikasi_oleh);
        $this->assertNotNull($absensi->revisi_diminta_pada);
        $this->assertNull($absensi->revisi_dikirim_pada);
        $this->assertCount(1, $absensi->riwayat_revisi);
        $this->assertSame('menunggu_peserta', $absensi->riwayat_revisi[0]['status']);
        $this->assertSame('Foto kurang jelas, mohon ulangi.', $absensi->riwayat_revisi[0]['catatan']);
        $this->assertSame($this->pembimbing->id, $absensi->riwayat_revisi[0]['pembimbing_id']);
        $this->assertSame('Pembimbing Revisi', $absensi->riwayat_revisi[0]['pembimbing_nama']);
        $this->assertSame('absensi/selfie/lama.jpg', $absensi->riwayat_revisi[0]['data_sebelum']['foto_selfie']);

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $this->peserta->id_pengguna,
            'judul' => 'Presensi Perlu Direvisi',
            'tipe' => 'warning',
            'id_absensi' => $absensi->id,
        ]);

        $this->actingAs($this->penggunaPeserta, 'sanctum')
            ->getJson('/backend-api/absensi/revisi?aktif=1')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $absensi->id)
            ->assertJsonPath('data.data.0.status_revisi', 'menunggu_revisi');

        $this->actingAs($this->penggunaPeserta, 'sanctum')
            ->getJson("/backend-api/absensi/revisi/{$absensi->id}")
            ->assertOk()
            ->assertJsonPath('data.bagian_revisi_aktif.0.bagian', 'masuk')
            ->assertJsonPath('data.bagian_revisi_aktif.0.status', 'menunggu_peserta');

        $this->actingAs($this->penggunaPembimbing, 'sanctum')
            ->getJson("/backend-api/pembimbing/absensi/{$absensi->id}")
            ->assertOk()
            ->assertJsonPath('data.riwayat_revisi.0.status', 'menunggu_peserta')
            ->assertJsonPath('data.riwayat_revisi.0.bagian', 'masuk');
    }

    public function test_peserta_memperbaiki_check_in_mengirim_revisi_dan_pembimbing_menyetujui(): void
    {
        $absensi = $this->buatAbsensi('masuk');
        $this->mintaRevisi($absensi)->assertOk();

        // Belum dikirim peserta, pembimbing belum boleh memverifikasi.
        $this->actingAs($this->penggunaPembimbing, 'sanctum')
            ->postJson("/backend-api/verifikasi-absensi/{$absensi->id}", [
                'status_verifikasi' => 'approved',
                'paraf_digital' => $this->paraf,
            ])
            ->assertUnprocessable();

        $this->actingAs($this->penggunaPeserta, 'sanctum')
            ->post('/backend-api/check-in', [
                'id_absensi' => $absensi->id,
                'latitude' => -7.867828,
                'longitude' => 111.462034,
                'lokasi' => 'Kantor Sekretariat Dishub',
                'keterangan' => 'Foto ulang',
                'foto_selfie' => UploadedFile::fake()->create('selfie-baru.jpg', 10, 'image/jpeg'),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.revisi_diminta', true);

        $absensi->refresh();
        $this->assertNotSame('absensi/selfie/lama.jpg', $absensi->foto_selfie);
        $this->assertSame('pending', $absensi->status_verifikasi);
        $this->assertCount(1, $absensi->riwayat_revisi);

        $riwayat = $absensi->riwayat_revisi[0];
        $this->assertSame('siap_dikirim', $riwayat['status']);
        $this->assertNotNull($riwayat['diperbaiki_pada']);
        $this->assertSame('masuk', $riwayat['data_setelah']['status']);
        $this->assertSame('Foto ulang', $riwayat['data_setelah']['keterangan']);
        $this->assertSame('absensi/selfie/lama.jpg', $riwayat['data_sebelum']['foto_selfie']);

        $this->assertDatabaseHas('log_aktivitas', [
            'id_pengguna' => $this->penggunaPeserta->id,
            'aksi' => 'REVISI_CHECK_IN',
        ]);

        $this->actingAs($this->penggunaPeserta, 'sanctum')
            ->postJson("/backend-api/absensi/revisi/{$absensi->id}/kirim")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status_revisi', 'menunggu_verifikasi');

        $absensi->refresh();
        $this->assertFalse($absensi->revisi_diminta);
        $this->assertNotNull($absensi->revisi_dikirim_pada);
        $this->assertCount(1, $absensi->riwayat_revisi);
        $this->assertSame('menunggu_verifikasi', $absensi->riwayat_revisi[0]['status']);
        $this->assertNotNull($absensi->riwayat_revisi[0]['dikirim_pada']);

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $this->penggunaPembimbing->id,
            'judul' => 'Revisi Presensi Dikirim',
            'id_absensi' => $absensi->id,
        ]);
        $this->assertDatabaseHas('log_aktivitas', ['aksi' => 'KIRIM_REVISI_ABSENSI']);

        $this->actingAs($this->penggunaPembimbing, 'sanctum')
            ->postJson("/backend-api/verifikasi-absensi/{$absensi->id}", [
                'status_verifikasi' => 'approved',
                'paraf_digital' => $this->paraf,
            ])
            ->assertOk()
            ->assertJsonPath('data.status_verifikasi', 'approved');

        $absensi->refresh();
        $this->assertSame('approved', $absensi->status_verifikasi);
        $this->assertNotNull($absensi->paraf_pembimbing);
        $this->assertCount(1, $absensi->riwayat_revisi);
        $this->assertSame('disetujui', $absensi->riwayat_revisi[0]['status']);
        $this->assertNotNull($absensi->riwayat_revisi[0]['diputuskan_pada']);
        $this->assertSame($this->pembimbing->id, $absensi->riwayat_revisi[0]['diputuskan_oleh']);

        $this->assertDatabaseHas('notifikasi', [
            'id_pengguna' => $this->peserta->id_pengguna,
            'judul' => 'Revisi Presensi Disetujui',
            'id_absensi' => $absensi->id,
        ]);
    }

    public function test_revisi_check_out_tidak_mengubah_check_in_yang_sudah_disetujui(): void
    {
        $checkIn = $this->buatAbsensi('masuk', 'approved');
        $checkOut = $this->buatAbsensi('keluar', 'pending', Carbon::today()->setTime(16, 0));

        $this->mintaRevisi($checkOut, 'check-out', 'Lokasi check-out tidak sesuai.')->assertOk();

        $this->actingAs($this->penggunaPeserta, 'sanctum')
            ->post('/backend-api/check-out', [
                'id_absensi' => $checkOut->id,
                'latitude' => -7.867828,
                'longitude' => 111.462034,
                'lokasi' => 'Kantor Sekretariat Dishub',
                'keterangan' => 'Check-out ulang',
                'foto_selfie' => UploadedFile::fake()->create('out-baru.jpg', 10, 'image/jpeg'),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $checkIn->refresh();
        $this->assertSame('approved', $checkIn->status_verifikasi);
        $this->assertNotNull($checkIn->paraf_pembimbing);
        $this->assertEmpty($checkIn->riwayat_revisi);

        $checkOut->refresh();
        $this->assertSame('pending', $checkOut->status_verifikasi);
        $this->assertTrue($checkOut->revisi_diminta);
        $this->assertSame('siap_dikirim', $checkOut->riwayat_revisi[0]['status']);
        $this->assertNotSame('absensi/selfie/lama.jpg', $checkOut->foto_selfie);

        $this->actingAs($this->penggunaPeserta, 'sanctum')
            ->postJson("/backend-api/absensi/revisi/{$checkOut->id}/kirim")
            ->assertOk();

        $checkIn->refresh();
        $this->assertSame('approved', $checkIn->status_verifikasi);

        $checkOut->refresh();
        $this->assertFalse($checkOut->revisi_diminta);
        $this->assertNotNull($checkOut->revisi_dikirim_pada);
        $this->assertSame('menunggu_verifikasi', $checkOut->riwayat_revisi[0]['status']);
    }

    public function test_pembimbing_dapat_meminta_revisi_lagi_setelah_hasil_revisi_dikirim(): void
    {
        $absensi = $this->buatAbsensi('masuk');
        $this->mintaRevisi($absensi, 'check-in', 'Foto kurang jelas.')->assertOk();
        $this->perbaikiCheckIn($absensi);
        $this->kirimRevisi($absensi)->assertOk();

        $this->mintaRevisi($absensi, 'check-in', 'Foto masih buram.')->assertOk();

        $absensi->refresh();
        $this->assertTrue($absensi->revisi_diminta);
        $this->assertSame('Foto masih buram.', $absensi->catatan_pembimbing);
        $this->assertCount(2, $absensi->riwayat_revisi);
        $this->assertSame('revisi_diulang', $absensi->riwayat_revisi[0]['status']);
        $this->assertNotNull($absensi->riwayat_revisi[0]['diulang_pada']);
        $this->assertSame('menunggu_peserta', $absensi->riwayat_revisi[1]['status']);
        $this->assertSame('Foto masih buram.', $absensi->riwayat_revisi[1]['catatan']);

        $this->assertSame(2, Notifikasi::where('judul', 'Presensi Perlu Direvisi')->count());
    }

    public function test_permintaan_revisi_ganda_saat_masih_aktif_ditolak(): void
    {
        $absensi = $this->buatAbsensi('masuk');
        $this->mintaRevisi($absensi)->assertOk();

        $this->mintaRevisi($absensi, 'check-in', 'Permintaan kedua.')->assertUnprocessable();

        $absensi->refresh();
        $this->assertCount(1, $absensi->riwayat_revisi);
        $this->assertSame('Foto kurang jelas.', $absensi->catatan_pembimbing);
        $this->assertSame(1, Notifikasi::where('judul', 'Presensi Perlu Direvisi')->count());
    }

    public function test_peserta_tidak_bisa_mengirim_revisi_tanpa_permintaan_aktif(): void
    {
        $absensi = $this->buatAbsensi('masuk');

        $this->actingAs($this->penggunaPeserta, 'sanctum')
            ->postJson("/backend-api/absensi/revisi/{$absensi->id}/kirim")
            ->assertUnprocessable();

        $absensi->refresh();
        $this->assertSame('pending', $absensi->status_verifikasi);
        $this->assertFalse($absensi->revisi_diminta);
        $this->assertNull($absensi->revisi_dikirim_pada);
    }

    public function test_perbaikan_revisi_tetap_menjalankan_validasi_geofencing(): void
    {
        $absensi = $this->buatAbsensi('masuk');
        $this->mintaRevisi($absensi)->assertOk();

        $this->actingAs($this->penggunaPeserta, 'sanctum')
            ->post('/backend-api/check-in', [
                'id_absensi' => $absensi->id,
                'latitude' => -7.950000,
                'longitude' => 111.500000,
                'foto_selfie' => UploadedFile::fake()->create('selfie.jpg', 10, 'image/jpeg'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $absensi->refresh();
        $this->assertSame('absensi/selfie/lama.jpg', $absensi->foto_selfie);
        $this->assertSame('menunggu_peserta', $absensi->riwayat_revisi[0]['status']);
        $this->assertTrue($absensi->revisi_diminta);
    }

    public function test_peserta_dapat_memperbaiki_revisi_tanpa_mengirim_id_absensi(): void
    {
        $absensi = $this->buatAbsensi('masuk', 'pending', Carbon::yesterday()->setTime(8, 0));
        $this->mintaRevisi($absensi)->assertOk();

        $this->actingAs($this->penggunaPeserta, 'sanctum')
            ->post('/backend-api/check-in', [
                'latitude' => -7.867828,
                'longitude' => 111.462034,
                'keterangan' => 'Perbaikan tanpa id',
                'foto_selfie' => UploadedFile::fake()->create('selfie-baru.jpg', 10, 'image/jpeg'),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.id', $absensi->id);

        $absensi->refresh();
        $this->assertSame(Carbon::yesterday()->setTime(8, 0)->toDateTimeString(), $absensi->tanggal_jam->toDateTimeString());
        $this->assertSame('Perbaikan tanpa id', $absensi->keterangan);
        $this->assertSame('siap_dikirim', $absensi->riwayat_revisi[0]['status']);
        $this->assertSame(1, Absensi::count());
    }

    public function test_perbaikan_tanpa_id_absensi_ditolak_jika_ada_lebih_dari_satu_permintaan_revisi(): void
    {
        $absensiKemarin = $this->buatAbsensi('masuk', 'pending', Carbon::yesterday()->setTime(8, 0));
        $absensiDuaHariLalu = $this->buatAbsensi('masuk', 'pending', Carbon::today()->subDays(2)->setTime(8, 0));
        $this->mintaRevisi($absensiKemarin)->assertOk();
        $this->mintaRevisi($absensiDuaHariLalu)->assertOk();

        $this->actingAs($this->penggunaPeserta, 'sanctum')
            ->post('/backend-api/check-in', [
                'latitude' => -7.867828,
                'longitude' => 111.462034,
                'foto_selfie' => UploadedFile::fake()->create('selfie-baru.jpg', 10, 'image/jpeg'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->assertTrue($absensiKemarin->fresh()->revisi_diminta);
        $this->assertTrue($absensiDuaHariLalu->fresh()->revisi_diminta);
        $this->assertSame('absensi/selfie/lama.jpg', $absensiKemarin->fresh()->foto_selfie);
        $this->assertSame('absensi/selfie/lama.jpg', $absensiDuaHariLalu->fresh()->foto_selfie);
    }

    public function test_peserta_tidak_bisa_memperbaiki_atau_mengirim_revisi_absensi_peserta_lain(): void
    {
        $absensi = $this->buatAbsensi('masuk');
        $this->mintaRevisi($absensi)->assertOk();

        $penggunaLain = Pengguna::create([
            'username' => 'peserta-revisi-lain',
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
            ->post('/backend-api/check-in', [
                'id_absensi' => $absensi->id,
                'latitude' => -7.867828,
                'longitude' => 111.462034,
                'foto_selfie' => UploadedFile::fake()->create('selfie.jpg', 10, 'image/jpeg'),
            ], ['Accept' => 'application/json'])
            ->assertNotFound();

        $this->actingAs($penggunaLain, 'sanctum')
            ->postJson("/backend-api/absensi/revisi/{$absensi->id}/kirim")
            ->assertForbidden();

        $absensi->refresh();
        $this->assertTrue($absensi->revisi_diminta);
        $this->assertSame('absensi/selfie/lama.jpg', $absensi->foto_selfie);
    }

    public function test_pembimbing_tidak_bisa_meminta_revisi_absensi_di_luar_unit_kerjanya(): void
    {
        $unitLain = UnitKerja::create([
            'kode_unit' => 'UNIT-LAIN',
            'nama_unit' => 'Unit Lain',
            'tipe' => 'unit',
            'status' => 'aktif',
        ]);
        $penggunaPembimbingLain = Pengguna::create([
            'username' => 'pembimbing-lain',
            'nama_lengkap' => 'Pembimbing Lain',
            'password' => 'password',
            'role' => 'pembimbing',
            'status' => 'aktif',
        ]);
        Pembimbing::create([
            'id_pengguna' => $penggunaPembimbingLain->id,
            'id_unit_kerja' => $unitLain->id,
            'nip' => 'NIP-LAIN',
            'jabatan' => 'Pembimbing',
            'status_pembimbing' => 'aktif',
        ]);

        $absensi = $this->buatAbsensi('masuk');

        $this->actingAs($penggunaPembimbingLain, 'sanctum')
            ->postJson("/backend-api/pembimbing/absensi/{$absensi->id}/minta-revisi", [
                'bagian' => 'check-in',
                'catatan' => 'Tidak berhak.',
            ])
            ->assertNotFound();

        $absensi->refresh();
        $this->assertFalse($absensi->revisi_diminta);
        $this->assertEmpty($absensi->riwayat_revisi);
    }

    public function test_pembimbing_tidak_bisa_menyetujui_presensi_jika_pasangannya_masih_revisi(): void
    {
        $checkIn = $this->buatAbsensi('masuk');
        $checkOut = $this->buatAbsensi('keluar', 'pending', Carbon::today()->setTime(16, 0));

        $this->mintaRevisi($checkIn)->assertOk();

        $this->actingAs($this->penggunaPembimbing, 'sanctum')
            ->postJson("/backend-api/verifikasi-absensi/{$checkOut->id}", [
                'status_verifikasi' => 'approved',
                'paraf_digital' => $this->paraf,
            ])
            ->assertUnprocessable();

        $checkOut->refresh();
        $this->assertSame('pending', $checkOut->status_verifikasi);
    }

    public function test_permintaan_revisi_kedua_bagian_harus_diperbaiki_lalu_dikirim_bersama(): void
    {
        $checkIn = $this->buatAbsensi('masuk');
        $checkOut = $this->buatAbsensi('keluar', 'pending', Carbon::today()->setTime(16, 0));

        $this->mintaRevisi($checkIn, 'both', 'Perbaiki check-in dan check-out.')->assertOk();

        $checkIn->refresh();
        $checkOut->refresh();
        $this->assertTrue($checkIn->revisi_diminta);
        $this->assertTrue($checkOut->revisi_diminta);
        $this->assertCount(1, $checkIn->riwayat_revisi);
        $this->assertCount(1, $checkOut->riwayat_revisi);

        $this->perbaikiCheckIn($checkIn);

        // Bagian check-out belum diperbaiki, pengiriman harus ditolak.
        $this->kirimRevisi($checkIn)->assertUnprocessable();

        $this->actingAs($this->penggunaPeserta, 'sanctum')
            ->post('/backend-api/check-out', [
                'id_absensi' => $checkOut->id,
                'latitude' => -7.867828,
                'longitude' => 111.462034,
                'foto_selfie' => UploadedFile::fake()->create('out-baru.jpg', 10, 'image/jpeg'),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->kirimRevisi($checkIn)->assertOk();

        $checkIn->refresh();
        $checkOut->refresh();
        $this->assertFalse($checkIn->revisi_diminta);
        $this->assertFalse($checkOut->revisi_diminta);
        $this->assertSame('menunggu_verifikasi', $checkIn->riwayat_revisi[0]['status']);
        $this->assertSame('menunggu_verifikasi', $checkOut->riwayat_revisi[0]['status']);

        foreach ([$checkIn, $checkOut] as $absensi) {
            $this->actingAs($this->penggunaPembimbing, 'sanctum')
                ->postJson("/backend-api/verifikasi-absensi/{$absensi->id}", [
                    'status_verifikasi' => 'approved',
                    'paraf_digital' => $this->paraf,
                ])
                ->assertOk();
        }

        $this->assertSame('approved', $checkIn->fresh()->status_verifikasi);
        $this->assertSame('approved', $checkOut->fresh()->status_verifikasi);
        $this->assertSame('disetujui', $checkIn->fresh()->riwayat_revisi[0]['status']);
        $this->assertSame('disetujui', $checkOut->fresh()->riwayat_revisi[0]['status']);
    }

    private function buatAbsensi(string $status, string $statusVerifikasi = 'pending', ?Carbon $waktu = null): Absensi
    {
        return Absensi::create([
            'id_peserta_magang' => $this->peserta->id,
            'id_unit_kerja' => $this->unit->id,
            'tanggal_jam' => $waktu ?? Carbon::today()->setTime(8, 0),
            'foto_selfie' => 'absensi/selfie/lama.jpg',
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

    private function mintaRevisi(Absensi $absensi, string $bagian = 'check-in', string $catatan = 'Foto kurang jelas.')
    {
        return $this->actingAs($this->penggunaPembimbing, 'sanctum')
            ->postJson("/backend-api/pembimbing/absensi/{$absensi->id}/minta-revisi", [
                'bagian' => $bagian,
                'catatan' => $catatan,
            ]);
    }

    private function perbaikiCheckIn(Absensi $absensi)
    {
        return $this->actingAs($this->penggunaPeserta, 'sanctum')
            ->post('/backend-api/check-in', [
                'id_absensi' => $absensi->id,
                'latitude' => -7.867828,
                'longitude' => 111.462034,
                'lokasi' => 'Kantor Sekretariat Dishub',
                'keterangan' => 'Foto ulang',
                'foto_selfie' => UploadedFile::fake()->create('selfie-baru.jpg', 10, 'image/jpeg'),
            ], ['Accept' => 'application/json'])
            ->assertOk();
    }

    private function kirimRevisi(Absensi $absensi)
    {
        return $this->actingAs($this->penggunaPeserta, 'sanctum')
            ->postJson("/backend-api/absensi/revisi/{$absensi->id}/kirim");
    }
}
