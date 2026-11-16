<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rekap Absensi SIAMAGDISHUB</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #333; }
        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .header h2 { margin: 0; padding: 0; text-transform: uppercase; }
        .header p { margin: 4px 0 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #999; padding: 6px; text-align: left; }
        th { background-color: #f2f2f2; text-align: center; }
        .text-center { text-align: center; }
        .badge { font-weight: bold; }
        .footer { margin-top: 20px; text-align: right; font-size: 10px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Dinas Perhubungan - Sistem Absensi Magang (SIAMAGDISHUB)</h2>
        <p>Laporan Rekapitulasi Presensi Peserta Magang</p>
        <p>Periode: {{ $tanggal_mulai }} s/d {{ $tanggal_selesai }}</p>
    </div>

    <table>
        <thead>
            <tr>
                <th width="4%">No</th>
                <th width="20%">Nama Peserta</th>
                <th width="18%">Asal Kampus / Sekolah</th>
                <th width="16%">Unit Kerja</th>
                <th width="14%">Waktu</th>
                <th width="10%">Status</th>
                <th width="18%">Verifikasi</th>
            </tr>
        </thead>
        <tbody>
            @forelse($absensi as $index => $row)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td>{{ $row->pesertaMagang->pengguna->nama_lengkap ?? '-' }}</td>
                <td>{{ $row->pesertaMagang->asal_sekolah_kampus ?? '-' }}</td>
                <td>{{ $row->unitKerja->nama_unit ?? '-' }}</td>
                <td class="text-center">{{ $row->tanggal_jam?->format('d/m/Y H:i') }}</td>
                <td class="text-center">{{ strtoupper($row->status) }}</td>
                <td class="text-center">{{ strtoupper($row->status_verifikasi) }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="text-center">Tidak ada data absensi untuk periode ini.</td>
            </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        Dicetak otomatis pada: {{ $generated_at }}
    </div>
</body>
</html>
