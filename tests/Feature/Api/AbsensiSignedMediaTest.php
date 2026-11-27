<?php

namespace Tests\Feature\Api;

use App\Models\Absensi;
use App\Models\Pembimbing;
use App\Models\Pengguna;
use App\Models\PesertaMagang;
use App\Models\UnitKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbsensiSignedMediaTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l2cAAAAASUVORK5CYII=';

    public function test_url_media_memakai_prefix_backend_api_dan_berlaku_enam_puluh_menit(): void
    {
        [$pembimbing, $absensi] = $this->buatFixture();

        $response = $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->getJson("/backend-api/pembimbing/absensi/{$absensi->id}")
            ->assertOk();

        $selfieUrl = $response->json('data.absensi.foto_selfie_url');
        $parafUrl = $response->json('data.absensi.paraf_pembimbing_url');

        $this->assertNotNull($selfieUrl);
        $this->assertNotNull($parafUrl);

        $path = parse_url($selfieUrl, PHP_URL_PATH);
        $query = $this->parseQuery($selfieUrl);

        $this->assertSame("/backend-api/absensi/{$absensi->id}/media/selfie", $path);
        $this->assertArrayHasKey('expires', $query);
        $this->assertArrayHasKey('signature', $query);
        $this->assertSame(3600, (int) $query['expires'] - now()->timestamp);

        $this->assertStringStartsWith(
            "/backend-api/absensi/{$absensi->id}/media/paraf",
            (string) parse_url($parafUrl, PHP_URL_PATH),
        );

        $this->assertStringNotContainsString('data:image', $response->getContent());
    }

    public function test_media_tetap_bisa_diakses_sebelum_kedaluwarsa(): void
    {
        [$pembimbing, $absensi] = $this->buatFixture();
        $url = $this->urlSelfie($pembimbing, $absensi);

        $this->travel(59)->minutes();

        $response = $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $body = $response->streamedContent();
        $this->assertSame(strlen(base64_decode(self::PNG_BASE64)), strlen($body));
        $this->assertStringNotContainsString('data:image', $body);
        $this->assertStringNotContainsString('base64', $body);
    }

    public function test_media_tidak_dapat_diakses_setelah_kedaluwarsa(): void
    {
        [$pembimbing, $absensi] = $this->buatFixture();
        $url = $this->urlSelfie($pembimbing, $absensi);

        $this->travel(61)->minutes();

        $this->get($url)->assertForbidden();
    }

    public function test_media_tanpa_signature_ditolak(): void
    {
        [$pembimbing, $absensi] = $this->buatFixture();

        $this->get("/backend-api/absensi/{$absensi->id}/media/selfie")->assertForbidden();
        $this->get("/backend-api/absensi/{$absensi->id}/media/paraf")->assertForbidden();
    }

    public function test_signature_tidak_dapat_dipakai_untuk_absensi_lain(): void
    {
        [$pembimbing, $absensi] = $this->buatFixture();
        $absensiLain = $this->buatAbsensiLain($absensi->id_peserta_magang);

        $url = $this->urlSelfie($pembimbing, $absensi);
        $urlLain = str_replace(
            "/backend-api/absensi/{$absensi->id}/media/selfie",
            "/backend-api/absensi/{$absensiLain->id}/media/selfie",
            $url,
        );

        $this->get($urlLain)->assertForbidden();
    }

    public function test_signature_yang_dimanipulasi_ditolak(): void
    {
        [$pembimbing, $absensi] = $this->buatFixture();
        $url = $this->urlSelfie($pembimbing, $absensi);

        $query = $this->parseQuery($url);
        $signature = $query['signature'];
        $signature[0] = $signature[0] === 'a' ? 'b' : 'a';
        $urlManipulasi = str_replace($query['signature'], $signature, $url);

        $this->get($urlManipulasi)->assertForbidden();
    }

    public function test_parameter_snapshot_tidak_dapat_diubah_tanpa_membatalkan_signature(): void
    {
        [$pembimbing, $absensi] = $this->buatFixture();

        $url = $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->getJson("/backend-api/pembimbing/absensi/{$absensi->id}")
            ->assertOk()
            ->json('data.absensi.riwayat_revisi.0.data_sebelum.foto_selfie');

        $this->assertIsString($url);

        $this->get(str_replace('/sebelum/', '/setelah/', $url))->assertForbidden();
        $this->get(str_replace('/foto_selfie', '/paraf_pembimbing', $url))->assertForbidden();
        $this->get(str_replace('/media/revisi/0/', '/media/revisi/1/', $url))->assertForbidden();
        $this->get(preg_replace('/expires=\d+/', 'expires=9999999999', $url))->assertForbidden();
    }

    public function test_media_paraf_dan_snapshot_revisi_bisa_diakses(): void
    {
        [$pembimbing, $absensi] = $this->buatFixture();

        $detail = $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->getJson("/backend-api/pembimbing/absensi/{$absensi->id}")
            ->assertOk();

        $this->get($detail->json('data.absensi.paraf_pembimbing_url'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $snapshotUrl = $detail->json('data.absensi.riwayat_revisi.0.data_sebelum.foto_selfie');
        $this->assertNotNull($snapshotUrl);
        $this->assertStringNotContainsString('data:image', (string) $snapshotUrl);
        $this->assertStringStartsWith(
            "/backend-api/absensi/{$absensi->id}/media/revisi/0/sebelum/foto_selfie",
            (string) parse_url($snapshotUrl, PHP_URL_PATH),
        );

        $this->get($snapshotUrl)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_signature_valid_untuk_index_snapshot_tidak_ada_mengembalikan_404_bukan_data_lain(): void
    {
        [$pembimbing, $absensi] = $this->buatFixture();

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'absensi.media.revisi',
            now()->addMinutes(60),
            [
                'absensi' => $absensi->id,
                'index' => 9,
                'posisi' => 'sebelum',
                'field' => 'foto_selfie',
            ],
        );

        $this->get($url)
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_media_absensi_tanpa_foto_mengembalikan_404_json(): void
    {
        [, $absensi] = $this->buatFixture(fotoKosong: true);

        $url = \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'absensi.media.selfie',
            now()->addMinutes(60),
            ['absensi' => $absensi->id],
        );

        $this->getJson($url)
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Media absensi tidak ditemukan.');
    }

    private function buatFixture(bool $fotoKosong = false): array
    {
        $unitKerja = UnitKerja::create([
            'kode_unit' => 'UNIT-MEDIA',
            'nama_unit' => 'Unit Media',
            'tipe' => 'unit',
            'status' => 'aktif',
        ]);

        $penggunaPembimbing = Pengguna::create([
            'username' => 'pembimbing-media',
            'nama_lengkap' => 'Pembimbing Media',
            'password' => 'password',
            'role' => 'pembimbing',
            'status' => 'aktif',
        ]);

        $pembimbing = Pembimbing::create([
            'id_pengguna' => $penggunaPembimbing->id,
            'id_unit_kerja' => $unitKerja->id,
            'nip' => 'NIP-MEDIA',
            'jabatan' => 'Pembimbing Lapangan',
            'status_pembimbing' => 'aktif',
        ]);

        $penggunaPeserta = Pengguna::create([
            'username' => 'peserta-media',
            'nama_lengkap' => 'Peserta Media',
            'password' => 'password',
            'role' => 'peserta_magang',
            'status' => 'aktif',
        ]);

        $peserta = PesertaMagang::create([
            'id_pengguna' => $penggunaPeserta->id,
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

        $selfie = 'data:image/png;base64,'.self::PNG_BASE64;
        $paraf = 'data:image/png;base64,'.self::PNG_BASE64;

        $absensi = Absensi::create([
            'id_peserta_magang' => $peserta->id,
            'id_unit_kerja' => $unitKerja->id,
            'tanggal_jam' => '2026-11-13 08:00:00',
            'foto_selfie' => $fotoKosong ? '' : $selfie,
            'paraf_pembimbing' => $paraf,
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'masuk',
            'status_verifikasi' => 'approved',
            'diverifikasi_oleh' => $pembimbing->id,
            'diverifikasi_pada' => '2026-11-13 09:00:00',
            'riwayat_revisi' => [
                [
                    'jenis' => 'minta_revisi',
                    'waktu' => '2026-11-13 09:05:00',
                    'catatan' => 'Foto kurang jelas.',
                    'data_sebelum' => [
                        'foto_selfie' => 'data:image/png;base64,'.self::PNG_BASE64,
                        'paraf_pembimbing' => null,
                    ],
                    'data_setelah' => [
                        'foto_selfie' => $selfie,
                    ],
                ],
            ],
        ]);

        return [$pembimbing, $absensi];
    }

    private function buatAbsensiLain(int $idPeserta): Absensi
    {
        return Absensi::create([
            'id_peserta_magang' => $idPeserta,
            'tanggal_jam' => '2026-11-14 08:00:00',
            'foto_selfie' => 'data:image/png;base64,'.self::PNG_BASE64,
            'latitude' => -7.867828,
            'longitude' => 111.462034,
            'status' => 'masuk',
            'status_verifikasi' => 'pending',
        ]);
    }

    private function urlSelfie(Pembimbing $pembimbing, Absensi $absensi): string
    {
        $url = $this->actingAs($pembimbing->pengguna, 'sanctum')
            ->getJson("/backend-api/pembimbing/absensi/{$absensi->id}")
            ->assertOk()
            ->json('data.absensi.foto_selfie_url');

        $this->assertIsString($url);

        return $url;
    }

    private function parseQuery(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
