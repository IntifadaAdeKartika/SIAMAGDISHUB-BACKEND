<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Pengguna;
use App\Models\PesertaMagang;
use App\Models\UnitKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminResourceOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_updated_unit_appears_first_in_admin_list(): void
    {
        $admin = $this->createAdmin();
        $baseline = now();

        $this->travelTo($baseline);
        $olderUnit = $this->createUnit('UNIT-OLD', 'Unit Old');
        $this->travelTo($baseline->copy()->addMinute());
        $this->createUnit('UNIT-NEW', 'Unit New');
        $this->travelTo($baseline->copy()->addMinutes(2));

        $this->actingAs($admin, 'sanctum')
            ->putJson('/backend-api/admin/unit-kerja/'.$olderUnit->id, [
                'nama_unit' => 'Unit Old Updated',
            ])
            ->assertOk();

        $this->getJson('/backend-api/admin/unit-kerja?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $olderUnit->id)
            ->assertJsonPath('data.data.0.nama_unit', 'Unit Old Updated');
    }

    public function test_updated_participant_appears_first_in_admin_list(): void
    {
        $admin = $this->createAdmin();
        $baseline = now();

        $this->travelTo($baseline);
        $olderParticipant = $this->createParticipant('participant-old', 'Participant Old');
        $this->travelTo($baseline->copy()->addMinute());
        $this->createParticipant('participant-new', 'Participant New');
        $this->travelTo($baseline->copy()->addMinutes(2));

        $this->actingAs($admin, 'sanctum')
            ->putJson('/backend-api/admin/peserta-magang/'.$olderParticipant->id, [
                'nama_lengkap' => 'Participant Old Updated',
            ])
            ->assertOk();

        $this->getJson('/backend-api/admin/peserta-magang?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $olderParticipant->id)
            ->assertJsonPath('data.data.0.pengguna.nama_lengkap', 'Participant Old Updated');
    }

    private function createAdmin(): Pengguna
    {
        return Pengguna::create([
            'username' => 'admin',
            'nama_lengkap' => 'Admin',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'aktif',
        ]);
    }

    private function createUnit(string $code, string $name): UnitKerja
    {
        return UnitKerja::create([
            'kode_unit' => $code,
            'nama_unit' => $name,
            'tipe' => 'unit',
            'status' => 'aktif',
        ]);
    }

    private function createParticipant(string $username, string $name): PesertaMagang
    {
        $pengguna = Pengguna::create([
            'username' => $username,
            'nama_lengkap' => $name,
            'password' => 'password',
            'role' => 'peserta_magang',
            'status' => 'aktif',
        ]);

        return PesertaMagang::create([
            'id_pengguna' => $pengguna->id,
            'jenis_peserta' => 'siswa',
            'prodi_jurusan' => 'Teknik Kendaraan',
            'asal_sekolah_kampus' => 'SMK Contoh',
            'kelas_semester' => 'XII',
            'status_peserta' => 'aktif',
            'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31',
            'status_dokumen' => 'pending',
        ]);
    }
}
