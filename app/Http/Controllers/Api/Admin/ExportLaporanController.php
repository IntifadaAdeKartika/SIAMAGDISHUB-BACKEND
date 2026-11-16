<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportLaporanController extends Controller
{
    /**
     * Export rekap absensi ke format PDF.
     */
    public function exportPdf(Request $request): Response
    {
        $query = Absensi::with(['pesertaMagang.pengguna', 'unitKerja', 'verifikator.pengguna']);

        if ($request->filled('id_unit_kerja')) {
            $query->where('id_unit_kerja', $request->id_unit_kerja);
        }

        if ($request->filled('tanggal_mulai') && $request->filled('tanggal_selesai')) {
            $query->whereBetween('tanggal_jam', [$request->tanggal_mulai.' 00:00:00', $request->tanggal_selesai.' 23:59:59']);
        }

        $absensi = $query->orderBy('tanggal_jam', 'desc')->get();

        $pdf = Pdf::loadView('exports.laporan_absensi_pdf', [
            'absensi' => $absensi,
            'tanggal_mulai' => $request->tanggal_mulai ?? '-',
            'tanggal_selesai' => $request->tanggal_selesai ?? '-',
            'generated_at' => now()->translatedFormat('d F Y H:i'),
        ]);

        return $pdf->download('Rekap_Absensi_SIAMAGDISHUB_'.now()->format('Ymd_His').'.pdf');
    }

    /**
     * Export rekap absensi ke format CSV/Excel.
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        $query = Absensi::with(['pesertaMagang.pengguna', 'unitKerja', 'verifikator.pengguna']);

        if ($request->filled('id_unit_kerja')) {
            $query->where('id_unit_kerja', $request->id_unit_kerja);
        }

        if ($request->filled('tanggal_mulai') && $request->filled('tanggal_selesai')) {
            $query->whereBetween('tanggal_jam', [$request->tanggal_mulai.' 00:00:00', $request->tanggal_selesai.' 23:59:59']);
        }

        $absensi = $query->orderBy('tanggal_jam', 'desc')->get();

        $filename = 'Rekap_Absensi_SIAMAGDISHUB_'.now()->format('Ymd_His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($absensi) {
            $file = fopen('php://output', 'w');
            fputcsv($file, [
                'ID',
                'Nama Peserta',
                'Asal Kampus / Sekolah',
                'Unit Kerja',
                'Waktu Absensi',
                'Status',
                'Latitude',
                'Longitude',
                'Lokasi',
                'Status Verifikasi',
                'Diverifikasi Oleh',
                'Diverifikasi Pada',
            ]);

            foreach ($absensi as $row) {
                fputcsv($file, [
                    $row->id,
                    $row->pesertaMagang->pengguna->nama_lengkap ?? '-',
                    $row->pesertaMagang->asal_sekolah_kampus ?? '-',
                    $row->unitKerja->nama_unit ?? '-',
                    $row->tanggal_jam?->format('Y-m-d H:i:s'),
                    $row->status,
                    $row->latitude,
                    $row->longitude,
                    $row->lokasi ?? '-',
                    $row->status_verifikasi,
                    $row->verifikator->pengguna->nama_lengkap ?? '-',
                    $row->diverifikasi_pada?->format('Y-m-d H:i:s') ?? '-',
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
