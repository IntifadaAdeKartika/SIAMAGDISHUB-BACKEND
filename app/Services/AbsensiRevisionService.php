<?php

namespace App\Services;

use App\Models\Absensi;
use App\Models\Pembimbing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AbsensiRevisionService
{
    public const BAGIAN_CHECK_IN = 'check-in';

    public const BAGIAN_CHECK_OUT = 'check-out';

    public const BAGIAN_BOTH = 'both';

    /**
     * @return array{check-in: ?Absensi, check-out: ?Absensi}
     */
    public function pasangan(Absensi $absensi, bool $lock = false): array
    {
        $tanggal = Carbon::parse($absensi->tanggal_jam ?? Carbon::today())->toDateString();

        $query = Absensi::query()
            ->where('id_peserta_magang', $absensi->id_peserta_magang)
            ->whereDate('tanggal_jam', $tanggal)
            ->whereIn('status', ['masuk', 'keluar'])
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        $rows = $query->get();

        return [
            'check-in' => $rows->firstWhere('status', 'masuk'),
            'check-out' => $rows->firstWhere('status', 'keluar'),
        ];
    }

    /**
     * @return Collection<int, Absensi>
     */
    public function targetUntukBagian(Absensi $anchor, string $bagian, bool $lock = false): Collection
    {
        $pasangan = $this->pasangan($anchor, $lock);

        $bagianDiminta = match ($bagian) {
            self::BAGIAN_CHECK_IN => [self::BAGIAN_CHECK_IN],
            self::BAGIAN_CHECK_OUT => [self::BAGIAN_CHECK_OUT],
            self::BAGIAN_BOTH => [self::BAGIAN_CHECK_IN, self::BAGIAN_CHECK_OUT],
            default => throw ValidationException::withMessages([
                'bagian' => ['Bagian revisi tidak dikenali.'],
            ]),
        };

        $target = collect();

        foreach ($bagianDiminta as $namaBagian) {
            $absensi = $pasangan[$namaBagian];

            if (! $absensi) {
                throw ValidationException::withMessages([
                    'bagian' => ['Absensi '.$this->labelBagian($namaBagian).' untuk tanggal ini belum ada.'],
                ]);
            }

            $target->push($absensi);
        }

        return $target;
    }

    /**
     * @return Collection<int, Absensi>
     */
    public function mintaRevisi(
        Absensi $anchor,
        string $bagian,
        string $catatan,
        Pembimbing $pembimbing,
        ?string $namaPembimbing = null,
    ): Collection {
        $target = $this->targetUntukBagian($anchor, $bagian, true);
        $waktu = Carbon::now();

        foreach ($target as $absensi) {
            if ($absensi->status_verifikasi === 'approved') {
                throw ValidationException::withMessages([
                    'bagian' => ['Absensi '.$this->labelBagian($absensi->status).' sudah disetujui dan tidak dapat diminta revisi.'],
                ]);
            }

            if ($absensi->status_verifikasi === 'rejected') {
                throw ValidationException::withMessages([
                    'bagian' => ['Absensi '.$this->labelBagian($absensi->status).' sedang menunggu perbaikan peserta.'],
                ]);
            }

            if ($absensi->revisi_diminta) {
                throw ValidationException::withMessages([
                    'bagian' => ['Masih ada permintaan revisi aktif pada absensi '.$this->labelBagian($absensi->status).'.'],
                ]);
            }
        }

        foreach ($target as $absensi) {
            $riwayat = $absensi->riwayat_revisi ?? [];

            if (! empty($riwayat)) {
                $indexTerakhir = array_key_last($riwayat);

                if (($riwayat[$indexTerakhir]['status'] ?? null) === 'menunggu_verifikasi') {
                    $riwayat[$indexTerakhir]['status'] = 'revisi_diulang';
                    $riwayat[$indexTerakhir]['diulang_pada'] = $waktu->toISOString();
                }
            }

            $riwayat[] = [
                'bagian' => $absensi->status,
                'catatan' => $catatan,
                'pembimbing_id' => $pembimbing->id,
                'pembimbing_nama' => $namaPembimbing ?? $pembimbing->pengguna?->nama_lengkap,
                'pembimbing_pengguna_id' => $pembimbing->id_pengguna,
                'diminta_pada' => $waktu->toISOString(),
                'dikirim_pada' => null,
                'diperbaiki_pada' => null,
                'diputuskan_pada' => null,
                'diputuskan_oleh' => null,
                'diputuskan_oleh_nama' => null,
                'status' => 'menunggu_peserta',
                'data_sebelum' => $this->snapshot($absensi),
                'data_setelah' => null,
            ];

            $absensi->update([
                'catatan_pembimbing' => $catatan,
                'revisi_diminta' => true,
                'revisi_diminta_pada' => $waktu,
                'revisi_dikirim_pada' => null,
                'diverifikasi_oleh' => $pembimbing->id,
                'diverifikasi_pada' => $waktu,
                'riwayat_revisi' => $riwayat,
            ]);
        }

        return $target->map->fresh();
    }

    public function catatPerbaikanPeserta(Absensi $absensi, array $dataSetelah): void
    {
        if (! $absensi->menungguRevisi()) {
            throw ValidationException::withMessages([
                'absensi' => ['Tidak ada permintaan revisi aktif untuk absensi ini.'],
            ]);
        }

        $riwayat = $absensi->riwayat_revisi ?? [];
        $index = $this->indexTerakhirDenganStatus($riwayat, ['menunggu_peserta', 'siap_dikirim']);

        if ($index === null) {
            throw ValidationException::withMessages([
                'absensi' => ['Permintaan revisi tidak ditemukan pada riwayat absensi.'],
            ]);
        }

        $waktu = Carbon::now();
        $riwayat[$index]['status'] = 'siap_dikirim';
        $riwayat[$index]['diperbaiki_pada'] = $waktu->toISOString();
        $riwayat[$index]['data_setelah'] = array_merge(
            $riwayat[$index]['data_setelah'] ?? [],
            $dataSetelah,
            ['diperbaiki_pada' => $waktu->toISOString()]
        );

        $absensi->forceFill(['riwayat_revisi' => $riwayat])->save();
    }

    /**
     * @return Collection<int, Absensi>
     */
    public function kirimRevisi(Absensi $anchor): Collection
    {
        $pasangan = $this->pasangan($anchor, true);
        $aktif = collect($pasangan)
            ->filter(fn (?Absensi $absensi) => $absensi
                && $absensi->status_verifikasi === 'pending'
                && $absensi->revisi_diminta);

        if ($aktif->isEmpty()) {
            throw ValidationException::withMessages([
                'absensi' => ['Tidak ada permintaan revisi aktif yang dapat dikirim.'],
            ]);
        }

        foreach ($aktif as $absensi) {
            $riwayat = $absensi->riwayat_revisi ?? [];
            $index = $this->indexTerakhirDenganStatus($riwayat, ['siap_dikirim']);

            if ($index === null) {
                throw ValidationException::withMessages([
                    'absensi' => ['Perbaikan '.$this->labelBagian($absensi->status).' belum disimpan. Silakan lakukan check-in/check-out ulang terlebih dahulu.'],
                ]);
            }
        }

        $waktu = Carbon::now();

        foreach ($aktif as $absensi) {
            $riwayat = $absensi->riwayat_revisi ?? [];
            $index = $this->indexTerakhirDenganStatus($riwayat, ['siap_dikirim']);

            $riwayat[$index]['status'] = 'menunggu_verifikasi';
            $riwayat[$index]['dikirim_pada'] = $waktu->toISOString();

            $absensi->update([
                'revisi_diminta' => false,
                'revisi_dikirim_pada' => $waktu,
                'riwayat_revisi' => $riwayat,
            ]);
        }

        return $aktif->map->fresh();
    }

    public function tandaiDisetujui(Absensi $absensi, ?int $pembimbingId, ?string $namaPembimbing): bool
    {
        $riwayat = $absensi->riwayat_revisi ?? [];
        $index = $this->indexTerakhirDenganStatus($riwayat, ['menunggu_verifikasi']);

        if ($index === null) {
            return false;
        }

        $riwayat[$index]['status'] = 'disetujui';
        $riwayat[$index]['diputuskan_pada'] = Carbon::now()->toISOString();
        $riwayat[$index]['diputuskan_oleh'] = $pembimbingId;
        $riwayat[$index]['diputuskan_oleh_nama'] = $namaPembimbing;
        $riwayat[$index]['alasan_penolakan'] = null;

        $absensi->forceFill(['riwayat_revisi' => $riwayat])->save();

        return true;
    }

    public function tandaiDitolak(Absensi $absensi, ?int $pembimbingId, ?string $namaPembimbing, ?string $alasan): bool
    {
        $riwayat = $absensi->riwayat_revisi ?? [];
        $index = $this->indexTerakhirDenganStatus($riwayat, ['menunggu_peserta', 'siap_dikirim', 'menunggu_verifikasi']);

        if ($index === null) {
            return false;
        }

        $riwayat[$index]['status'] = 'ditolak';
        $riwayat[$index]['diputuskan_pada'] = Carbon::now()->toISOString();
        $riwayat[$index]['diputuskan_oleh'] = $pembimbingId;
        $riwayat[$index]['diputuskan_oleh_nama'] = $namaPembimbing;
        $riwayat[$index]['alasan_penolakan'] = $alasan;

        $absensi->forceFill(['riwayat_revisi' => $riwayat])->save();

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function riwayatGabungan(Absensi $absensi): array
    {
        $pasangan = $this->pasangan($absensi);
        $riwayat = [];

        foreach ([self::BAGIAN_CHECK_IN, self::BAGIAN_CHECK_OUT] as $bagian) {
            $row = $pasangan[$bagian];

            if (! $row) {
                continue;
            }

            foreach (($row->riwayat_revisi ?? []) as $index => $entri) {
                $riwayat[] = array_merge($entri, [
                    'id_absensi' => $row->id,
                    'index_riwayat' => $index,
                    'bagian' => $entri['bagian'] ?? $bagian,
                    'tanggal_jam_absensi' => $row->tanggal_jam?->toISOString(),
                    'status_absensi' => $row->status_verifikasi,
                    'status_revisi_terkini' => $row->statusRevisi(),
                ]);
            }
        }

        usort($riwayat, fn (array $a, array $b) => strcmp(
            (string) ($a['diminta_pada'] ?? ''),
            (string) ($b['diminta_pada'] ?? '')
        ));

        return $riwayat;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function bagianAktif(Absensi $absensi): array
    {
        $pasangan = $this->pasangan($absensi);

        return collect($pasangan)
            ->filter(fn (?Absensi $row) => $row && $row->revisi_diminta)
            ->map(function (Absensi $row): array {
                $terakhir = collect($row->riwayat_revisi ?? [])->last();

                return [
                    'id_absensi' => $row->id,
                    'bagian' => $row->status,
                    'status' => $terakhir['status'] ?? 'menunggu_peserta',
                    'diminta_pada' => $terakhir['diminta_pada'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Serialisasi riwayat untuk response tanpa mengubah data tersimpan.
     * Snapshot Base64 diganti URL file bertanda tangan; daftar dapat memilih
     * untuk tidak menyertakan snapshot sama sekali.
     *
     * @param  array<int, array<string, mixed>>  $riwayat
     * @return array<int, array<string, mixed>>
     */
    public function serialisasiRiwayat(
        array $riwayat,
        ?int $absensiId = null,
        bool $denganSnapshot = true
    ): array {
        $media = app(AbsensiMediaService::class);
        $hasil = [];

        foreach (array_values($riwayat) as $index => $entri) {
            if (! is_array($entri)) {
                continue;
            }

            $idAbsensi = (int) ($entri['id_absensi'] ?? $absensiId ?? 0);
            $indexRiwayat = (int) ($entri['index_riwayat'] ?? $index);

            foreach (['sebelum' => 'data_sebelum', 'setelah' => 'data_setelah'] as $posisi => $key) {
                if (! $denganSnapshot) {
                    unset($entri[$key]);

                    continue;
                }

                if (! is_array($entri[$key] ?? null)) {
                    continue;
                }

                foreach (['foto_selfie', 'paraf_pembimbing'] as $field) {
                    $path = $entri[$key][$field] ?? null;
                    $url = $media->snapshotUrl(
                        is_string($path) ? $path : null,
                        $idAbsensi,
                        $indexRiwayat,
                        $posisi,
                        $field
                    );

                    if ($url === null) {
                        unset($entri[$key][$field]);

                        continue;
                    }

                    $entri[$key][$field] = $url;
                    $entri[$key][$field.'_url'] = $url;
                }
            }

            $hasil[] = $entri;
        }

        return $hasil;
    }

    public function labelBagian(string $bagian): string
    {
        return match ($bagian) {
            'masuk', self::BAGIAN_CHECK_IN => 'Check-in',
            'keluar', self::BAGIAN_CHECK_OUT => 'Check-out',
            self::BAGIAN_BOTH => 'Check-in dan Check-out',
            default => ucfirst($bagian),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Absensi $absensi): array
    {
        return [
            'tanggal_jam' => $absensi->tanggal_jam?->toISOString(),
            'foto_selfie' => $absensi->foto_selfie,
            'lokasi' => $absensi->lokasi,
            'latitude' => $absensi->latitude,
            'longitude' => $absensi->longitude,
            'status' => $absensi->status,
            'keterangan' => $absensi->keterangan,
            'status_verifikasi' => $absensi->status_verifikasi,
            'paraf_pembimbing' => $absensi->paraf_pembimbing,
            'diverifikasi_oleh' => $absensi->diverifikasi_oleh,
            'diverifikasi_pada' => $absensi->diverifikasi_pada?->toISOString(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $riwayat
     * @param  array<int, string>  $status
     */
    private function indexTerakhirDenganStatus(array $riwayat, array $status): ?int
    {
        for ($i = count($riwayat) - 1; $i >= 0; $i--) {
            if (in_array($riwayat[$i]['status'] ?? null, $status, true)) {
                return $i;
            }
        }

        return null;
    }
}
