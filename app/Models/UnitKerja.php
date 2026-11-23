<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    /**
     * Pembimbing berdasarkan kolom pembimbing.id_unit_kerja.
     *
     * Dipakai sebagai cadangan ketika unit_kerja.id_pembimbing belum tersinkron
     * (misalnya pembimbing ditetapkan lewat menu Pembimbing, bukan menu Unit Kerja).
     */
    public function pembimbingByUnit(): HasOne
    {
        return $this->hasOne(Pembimbing::class, 'id_unit_kerja')->oldestOfMany('id');
    }

    /**
     * Pembimbing efektif untuk unit ini.
     *
     * Prioritas: relasi utama unit_kerja.id_pembimbing, lalu pembimbing.id_unit_kerja.
     */
    public function pembimbingEfektif(): ?Pembimbing
    {
        // 1. Jika relasi pembimbing utama sudah dimuat, gunakan jika ada; jika kosong, fallback ke cadangan
        if ($this->relationLoaded('pembimbing')) {
            return $this->pembimbing ?? $this->pembimbingByUnit;
        }

        // 2. Jika relasi pembimbing utama belum dimuat tetapi unit memiliki pembimbing utama (id_pembimbing terisi),
        // pembimbing utama tetap wajib diprioritaskan meskipun pembimbingByUnit sudah dimuat
        if (! empty($this->id_pembimbing)) {
            return $this->pembimbing ?? $this->pembimbingByUnit;
        }

        // 3. Unit tidak memiliki pembimbing utama (id_pembimbing null), langsung gunakan pembimbing cadangan
        return $this->pembimbingByUnit;
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
