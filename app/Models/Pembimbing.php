<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Pembimbing extends Model
{
    use HasFactory;

    protected $table = 'pembimbing';

    protected $fillable = [
        'id_pengguna',
        'id_unit_kerja',
        'nip',
        'jabatan',
        'status_pembimbing',
        'tanggal_mulai_tugas',
        'tanggal_selesai_tugas',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_mulai_tugas' => 'date',
            'tanggal_selesai_tugas' => 'date',
        ];
    }

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(Pengguna::class, 'id_pengguna');
    }

    public function unitKerja(): BelongsTo
    {
        return $this->belongsTo(UnitKerja::class, 'id_unit_kerja');
    }

    public function unitKerjaUtama(): HasOne
    {
        return $this->hasOne(UnitKerja::class, 'id_pembimbing');
    }

    public function verifikasiAbsensiList(): HasMany
    {
        return $this->hasMany(Absensi::class, 'diverifikasi_oleh');
    }

    public function verifikasiLogbookList(): HasMany
    {
        return $this->hasMany(Logbook::class, 'diverifikasi_oleh');
    }

    public function verifikasiIzinList(): HasMany
    {
        return $this->hasMany(PengajuanIzin::class, 'diverifikasi_oleh');
    }

    public function pesertaBimbingan(): HasMany
    {
        return $this->hasMany(PesertaMagang::class, 'id_unit_kerja', 'id_unit_kerja');
    }
}
