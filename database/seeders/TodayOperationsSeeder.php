<?php

namespace Database\Seeders;

use App\Models\Operation;
use App\Models\Shop;
use Illuminate\Database\Seeder;

class TodayOperationsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Shop::with('employees.user')->get() as $shop) {
            $employee = $shop->employees->first(fn ($item) => $item->user);
            if (! $employee) continue;
            $rows = [
                ['momo', 'in', 'deposit', 65000, 'Dépôt MoMo du jour', '0197001001'],
                ['momo', 'out', 'withdrawal', 18000, 'Retrait MoMo du jour', '0197001002'],
                ['flooz', 'in', 'deposit', 42000, 'Dépôt Flooz du jour', '0197001003'],
                ['mtn_credit', 'in', 'deposit', 12000, 'Encaissement MTN crédit', '0197001004'],
                ['moov_credit', 'in', 'deposit', 10000, 'Encaissement Moov crédit', '0197001005'],
                ['celtiis', 'in', 'deposit', 27000, 'Dépôt Celtiis du jour', '0197001006'],
                ['celtiis', 'out', 'withdrawal', 8000, 'Retrait Celtiis du jour', '0197001007'],
                ['other', 'out', 'expense', 3500, 'Frais de déplacement du jour', null],
                ['other', 'out', 'debt', 7000, 'Avance accordée à un client', null],
                ['other', 'in', 'debt_repayment', 4000, 'Remboursement reçu du client', null],
                ['other', 'out', 'virtual_credit_purchase', 15000, 'Achat crédit virtuel MTN', null],
            ];
            foreach ($rows as $index => [$service, $direction, $type, $amount, $description, $phone]) {
                $uuid = sprintf('%08d-%04d-1208-%04d-%012d', $shop->id, now()->year, $index, now()->day);
                Operation::updateOrCreate(['client_uuid' => $uuid], [
                    'shop_id' => $shop->id, 'employee_id' => $employee->id, 'user_id' => $employee->user_id,
                    'service' => $service, 'direction' => $direction, 'type' => $type, 'amount' => $amount,
                    'phone' => $phone, 'network' => $type === 'virtual_credit_purchase' ? 'MTN' : null,
                    'description' => $description, 'occurred_at' => now()->setTime(9 + $index, 10),
                ]);
            }
        }
    }
}
