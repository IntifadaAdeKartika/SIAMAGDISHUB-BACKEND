<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\LogbookResource;
use App\Models\Logbook;
use Illuminate\Http\JsonResponse;

class AdminLogbookController extends Controller
{
    public function index(): JsonResponse
    {
        $logbooks = Logbook::with([
            'pesertaMagang.pengguna',
            'pesertaMagang.unitKerja.pembimbing.pengguna',
            'verifikator.pengguna',
        ])
            ->latest('tanggal')
            ->paginate(15)
            ->through(fn (Logbook $logbook): array => (new LogbookResource($logbook))->resolve());

        return response()->json([
            'success' => true,
            'data' => $logbooks,
        ]);
    }
}
