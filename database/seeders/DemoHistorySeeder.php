<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\DailyClosure;
use App\Models\Employee;
use App\Models\Operation;
use App\Models\Report;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoHistorySeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        foreach (Shop::where('owner_id', $admin->id)->get() as $shop) {
            $employee = $shop->employees()->whereNotNull('user_id')->first();
            if (! $employee) {
                $user = User::updateOrCreate(['email' => "demo{$shop->id}@enagnon.test"], [
                    'name' => 'Agent Démo '.$shop->id, 'username' => 'agent_demo_'.$shop->id,
                    'role' => 'seller', 'password' => 'password',
                ]);
                $employee = Employee::updateOrCreate(['user_id' => $user->id], [
                    'shop_id' => $shop->id, 'name' => $user->name, 'email' => $user->email,
                    'phone' => '97000000'.str_pad((string) $shop->id, 2, '0', STR_PAD_LEFT),
                    'role' => 'Vendeur / agent',
                ]);
            }
            $user = $employee->user;
            foreach (range(10, 1) as $offset) {
                $date = now()->subDays($offset);
                $totalIn = 0.0; $totalOut = 0.0;
                $balances = array_fill_keys(['other', 'momo', 'flooz', 'mtn_credit', 'moov_credit', 'celtiis'], 0.0);
                foreach (['momo', 'flooz', 'mtn_credit', 'moov_credit', 'celtiis'] as $index => $service) {
                    foreach ([['in', 'deposit', 20000 + (11 - $offset) * 3200 + $index * 1700], ['out', 'withdrawal', 5500 + $offset * 450 + $index * 500]] as $movement => [$direction, $type, $amount]) {
                        $uuid = sprintf('%08d-%04d-%04d-%04d-%012d', $shop->id, $offset, $index, $movement, 1);
                        Operation::updateOrCreate(['client_uuid' => $uuid], [
                            'shop_id' => $shop->id, 'employee_id' => $employee->id, 'user_id' => $user->id,
                            'service' => $service, 'direction' => $direction, 'type' => $type, 'amount' => $amount,
                            'phone' => '019700'.str_pad((string) ($offset * 10 + $index), 4, '0', STR_PAD_LEFT),
                            'description' => $direction === 'in' ? 'Dépôt client' : 'Retrait client',
                            'occurred_at' => $date->copy()->setTime(9 + $index, 15),
                        ]);
                        $direction === 'in' ? $totalIn += $amount : $totalOut += $amount;
                        $balances[$service] += $direction === 'in' ? $amount : -$amount;
                    }
                }
                foreach ([['out', 'expense', 2200 + $offset * 100, 'Frais de déplacement'], ['out', 'debt', 3500 + $offset * 150, 'Avance client'], ['in', 'debt_repayment', 1400 + $offset * 80, 'Remboursement client']] as $index => [$direction, $type, $amount, $description]) {
                    $uuid = sprintf('%08d-%04d-9999-%04d-%012d', $shop->id, $offset, $index, 1);
                    Operation::updateOrCreate(['client_uuid' => $uuid], [
                        'shop_id' => $shop->id, 'employee_id' => $employee->id, 'user_id' => $user->id,
                        'service' => 'other', 'direction' => $direction, 'type' => $type, 'amount' => $amount,
                        'description' => $description, 'occurred_at' => $date->copy()->setTime(16, 20 + $index),
                    ]);
                    $direction === 'in' ? $totalIn += $amount : $totalOut += $amount;
                    $balances['other'] += $direction === 'in' ? $amount : -$amount;
                }
                $balance = $totalIn - $totalOut;
                $closureId = DailyClosure::where('shop_id', $shop->id)->whereDate('date', $date)->value('id');
                DailyClosure::updateOrCreate(['id' => $closureId], [
                    'shop_id' => $shop->id, 'date' => $date->toDateString(),
                    'employee_id' => $employee->id, 'created_by' => $user->id, 'validated_by' => $user->id,
                    'status' => 'validated', 'cash' => $balances['other'], 'moov_credit' => $balances['moov_credit'],
                    'flooz' => $balances['flooz'], 'momo' => $balances['momo'], 'mtn_credit' => $balances['mtn_credit'],
                    'celtiis' => $balances['celtiis'], 'expenses' => [['description' => 'Frais de déplacement', 'amount' => 2200 + $offset * 100]],
                    'debts' => [['description' => 'Avance client', 'amount' => 3500 + $offset * 150]],
                    'expected_total' => $balance, 'actual_total' => $balance, 'difference' => 0,
                    'closed_at' => $date->copy()->setTime(19, 0), 'submitted_at' => $date->copy()->setTime(19, 5),
                ]);
                $reportId = Report::where('shop_id', $shop->id)->whereDate('date', $date)->value('id');
                $report = Report::updateOrCreate(['id' => $reportId], [
                    'shop_id' => $shop->id, 'date' => $date->toDateString(),
                    'total_in' => $totalIn, 'total_out' => $totalOut, 'cash_balance' => $balance,
                    'note' => 'Rapport journalier envoyé par '.$user->name.'.',
                ]);
                $attendanceId = Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->value('id');
                Attendance::updateOrCreate(['id' => $attendanceId], [
                    'employee_id' => $employee->id, 'date' => $date->toDateString(),
                    'shop_id' => $shop->id, 'arrival_at' => $date->copy()->setTime(8, 5 + $offset),
                    'departure_at' => $date->copy()->setTime(18, 10 + $offset),
                ]);
                ActivityLog::firstOrCreate(['action' => 'report_sent', 'subject_type' => Report::class, 'subject_id' => $report->id], [
                    'user_id' => $user->id, 'details' => ['demo' => true], 'created_at' => $date->copy()->setTime(19, 5),
                ]);
            }
        }
    }
}
