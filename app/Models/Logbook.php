<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Logbook extends Model
{
    use HasFactory;

    protected $table = 'logbook';

    protected $fillable = [
        'id_peserta_magang',
        'id_absensi',
        'tanggal',
        'jam_mulai',
        'jam_selesai',
        'kegiatan',
        'foto_kegiatan',
        'status_verifikasi',
        'catatan_pembimbing',
        'paraf_pembimbing',
        'diverifikasi_oleh',
        'diverifikasi_pada',
        'is_draft',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'diverifikasi_pada' => 'datetime',
            'is_draft' => 'boolean',
        ];
    }

    public function isPending(): bool
    {
        return $this->status_verifikasi === 'pending';
    }

    public function pesertaMagang(): BelongsTo
    {
        return $this->belongsTo(PesertaMagang::class, 'id_peserta_magang');
    }

    public function absensi(): BelongsTo
    {
        return $this->belongsTo(Absensi::class, 'id_absensi');
    }

    public function verifikator(): BelongsTo
    {
        return $this->belongsTo(Pembimbing::class, 'diverifikasi_oleh');
    }
}
