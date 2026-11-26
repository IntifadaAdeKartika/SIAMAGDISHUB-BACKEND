<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Absensi extends Model
{
    use HasFactory;

    protected $table = 'absensi';

    protected $fillable = [
        'id_peserta_magang',
        'id_unit_kerja',
        'tanggal_jam',
        'foto_selfie',
        'lokasi',
        'latitude',
        'longitude',
        'status',
        'keterangan',
        'paraf_pembimbing',
        'diverifikasi_oleh',
        'diverifikasi_pada',
        'status_verifikasi',
        'alasan_penolakan',
        'jumlah_resubmit',
        'riwayat_penolakan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_jam' => 'datetime',
            'diverifikasi_pada' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'riwayat_penolakan' => 'array',
            'jumlah_resubmit' => 'integer',
        ];
    }

    public function isApproved(): bool
    {
        return $this->status_verifikasi === 'approved';
    }

    public function pesertaMagang(): BelongsTo
    {
        return $this->belongsTo(PesertaMagang::class, 'id_peserta_magang');
    }

    public function unitKerja(): BelongsTo
    {
        return $this->belongsTo(UnitKerja::class, 'id_unit_kerja');
    }

    public function verifikator(): BelongsTo
    {
        return $this->belongsTo(Pembimbing::class, 'diverifikasi_oleh');
    }

    public function logbooks(): HasMany
    {
        return $this->hasMany(Logbook::class, 'id_absensi');
    }
}
