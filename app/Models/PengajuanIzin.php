<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PengajuanIzin extends Model
{
    use HasFactory;

    protected $table = 'pengajuan_izin';

    protected $fillable = [
        'id_peserta_magang',
        'jenis',
        'tanggal_mulai',
        'tanggal_selesai',
        'keterangan',
        'file_surat',
        'status',
        'diverifikasi_oleh',
        'diverifikasi_pada',
        'catatan_pembimbing',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'diverifikasi_pada' => 'datetime',
        ];
    }

    public function pesertaMagang(): BelongsTo
    {
        return $this->belongsTo(PesertaMagang::class, 'id_peserta_magang');
    }

    public function verifikator(): BelongsTo
    {
        return $this->belongsTo(Pembimbing::class, 'diverifikasi_oleh');
    }
}
