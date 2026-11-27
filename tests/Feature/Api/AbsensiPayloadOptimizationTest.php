<?php

namespace Tests\Feature\Api;

use App\Models\Absensi;
use App\Models\Pembimbing;
use App\Models\Pengguna;
use App\Models\PesertaMagang;
use App\Models\UnitKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AbsensiPayloadOptimizationTest extends TestCase
{
    use RefreshDatabase;

    private UnitKerja $unit;

    private Pengguna $penggunaPembimbing;

    private Pembimbing $pembimbing;

    private Pengguna $penggunaPeserta;

    private PesertaMagang $peserta;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['siamag.storage_disk' => 'public']);

        $this->unit = UnitKerja::create([
            'kode_unit' => 'UNIT-PAYLOAD',
            'nama_unit' => 'Unit Payload',
            'tipe' => 'unit',
            'status' => 'aktif',
        ]);

        $this->penggunaPembimbing = Pengguna::create([
            'username' => 'pembimbing-payload',
            'nama_lengkap' => 'Pembimbing Payload',
            'password' => 'password',
            'role' => 'pembimbing',
            'status' => 'aktif',
        ]);

        $this->pembimbing = Pembimbing::create([
            'id_pengguna' => $this->penggunaPembimbing->id,
            'id_unit_kerja' => $this->unit->id,
            'nip' => 'NIP-PAYLOAD',
            'jabatan' => 'Pembimbing Lapangan',
            'status_pembimbing' => 'aktif',
        ]);
        $this->unit->update(['id_pembimbing' => $this->pembimbing->id]);

        $this->penggunaPeserta = Pengguna::create([
            'username' => 'peserta-payload',
            'nama_lengkap' => 'Peserta Payload',
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
            'status_dokumen' => 'lengkap',
            'file_surat_permohonan' => $this->base64Gambar(100_000),
            'file_surat_balasan' => $this->base64Gambar(100_000),
        ]);
    }

    public function test_daftar_absensi_pembimbing_tidak_mengirim_base64_dan_per_page_100_dibatasi_25(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->buatAbsensi('masuk', Carbon::parse('2026-11-01 08:00:00')->addMinutes($i)->toDateTimeString(), [
                'riwayat_revisi' => [[
                    'bagian' => 'masuk',
                    'status' => 'menunggu_peserta',
                    'catatan' => 'Perbaiki foto.',
                    'data_sebelum' => [
                        'foto_selfie' => $this->base64Gambar(60_000),
                        'paraf_pembimbing' => null,
                    ],
                    'data_setelah' => null,
                ]],
            ]);
        }

        $response = $this->actingAs($this->penggunaPembimbing, 'sanctum')
            ->getJson('/backend-api/pembimbing/absensi?per_page=100')
            ->assertOk()
            ->assertJsonCount(25, 'data.data')
            ->assertJsonPath('data.meta.total', 26)
            ->assertJsonPath('data.meta.per_page', 25)
            ->assertJsonMissingPath('data.data.0.peserta_magang.file_surat_permohonan')
            ->assertJsonMissingPath('data.data.0.peserta_magang.file_surat_balasan')
            ->assertJsonMissingPath('data.data.0.peserta_magang.url_surat_permohonan')
            ->assertJsonMissingPath('data.data.0.peserta_magang.url_surat_balasan')
            ->assertJsonMissingPath('data.data.0.riwayat_revisi.0.data_sebelum')
            ->assertJsonPath('data.data.0.peserta_magang.pengguna.nama_lengkap', 'Peserta Payload');

        $body = $response->getContent();

        $this->assertStringNotContainsString('data:image', $body);
        $this->assertLessThan(300_000, strlen($body), 'Payload daftar absensi still too large: '.strlen($body));

        $fotoUrl = $response->json('data.data.0.foto_selfie_url');
        $this->assertIsString($fotoUrl);
        $this->assertStringContainsString('/media/selfie', $fotoUrl);
        $this->assertStringContainsString('signature=', $fotoUrl);

        $this->actingAs($this->penggunaPembimbing, 'sanctum')
            ->getJson('/backend-api/pembimbing/absensi?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data.data')
            ->assertJsonPath('data.meta.per_page', 10);

        $this->actingAs($this->penggunaPembimbing, 'sanctum')
            ->getJson('/backend-api/pembimbing/absensi?per_page=20')
            ->assertOk()
            ->assertJsonCount(20, 'data.data')
            ->assertJsonPath('data.meta.per_page', 20);
    }

    public function test_daftar_absensi_admin_default_20_dan_maksimum_25(): void
    {
        $admin = Pengguna::create([
            'username' => 'admin-payload',
            'nama_lengkap' => 'Admin Payload',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'aktif',
        ]);

        for ($i = 0; $i < 30; $i++) {
            $this->buatAbsensi('masuk', Carbon::parse('2026-12-01 08:00:00')->addMinutes($i)->toDateTimeString());
        }

        $this->actingAs($admin, 'sanctum')
            ->getJson('/backend-api/admin/absensi')
            ->assertOk()
            ->assertJsonCount(20, 'data.data')
            ->assertJsonPath('data.per_page', 20);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/backend-api/admin/absensi?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data.data')
            ->assertJsonPath('data.per_page', 10);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/backend-api/admin/absensi?per_page=100')
            ->assertOk()
            ->assertJsonCount(25, 'data.data')
            ->assertJsonPath('data.per_page', 25);
    }

    public function test_detail_absensi_menyajikan_foto_check_in_check_out_dan_paraf_lewat_url_bertanda_tangan(): void
    {
        $checkIn = $this->buatAbsensi('masuk', '2026-11-10 08:00:00', [
            'foto_selfie' => $this->base64Gambar(80_000),
            'paraf_pembimbing' => $this->base64Gambar(20_000),
            'status_verifikasi' => 'approved',
        ]);
        $this->buatAbsensi('keluar', '2026-11-10 17:00:00', [
            'foto_selfie' => $this->base64Gambar(80_000),
        ]);

        $response = $this->actingAs($this->penggunaPembimbing, 'sanctum')
            ->getJson("/backend-api/pembimbing/absensi/{$checkIn->id}")
            ->assertOk();

        $body = $response->getContent();
        $this->assertStringNotContainsString('data:image', $body);
        $this->assertStringContainsString('/media/selfie', $response->json('data.absensi.foto_selfie_url'));
        $this->assertStringContainsString('/media/paraf', $response->json('data.absensi.paraf_pembimbing_url'));
        $this->assertStringContainsString('/media/selfie', $response->json('data.pasangan.check_in.foto_selfie_url'));
        $this->assertStringContainsString('/media/selfie', $response->json('data.pasangan.check_out.foto_selfie_url'));

        foreach ([
            $response->json('data.absensi.foto_selfie_url'),
            $response->json('data.absensi.paraf_pembimbing_url'),
            $response->json('data.pasangan.check_out.foto_selfie_url'),
        ] as $url) {
            $this->assertMediaBisaDiakses($url);
        }
    }

    public function test_riwayat_revisi_detail_memakai_url_media_dan_data_database_tidak_berubah(): void
    {
        $selfieLama = $this->base64Gambar(70_000);
        $absensi = $this->buatAbsensi('masuk', '2026-11-12 08:00:00', [
            'foto_selfie' => $this->base64Gambar(50_000),
            'status_verifikasi' => 'pending',
            'revisi_diminta' => true,
            'catatan_pembimbing' => 'Foto buram.',
            'riwayat_revisi' => [[
                'bagian' => 'masuk',
                'status' => 'menunggu_peserta',
                'catatan' => 'Foto buram.',
                'pembimbing_nama' => 'Pembimbing Payload',
                'diminta_pada' => '2026-11-12T09:00:00+07:00',
                'data_sebelum' => [
                    'foto_selfie' => $selfieLama,
                    'paraf_pembimbing' => null,
                ],
                'data_setelah' => null,
            ]],
        ]);

        $response = $this->actingAs($this->penggunaPembimbing, 'sanctum')
            ->getJson("/backend-api/pembimbing/absensi/{$absensi->id}")
            ->assertOk()
            ->assertJsonPath('data.riwayat_revisi.0.status', 'menunggu_peserta');

        $body = $response->getContent();
        $this->assertStringNotContainsString('data:image', $body);

        $snapshotUrl = $response->json('data.riwayat_revisi.0.data_sebelum.foto_selfie');
        $this->assertIsString($snapshotUrl);
        $this->assertStringContainsString('/media/revisi/', $snapshotUrl);
        $this->assertMediaBisaDiakses($snapshotUrl);

        $this->assertSame($selfieLama, $absensi->fresh()->riwayat_revisi[0]['data_sebelum']['foto_selfie']);
    }

    public function test_filter_peserta_status_dan_tanggal_tetap_berfungsi_dengan_pagination(): void
    {
        $penggunaLain = Pengguna::create([
            'username' => 'peserta-lain',
            'nama_lengkap' => 'Peserta Lain',
            'password' => 'password',
            'role' => 'peserta_magang',
            'status' => 'aktif',
        ]);
        $pesertaLain = PesertaMagang::create([
            'id_pengguna' => $penggunaLain->id,
            'id_unit_kerja' => $this->unit->id,
            'jenis_peserta' => 'siswa',
            'prodi_jurusan' => 'Teknik Kendaraan',
            'asal_sekolah_kampus' => 'SMK Lain',
            'kelas_semester' => 'XI',
            'status_peserta' => 'aktif',
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31',
            'status_dokumen' => 'pending',
        ]);

        $absensiTarget = $this->buatAbsensi('masuk', '2026-11-15 08:00:00', [
            'status_verifikasi' => 'approved',
        ]);
        $this->buatAbsensi('masuk', '2026-11-16 08:00:00', [
            'id_peserta_magang' => $pesertaLain->id,
            'status_verifikasi' => 'pending',
        ]);

        $this->actingAs($this->penggunaPembimbing, 'sanctum')
            ->getJson('/backend-api/pembimbing/absensi?id_peserta_magang='.$this->peserta->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $absensiTarget->id);

        $this->actingAs($this->penggunaPembimbing, 'sanctum')
            ->getJson('/backend-api/pembimbing/absensi?status_verifikasi=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id_peserta_magang', $pesertaLain->id);

        $this->actingAs($this->penggunaPembimbing, 'sanctum')
            ->getJson('/backend-api/pembimbing/absensi?tanggal_mulai=2026-11-15&tanggal_selesai=2026-11-15')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $absensiTarget->id);

        $this->actingAs($this->penggunaPembimbing, 'sanctum')
            ->getJson('/backend-api/pembimbing/absensi?search=Peserta Lain')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id_peserta_magang', $pesertaLain->id);
    }

    public function test_migrate_db_tidak_lagi_dapat_diakses_tanpa_login(): void
    {
        $this->getJson('/backend-api/migrate-db')->assertUnauthorized();

        $this->actingAs($this->penggunaPeserta, 'sanctum')
            ->getJson('/backend-api/migrate-db')
            ->assertForbidden();
    }

    private function buatAbsensi(string $status, string $waktu, array $override = []): Absensi
    {
        return Absensi::create(array_merge([
            'id_peserta_magang' => $this->peserta->id,
            'id_unit_kerja' => $this->unit->id,
            'tanggal_jam' => $waktu,
            'foto_selfie' => $this->base64Gambar(10_000),
            'lokasi' => 'Kantor Dishub',
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => $status,
            'status_verifikasi' => 'pending',
        ], $override));
    }

    private function base64Gambar(int $ukuran): string
    {
        return 'data:image/png;base64,'.base64_encode(str_repeat('A', $ukuran));
    }

    private function assertMediaBisaDiakses(string $url): void
    {
        $bagian = parse_url($url);
        $path = ($bagian['path'] ?? '').'?'.($bagian['query'] ?? '');

        $response = $this->get($path)->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertGreaterThan(0, strlen($response->streamedContent()));
    }
}
