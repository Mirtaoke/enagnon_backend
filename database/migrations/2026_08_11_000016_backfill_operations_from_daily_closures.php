<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        $services = ['cash', 'moov_credit', 'flooz', 'momo', 'mtn_credit', 'celtiis'];
        DB::table('daily_closures')->orderBy('id')->each(function ($closure) use ($services) {
            if (DB::table('operations')->where('shop_id', $closure->shop_id)->whereDate('occurred_at', $closure->date)->exists()) return;
            $base = [
                'shop_id' => $closure->shop_id, 'employee_id' => $closure->employee_id,
                'user_id' => $closure->validated_by ?: $closure->created_by,
                'occurred_at' => $closure->date.' 12:00:00', 'created_at' => now(), 'updated_at' => now(),
            ];
            foreach ($services as $service) {
                $amount = (float) $closure->{$service};
                if ($amount <= 0) continue;
                DB::table('operations')->insert([...$base, 'client_uuid' => (string) Str::uuid(),
                    'service' => $service, 'direction' => 'in', 'type' => 'deposit', 'amount' => $amount,
                    'phone' => null, 'description' => '[IMPORT] Montant repris de la clôture existante']);
            }
            foreach (json_decode($closure->expenses ?: '[]', true) ?: [] as $line) {
                if ((float) ($line['amount'] ?? 0) <= 0) continue;
                DB::table('operations')->insert([...$base, 'client_uuid' => (string) Str::uuid(),
                    'service' => 'cash', 'direction' => 'out', 'type' => 'expense', 'amount' => $line['amount'],
                    'phone' => null, 'description' => '[IMPORT] '.($line['label'] ?? 'Dépense')]);
            }
            foreach (json_decode($closure->debts ?: '[]', true) ?: [] as $line) {
                if ((float) ($line['amount'] ?? 0) <= 0) continue;
                DB::table('operations')->insert([...$base, 'client_uuid' => (string) Str::uuid(),
                    'service' => 'cash', 'direction' => 'out', 'type' => 'debt', 'amount' => $line['amount'],
                    'phone' => null, 'description' => '[IMPORT] '.($line['label'] ?? 'Dette')]);
            }
            if ((float) $closure->virtual_credit_purchase > 0) {
                DB::table('operations')->insert([...$base, 'client_uuid' => (string) Str::uuid(),
                    'service' => 'cash', 'direction' => 'out', 'type' => 'virtual_credit_purchase',
                    'amount' => $closure->virtual_credit_purchase, 'phone' => null,
                    'description' => '[IMPORT] Achat de crédit / virtuel']);
            }
        });
    }

    public function down(): void
    {
        DB::table('operations')->where('description', 'like', '[IMPORT]%')->delete();
    }
};
