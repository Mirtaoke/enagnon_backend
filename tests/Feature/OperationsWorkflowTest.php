<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Report;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class OperationsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_feed_report_and_admin_can_reopen_it(): void
    {
        [$admin, $seller, $shop] = $this->accounts();
        $sellerHeaders = ['Authorization' => 'Bearer '.$seller->api_token];
        $adminHeaders = ['Authorization' => 'Bearer '.$admin->api_token];
        $date = today()->toDateString();

        $entry = [
            'client_uuid' => (string) Str::uuid(), 'service' => 'momo', 'direction' => 'in',
            'type' => 'deposit', 'amount' => 100, 'phone' => '0197000000',
            'description' => 'Dépôt client', 'occurred_at' => now()->toIso8601String(),
        ];
        $this->withHeaders($sellerHeaders)->postJson("/api/shops/{$shop->id}/operations", $entry)->assertCreated();
        $this->withHeaders($sellerHeaders)->postJson("/api/shops/{$shop->id}/operations", $entry)->assertOk()->assertJsonPath('duplicate', true);
        $this->withHeaders($sellerHeaders)->postJson("/api/shops/{$shop->id}/operations", [
            'client_uuid' => (string) Str::uuid(), 'service' => 'other', 'direction' => 'out',
            'type' => 'expense', 'amount' => 40, 'description' => 'Transport',
            'occurred_at' => now()->toIso8601String(),
        ])->assertCreated();
        $this->withHeaders($sellerHeaders)->postJson("/api/shops/{$shop->id}/operations", [
            'client_uuid' => (string) Str::uuid(), 'service' => 'other', 'direction' => 'in',
            'type' => 'debt_repayment', 'amount' => 25, 'description' => 'Règlement du client',
            'occurred_at' => now()->toIso8601String(),
        ])->assertCreated();
        $this->withHeaders($adminHeaders)->postJson("/api/shops/{$shop->id}/operations", $entry)->assertForbidden();

        $this->withHeaders($sellerHeaders)->getJson("/api/shops/{$shop->id}/operations-summary?date={$date}")
            ->assertOk()->assertJsonPath('total_in', 125)->assertJsonPath('total_out', 40)->assertJsonPath('balance', 85);

        $this->withHeaders($sellerHeaders)->postJson("/api/shops/{$shop->id}/closures", [
            'date' => $date,
        ])->assertCreated()->assertJsonPath('published', true);
        $report = Report::where('shop_id', $shop->id)->firstOrFail();
        $this->assertSame(85.0, $report->cash_balance);

        $this->withHeaders($adminHeaders)->getJson('/api/summary?month='.today()->format('Y-m'))
            ->assertOk()->assertJsonStructure(['summary' => ['report_count'], 'sales_chart' => [['date', 'entries', 'outputs', 'difference']]]);
        foreach (['pdf' => 'application/pdf', 'xls' => 'application/vnd.ms-excel; charset=UTF-8'] as $format => $type) {
            $response = $this->withHeaders($adminHeaders)->get("/api/shops/{$shop->id}/reports-export?format={$format}")
                ->assertOk()->assertHeader('Content-Type', $type);
            if ($format === 'pdf') {
                $this->assertStringStartsWith('%PDF-', $response->getContent());
                $this->assertGreaterThan(1000, strlen($response->getContent()));
            } else {
                $response->assertSee('Synthese-', false)->assertSee('Services-', false)->assertSee('Operations-', false);
            }
        }

        $this->withHeaders($sellerHeaders)->deleteJson("/api/shops/{$shop->id}/reports/{$report->id}")->assertForbidden();
        $this->withHeaders($adminHeaders)->deleteJson("/api/shops/{$shop->id}/reports/{$report->id}")->assertNoContent();
        $this->assertDatabaseHas('daily_closures', ['shop_id' => $shop->id, 'status' => 'open']);
    }

    public function test_password_can_be_reset_with_a_valid_code(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.test', 'password' => 'ancien']);
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk()->assertJsonStructure(['message']);
        $this->assertDatabaseHas('password_reset_codes', ['email' => $user->email]);
        DB::table('password_reset_codes')->where('email', $user->email)->delete();
        DB::table('password_reset_codes')->insert([
            'email' => $user->email, 'code' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email, 'code' => '123456', 'password' => 'nouveau123',
            'password_confirmation' => 'nouveau123',
        ])->assertOk();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'nouveau123'])->assertOk();
    }

    public function test_virtual_balance_and_edit_authorization_workflow(): void
    {
        [$admin, $seller, $shop] = $this->accounts();
        $shop->update(['momo_initial_balance' => 100000, 'momo_virtual_balance' => 100000]);
        $sellerHeaders = ['Authorization' => 'Bearer '.$seller->api_token];
        $adminHeaders = ['Authorization' => 'Bearer '.$admin->api_token];
        $this->withHeaders($adminHeaders)->postJson("/api/shops/{$shop->id}/cash-adjustments", ['direction' => 'in', 'amount' => 200000, 'description' => 'Caisse initiale'])->assertCreated();

        $purchase = $this->withHeaders($sellerHeaders)->postJson("/api/shops/{$shop->id}/operations", [
            'client_uuid' => (string) Str::uuid(), 'service' => 'other', 'network' => 'momo',
            'direction' => 'out', 'type' => 'virtual_credit_purchase', 'amount' => 50000,
            'occurred_at' => now()->subMinute()->toIso8601String(),
        ])->assertCreated()->json('operation');
        $deposit = $this->withHeaders($sellerHeaders)->postJson("/api/shops/{$shop->id}/operations", [
            'client_uuid' => (string) Str::uuid(), 'service' => 'momo', 'direction' => 'in',
            'type' => 'deposit', 'amount' => 20000, 'phone' => '0197000000',
            'description' => 'Dépôt', 'occurred_at' => now()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('operation.virtual_balance_after', 130000)->json('operation');

        $this->withHeaders($sellerHeaders)->putJson("/api/shops/{$shop->id}/operations/{$deposit['id']}", [...$deposit, 'amount' => 10000])->assertForbidden();
        $requestId = $this->withHeaders($sellerHeaders)->postJson("/api/shops/{$shop->id}/operations/{$deposit['id']}/edit-request", ['reason' => 'Montant saisi incorrect'])->assertCreated()->json('request.id');
        $this->withHeaders($adminHeaders)->putJson("/api/operation-edit-requests/{$requestId}", ['decision' => 'approved'])->assertOk();
        $this->withHeaders($sellerHeaders)->putJson("/api/shops/{$shop->id}/operations/{$deposit['id']}", [
            'service' => 'momo', 'direction' => 'in', 'type' => 'deposit', 'amount' => 10000,
            'phone' => '0197000000', 'description' => 'Dépôt corrigé', 'occurred_at' => now()->toIso8601String(),
        ])->assertOk()->assertJsonPath('operation.virtual_balance_after', 140000);
        $this->assertDatabaseHas('shops', ['id' => $shop->id, 'momo_virtual_balance' => 140000]);
    }

    public function test_operations_are_rejected_when_cash_or_virtual_balance_is_insufficient(): void
    {
        [, $seller, $shop] = $this->accounts();
        $headers = ['Authorization' => 'Bearer '.$seller->api_token];
        $this->withHeaders($headers)->postJson("/api/shops/{$shop->id}/operations", [
            'client_uuid' => (string) Str::uuid(), 'service' => 'momo', 'direction' => 'in',
            'type' => 'deposit', 'amount' => 1000001, 'phone' => '0197000000',
            'description' => 'Dépôt impossible', 'occurred_at' => now()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonPath('message', 'Solde virtuel insuffisant pour ce réseau. Il reste 1 000 000 FCFA.');
        $this->withHeaders($headers)->postJson("/api/shops/{$shop->id}/operations", [
            'client_uuid' => (string) Str::uuid(), 'service' => 'momo', 'direction' => 'out',
            'type' => 'withdrawal', 'amount' => 1, 'phone' => '0197000000',
            'description' => 'Retrait impossible', 'occurred_at' => now()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonPath('message', 'Solde espèces insuffisant. La caisse contient actuellement 0 FCFA.');
    }

    private function accounts(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'api_token' => Str::random(60)]);
        $seller = User::factory()->create(['role' => 'seller', 'api_token' => Str::random(60)]);
        $shop = Shop::create([
            'name' => 'Point test', 'owner_id' => $admin->id,
            'moov_credit_initial_balance' => 1000000, 'moov_credit_virtual_balance' => 1000000,
            'flooz_initial_balance' => 1000000, 'flooz_virtual_balance' => 1000000,
            'momo_initial_balance' => 1000000, 'momo_virtual_balance' => 1000000,
            'mtn_credit_initial_balance' => 1000000, 'mtn_credit_virtual_balance' => 1000000,
            'celtiis_initial_balance' => 1000000, 'celtiis_virtual_balance' => 1000000,
        ]);
        Employee::create(['user_id' => $seller->id, 'shop_id' => $shop->id, 'name' => $seller->name, 'email' => $seller->email]);
        return [$admin, $seller, $shop];
    }
}
