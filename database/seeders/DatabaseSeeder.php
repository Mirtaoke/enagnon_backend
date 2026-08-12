<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\DailyClosure;
use App\Models\Operation;
use App\Models\Report;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin MultiShop',
            'username' => 'admin',
            'email' => 'admin@multishop.test',
            'role' => 'admin',
            'password' => 'password',
            'api_token' => Str::random(60),
        ]);

        $shopA = Shop::create([
            'code' => 'PDV-001',
            'name' => 'Boutique Centrale',
            'address' => 'Centre-ville',
            'manager_name' => 'Moussa Ndiaye',
            'phone' => '0197000000',
            'description' => 'Boutique principale pour les ventes et les rapports journaliers.',
            'currency' => 'FCFA',
            'owner_id' => $admin->id,
        ]);

        $shopB = Shop::create([
            'code' => 'PDV-002',
            'name' => 'Boutique Dakar',
            'address' => 'Dakar',
            'manager_name' => 'Cheikh Diop',
            'phone' => '0198000000',
            'description' => 'Magasin de quartier avec plusieurs employés actifs.',
            'currency' => 'FCFA',
            'owner_id' => $admin->id,
        ]);

        $seller = User::factory()->create([
            'name' => 'Moussa Ndiaye',
            'username' => 'moussa',
            'email' => 'moussa@multishop.test',
            'role' => 'seller',
            'password' => 'password',
        ]);

        $employee1 = Employee::create([
            'user_id' => $seller->id,
            'shop_id' => $shopA->id,
            'name' => 'Moussa Ndiaye',
            'role' => 'Vendeur / agent',
            'email' => 'moussa@multishop.test',
            'phone' => '9700000001',
        ]);

        $sellerAwa = User::factory()->create([
            'name' => 'Awa Fall',
            'username' => 'awa',
            'email' => 'awa@multishop.test',
            'role' => 'seller',
            'password' => 'password',
        ]);

        $employee2 = Employee::create([
            'user_id' => $sellerAwa->id,
            'shop_id' => $shopA->id,
            'name' => 'Awa Fall',
            'role' => 'Vendeur / agent',
            'email' => 'awa@multishop.test',
            'phone' => '9700000002',
        ]);

        $manager = User::factory()->create([
            'name' => 'Cheikh Diop',
            'username' => 'cheikh',
            'email' => 'cheikh@multishop.test',
            'role' => 'manager',
            'password' => 'password',
        ]);

        $employee3 = Employee::create([
            'user_id' => $manager->id,
            'shop_id' => $shopB->id,
            'name' => 'Cheikh Diop',
            'role' => 'Responsable',
            'email' => 'cheikh@multishop.test',
            'phone' => '9700000003',
        ]);

        $samples = [
            [$shopA, $seller, $employee1, 8],
            [$shopB, $manager, $employee3, 5],
        ];
        $services = ['momo', 'flooz', 'mtn_credit', 'moov_credit', 'celtiis'];
        foreach ($samples as [$shop, $user, $employee, $days]) {
            for ($offset = $days; $offset >= 1; $offset--) {
                $date = now()->subDays($offset);
                $totalIn = 0;
                $totalOut = 0;
                $serviceBalances = array_fill_keys(['other', ...$services], 0.0);
                foreach ($services as $index => $service) {
                    $entry = 18000 + (($days - $offset + 1) * 3500) + ($index * 2100);
                    $output = 6000 + (($offset + $index) * 700);
                    foreach ([['in', 'deposit', $entry], ['out', 'withdrawal', $output]] as [$direction, $type, $amount]) {
                        Operation::create([
                            'client_uuid' => (string) Str::uuid(), 'shop_id' => $shop->id,
                            'employee_id' => $employee->id, 'user_id' => $user->id,
                            'service' => $service, 'direction' => $direction, 'type' => $type,
                            'amount' => $amount, 'phone' => '0197'.str_pad((string) ($offset * 100 + $index), 6, '0', STR_PAD_LEFT),
                            'description' => $direction === 'in' ? 'Dépôt client de démonstration' : 'Retrait client de démonstration',
                            'occurred_at' => $date->copy()->setTime(9 + $index, 15),
                        ]);
                        $direction === 'in' ? $totalIn += $amount : $totalOut += $amount;
                        $serviceBalances[$service] += $direction === 'in' ? $amount : -$amount;
                    }
                }
                $expense = 2500 + $offset * 150;
                $debt = 4000 + $offset * 200;
                $repayment = 1500 + $offset * 100;
                foreach ([
                    ['out', 'expense', $expense, 'Transport et fournitures'],
                    ['out', 'debt', $debt, 'Avance accordée à un client'],
                    ['in', 'debt_repayment', $repayment, 'Remboursement partiel du client'],
                ] as [$direction, $type, $amount, $description]) {
                    Operation::create([
                        'client_uuid' => (string) Str::uuid(), 'shop_id' => $shop->id,
                        'employee_id' => $employee->id, 'user_id' => $user->id,
                        'service' => 'other', 'direction' => $direction, 'type' => $type,
                        'amount' => $amount, 'description' => $description,
                        'occurred_at' => $date->copy()->setTime(16, 20),
                    ]);
                    $direction === 'in' ? $totalIn += $amount : $totalOut += $amount;
                    $serviceBalances['other'] += $direction === 'in' ? $amount : -$amount;
                }
                $balance = $totalIn - $totalOut;
                DailyClosure::create([
                    'shop_id' => $shop->id, 'employee_id' => $employee->id,
                    'created_by' => $user->id, 'validated_by' => $user->id,
                    'date' => $date->toDateString(), 'status' => 'validated',
                    'cash' => $serviceBalances['other'], 'moov_credit' => $serviceBalances['moov_credit'],
                    'flooz' => $serviceBalances['flooz'], 'momo' => $serviceBalances['momo'],
                    'mtn_credit' => $serviceBalances['mtn_credit'], 'celtiis' => $serviceBalances['celtiis'],
                    'expenses' => [['description' => 'Transport et fournitures', 'amount' => $expense]],
                    'debts' => [['description' => 'Avance accordée à un client', 'amount' => $debt]],
                    'expected_total' => $balance, 'actual_total' => $balance, 'difference' => 0,
                    'closed_at' => $date->copy()->setTime(19, 0), 'submitted_at' => $date->copy()->setTime(19, 5),
                ]);
                $report = Report::create([
                    'shop_id' => $shop->id, 'date' => $date->toDateString(),
                    'total_in' => $totalIn, 'total_out' => $totalOut,
                    'cash_balance' => $balance, 'note' => 'Rapport de démonstration envoyé par '.$user->name.'.',
                ]);
                Attendance::create([
                    'employee_id' => $employee->id, 'shop_id' => $shop->id,
                    'date' => $date->toDateString(), 'arrival_at' => $date->copy()->setTime(8, 3 + $offset),
                    'departure_at' => $date->copy()->setTime(18, 5 + $offset),
                ]);
                ActivityLog::create([
                    'user_id' => $user->id, 'action' => 'report_sent',
                    'subject_type' => Report::class, 'subject_id' => $report->id,
                    'details' => ['seeded' => true], 'created_at' => $date->copy()->setTime(19, 5),
                    'updated_at' => $date->copy()->setTime(19, 5),
                ]);
            }
        }

    }
}
