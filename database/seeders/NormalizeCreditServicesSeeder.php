<?php

namespace Database\Seeders;

use App\Models\DailyClosure;
use App\Models\Operation;
use App\Models\Report;
use Illuminate\Database\Seeder;

class NormalizeCreditServicesSeeder extends Seeder
{
    public function run(): void
    {
        Operation::whereIn('service', ['mtn_credit', 'moov_credit'])
            ->where('direction', 'out')->update(['direction' => 'in', 'type' => 'deposit']);

        foreach (Report::all() as $report) {
            $operations = Operation::where('shop_id', $report->shop_id)->whereDate('occurred_at', $report->date)->get();
            $totalIn = (float) $operations->where('direction', 'in')->sum('amount');
            $totalOut = (float) $operations->where('direction', 'out')->sum('amount');
            $report->update(['total_in' => $totalIn, 'total_out' => $totalOut, 'cash_balance' => $totalIn - $totalOut]);
            $closure = DailyClosure::where('shop_id', $report->shop_id)->whereDate('date', $report->date)->first();
            if ($closure) {
                $balance = fn ($service) => (float) $operations->where('service', $service)->where('direction', 'in')->sum('amount')
                    - (float) $operations->where('service', $service)->where('direction', 'out')->sum('amount');
                $closure->update([
                    'mtn_credit' => $balance('mtn_credit'), 'moov_credit' => $balance('moov_credit'),
                    'actual_total' => $totalIn - $totalOut, 'expected_total' => $totalIn - $totalOut,
                ]);
            }
        }
    }
}
