<?php

namespace App\Services;

use App\Models\LogAktivitas;
use Illuminate\Support\Facades\Request;

class ActivityLogger
{
    public static function log(string $aksi, ?string $deskripsi = null, ?int $idPengguna = null): LogAktivitas
    {
        $userId = $idPengguna ?? auth('sanctum')->id();

        return LogAktivitas::create([
            'id_pengguna' => $userId,
            'aksi' => $aksi,
            'deskripsi' => $deskripsi,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }
}
