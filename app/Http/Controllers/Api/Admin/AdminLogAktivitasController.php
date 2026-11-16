<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LogAktivitasResource;
use App\Models\LogAktivitas;
use Illuminate\Http\JsonResponse;

class AdminLogAktivitasController extends Controller
{
    public function index(): JsonResponse
    {
        $logAktivitas = LogAktivitas::with('pengguna')
            ->latest()
            ->paginate(15)
            ->through(fn (LogAktivitas $log): array => (new LogAktivitasResource($log))->resolve());

        return response()->json([
            'success' => true,
            'data' => $logAktivitas,
        ]);
    }
}
