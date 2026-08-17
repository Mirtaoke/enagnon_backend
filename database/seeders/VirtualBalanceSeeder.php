<?php

namespace Database\Seeders;

use App\Models\Shop;
use Illuminate\Database\Seeder;

class VirtualBalanceSeeder extends Seeder
{
    public function run(): void
    {
        $services = ['moov_credit', 'flooz', 'momo', 'mtn_credit', 'celtiis'];
        Shop::query()->each(function (Shop $shop) use ($services) {
            $operations = $shop->operations()->orderBy('occurred_at')->orderBy('id')->get();
            $running = array_fill_keys($services, 0.0); $minimum = array_fill_keys($services, 0.0);
            foreach ($operations as $operation) {
                $service = $operation->type === 'virtual_credit_purchase' ? $operation->network : (in_array($operation->service, $services, true) ? $operation->service : null);
                if (! $service || ! isset($running[$service])) continue;
                $delta = $operation->type === 'virtual_credit_purchase' ? (float) $operation->amount : ($operation->direction === 'in' ? -(float) $operation->amount : (float) $operation->amount);
                $running[$service] += $delta; $minimum[$service] = min($minimum[$service], $running[$service]);
            }
            $initials = collect($services)->mapWithKeys(fn ($service) => [$service => max((float) $shop->{$service.'_initial_balance'}, -$minimum[$service])])->all();
            $shop->updateQuietly(collect($initials)->mapWithKeys(fn ($balance, $service) => [$service.'_initial_balance' => $balance])->all());
            $balances = $initials;
            foreach ($operations as $operation) {
                $service = $operation->type === 'virtual_credit_purchase' ? $operation->network : (in_array($operation->service, $services, true) ? $operation->service : null);
                if (! $service || ! isset($balances[$service])) { $operation->updateQuietly(['virtual_balance_after' => null]); continue; }
                $delta = $operation->type === 'virtual_credit_purchase' ? (float) $operation->amount : ($operation->direction === 'in' ? -(float) $operation->amount : (float) $operation->amount);
                $balances[$service] = round($balances[$service] + $delta, 2);
                $operation->updateQuietly(['virtual_balance_after' => $balances[$service]]);
            }
            $shop->updateQuietly(collect($balances)->mapWithKeys(fn ($balance, $service) => [$service.'_virtual_balance' => $balance])->all());
        });
    }
}
