<?php

namespace Database\Seeders;

use App\Models\Operation;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Seeder;

class EnagnonBrandingSeeder extends Seeder
{
    public function run(): void
    {
        User::where('role', 'admin')->where(function ($query) {
            $query->where('name', 'like', '%MultiShop%')->orWhere('name', 'like', 'Admin%');
        })->update(['name' => 'Admin']);

        Operation::where('type', 'admin_cash_deposit')->update(['type' => 'deposit']);
        Operation::where('type', 'admin_cash_withdrawal')->update(['type' => 'withdrawal']);
        Operation::where('description', 'like', '% de démonstration')->get()->each(function ($operation) {
            $operation->update(['description' => str_ireplace([' client de démonstration', ' de démonstration'], '', $operation->description)]);
        });
        Operation::whereIn('description', ['Dépôt client', 'Retrait client', 'Dépôt client de démonstration', 'Retrait client de démonstration'])
            ->get()->each(fn ($operation) => $operation->update(['description' => $operation->direction === 'in' ? 'Dépôt' : 'Retrait']));
        Report::where('note', 'like', '%démonstration%')->get()->each(function ($report) {
            $report->update(['note' => str_ireplace(' de démonstration', '', $report->note)]);
        });
        User::where('name', 'like', '%Démo%')->get()->each(function ($user) {
            $user->update(['name' => str_ireplace([' Démo', 'Démo '], ['', ''], $user->name)]);
            $user->employee?->update(['name' => $user->name]);
        });
    }
}
