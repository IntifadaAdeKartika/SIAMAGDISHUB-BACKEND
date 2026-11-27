<?php

namespace App\Services;

use Illuminate\Support\Facades\URL;

class AbsensiMediaService
{
    private const TTL_MINUTES = 60;

    /**
     * URL publik untuk media absensi. Data URI tidak pernah dikembalikan mentah.
     */
    public function url(?string $path, int $absensiId, string $jenis = 'selfie'): ?string
    {
        if (! $this->terisi($path)) {
            return null;
        }

        if ($this->urlEksternal($path)) {
            return $path;
        }

        if (str_starts_with((string) $path, 'data:')) {
            return URL::temporarySignedRoute(
                $jenis === 'paraf' ? 'absensi.media.paraf' : 'absensi.media.selfie',
                now()->addMinutes(self::TTL_MINUTES),
                ['absensi' => $absensiId]
            );
        }

        return app(FileUploadService::class)->url($path);
    }

    /**
     * URL untuk foto/paraf yang tersimpan di snapshot riwayat revisi.
     */
    public function snapshotUrl(
        ?string $path,
        int $absensiId,
        int $index,
        string $posisi,
        string $field
    ): ?string {
        if (! $this->terisi($path)) {
            return null;
        }

        if ($this->urlEksternal($path)) {
            return $path;
        }

        return URL::temporarySignedRoute(
            'absensi.media.revisi',
            now()->addMinutes(self::TTL_MINUTES),
            [
                'absensi' => $absensiId,
                'index' => $index,
                'posisi' => $posisi,
                'field' => $field,
            ]
        );
    }

    private function terisi(?string $path): bool
    {
        return $path !== null && trim($path) !== '';
    }

    private function urlEksternal(?string $path): bool
    {
        return is_string($path) && preg_match('#^https?://#i', $path) === 1;
    }
}
