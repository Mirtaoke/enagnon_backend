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
        Operation::where('description', 'like', '%démonstration%')->get()->each(function ($operation) {
            $description = preg_replace('/\s*(client\s+)?de\s+démonstration/iu', '', (string) $operation->description);
            $operation->update(['description' => trim($description) ?: ($operation->direction === 'in' ? 'Dépôt' : 'Retrait')]);
        });
        Operation::whereIn('description', ['Dépôt client', 'Retrait client'])
            ->get()->each(fn ($operation) => $operation->update(['description' => $operation->direction === 'in' ? 'Dépôt' : 'Retrait']));
        Report::where('note', 'like', '%démonstration%')->get()->each(function ($report) {
            $note = preg_replace('/\s*de\s+démonstration/iu', '', (string) $report->note);
            $report->update(['note' => trim($note) ?: 'Rapport envoyé']);
        });
    }
}
