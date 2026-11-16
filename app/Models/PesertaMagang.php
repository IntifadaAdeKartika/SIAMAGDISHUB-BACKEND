<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PesertaMagang extends Model
{
    use HasFactory;

    protected $table = 'peserta_magang';

    protected $fillable = [
        'id_pengguna',
        'id_unit_kerja',
        'jenis_peserta',
        'prodi_jurusan',
        'asal_sekolah_kampus',
        'kelas_semester',
        'pembimbing_akademik',
        'nip_pembimbing_akademik',
        'no_hp_pembimbing_akademik',
        'status_peserta',
        'tanggal_mulai',
        'tanggal_selesai',
        'file_surat_permohonan',
        'file_surat_balasan',
        'status_dokumen',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
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

    /**
     * Alias relasi unit kerja (snake_case)
     */
    public function unit_kerja(): BelongsTo
    {
        return $this->belongsTo(UnitKerja::class, 'id_unit_kerja');
    }

    public function absensiList(): HasMany
    {
        return $this->hasMany(Absensi::class, 'id_peserta_magang');
    }

    public function absensiTerbaru(): HasOne
    {
        return $this->hasOne(Absensi::class, 'id_peserta_magang')->latestOfMany('tanggal_jam');
    }

    public function absensiHariIni(): HasOne
    {
        return $this->hasOne(Absensi::class, 'id_peserta_magang')
            ->ofMany(
                ['tanggal_jam' => 'max', 'id' => 'max'],
                fn (Builder $query) => $query->whereDate('tanggal_jam', now()->toDateString()),
            );
    }

    public function logbookList(): HasMany
    {
        return $this->hasMany(Logbook::class, 'id_peserta_magang');
    }

    public function logbookTerakhir(): HasOne
    {
        return $this->hasOne(Logbook::class, 'id_peserta_magang')->latestOfMany('tanggal');
    }

    public function pengajuanIzinList(): HasMany
    {
        return $this->hasMany(PengajuanIzin::class, 'id_peserta_magang');
    }
}
