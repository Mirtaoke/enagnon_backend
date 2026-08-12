<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_can_login_check_in_check_out_and_logout(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $seller = User::factory()->create([
            'username' => 'vendeur-test',
            'role' => 'seller',
            'password' => 'password',
        ]);
        $shop = Shop::create([
            'name' => 'Point test',
            'owner_id' => $admin->id,
        ]);
        Employee::create([
            'user_id' => $seller->id,
            'shop_id' => $shop->id,
            'name' => $seller->name,
            'email' => $seller->email,
        ]);

        $login = $this->postJson('/api/auth/login', [
            'login' => 'vendeur-test',
            'password' => 'password',
        ])->assertOk()->assertJsonPath('user.role', 'seller');

        $headers = ['Authorization' => 'Bearer '.$login->json('token')];
        $this->withHeaders($headers)->getJson('/api/attendance/today')
            ->assertOk()->assertJsonPath('attendance.arrival_at', null);
        $this->withHeaders($headers)->postJson('/api/attendance/check-in')
            ->assertOk()->assertJsonPath('attendance.departure_at', null);
        $this->withHeaders($headers)->postJson('/api/attendance/check-in')
            ->assertStatus(422);
        $this->withHeaders($headers)->postJson('/api/attendance/check-out')
            ->assertOk();
        $this->withHeaders($headers)->postJson('/api/auth/logout')
            ->assertNoContent();
        $this->withHeaders($headers)->getJson('/api/auth/me')
            ->assertUnauthorized();
    }
}
