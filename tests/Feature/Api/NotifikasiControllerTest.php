<?php

namespace Tests\Feature\Api;

use App\Models\Notifikasi;
use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotifikasiControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_list_only_their_own_notifications_with_required_fields(): void
    {
        $user = $this->createUser('notifications-user');
        $otherUser = $this->createUser('notifications-other-user');
        $baseline = now();

        $this->travelTo($baseline);
        $olderNotification = $this->createNotification($user, [
            'judul' => 'Notifikasi lama',
        ]);
        $this->travelTo($baseline->copy()->addMinute());
        $newerNotification = $this->createNotification($user, [
            'judul' => 'Notifikasi baru',
        ]);
        $this->createNotification($otherUser);

        $this->actingAs($user, 'sanctum')
            ->getJson('/backend-api/notifikasi')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newerNotification->id)
            ->assertJsonPath('data.0.judul', 'Notifikasi baru')
            ->assertJsonPath('data.0.pesan', 'Pesan notifikasi')
            ->assertJsonPath('data.0.tipe', 'info')
            ->assertJsonPath('data.0.link', '/riwayat')
            ->assertJsonPath('data.0.sudah_dibaca', false)
            ->assertJsonPath('data.0.dibuat_pada', $newerNotification->created_at->toISOString())
            ->assertJsonPath('data.1.id', $olderNotification->id);
    }

    public function test_user_can_mark_their_notification_as_read_but_not_another_users_notification(): void
    {
        $user = $this->createUser('notification-reader');
        $otherUser = $this->createUser('notification-owner');
        $userNotification = $this->createNotification($user);
        $otherNotification = $this->createNotification($otherUser);

        $this->actingAs($user, 'sanctum')
            ->putJson("/backend-api/notifikasi/{$userNotification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $userNotification->id)
            ->assertJsonPath('data.sudah_dibaca', true);

        $this->assertDatabaseHas('notifikasi', [
            'id' => $userNotification->id,
            'sudah_dibaca' => true,
        ]);

        $this->putJson("/backend-api/notifikasi/{$otherNotification->id}/read")
            ->assertNotFound();

        $this->assertDatabaseHas('notifikasi', [
            'id' => $otherNotification->id,
            'sudah_dibaca' => false,
        ]);
    }

    public function test_user_can_mark_all_and_only_their_notifications_as_read(): void
    {
        $user = $this->createUser('bulk-notification-reader');
        $otherUser = $this->createUser('bulk-notification-owner');
        $userNotifications = [
            $this->createNotification($user),
            $this->createNotification($user, ['judul' => 'Notifikasi kedua']),
        ];
        $otherNotification = $this->createNotification($otherUser);

        $this->actingAs($user, 'sanctum')
            ->putJson('/backend-api/notifikasi/read-all')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 2);

        foreach ($userNotifications as $notification) {
            $this->assertDatabaseHas('notifikasi', [
                'id' => $notification->id,
                'sudah_dibaca' => true,
            ]);
        }

        $this->assertDatabaseHas('notifikasi', [
            'id' => $otherNotification->id,
            'sudah_dibaca' => false,
        ]);
    }

    public function test_notification_endpoints_require_authentication(): void
    {
        $this->getJson('/backend-api/notifikasi')->assertUnauthorized();
        $this->putJson('/backend-api/notifikasi/1/read')->assertUnauthorized();
        $this->putJson('/backend-api/notifikasi/read-all')->assertUnauthorized();
    }

    private function createUser(string $username): Pengguna
    {
        return Pengguna::create([
            'username' => $username,
            'nama_lengkap' => $username,
            'password' => 'password',
            'role' => 'peserta_magang',
            'status' => 'aktif',
        ]);
    }

    private function createNotification(Pengguna $user, array $attributes = []): Notifikasi
    {
        return Notifikasi::create(array_merge([
            'id_pengguna' => $user->id,
            'judul' => 'Notifikasi',
            'pesan' => 'Pesan notifikasi',
            'tipe' => 'info',
            'link' => '/riwayat',
            'sudah_dibaca' => false,
        ], $attributes));
    }
}
