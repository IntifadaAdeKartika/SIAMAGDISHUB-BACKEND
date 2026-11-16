<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnitKerja extends Model
{
    use HasFactory;

    protected $table = 'unit_kerja';

    protected $fillable = [
        'id_induk',
        'id_pembimbing',
        'kode_unit',
        'nama_unit',
        'tipe',
        'status',
    ];

    public function induk(): BelongsTo
    {
        return $this->belongsTo(UnitKerja::class, 'id_induk');
    }

    public function subUnit(): HasMany
    {
        return $this->hasMany(UnitKerja::class, 'id_induk');
    }

    /**
     * Relasi ke Pembimbing Lapangan utama di Unit Kerja ini.
     */
    public function pembimbing(): BelongsTo
    {
        return $this->belongsTo(Pembimbing::class, 'id_pembimbing');
    }

    /**
     * Alias relasi ke Pembimbing Lapangan utama.
     */
    public function pembimbingUtama(): BelongsTo
    {
        return $this->belongsTo(Pembimbing::class, 'id_pembimbing');
    }

    public function pembimbingList(): HasMany
    {
        return $this->hasMany(Pembimbing::class, 'id_unit_kerja');
    }

    public function pesertaMagangList(): HasMany
    {
        return $this->hasMany(PesertaMagang::class, 'id_unit_kerja');
    }

    public function absensiList(): HasMany
    {
        return $this->hasMany(Absensi::class, 'id_unit_kerja');
    }
}
