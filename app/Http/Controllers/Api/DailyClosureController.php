<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\DailyClosure;
use App\Models\Report;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DailyClosureController extends ApiController
{
    private const SERVICES = ['other', 'moov_credit', 'flooz', 'momo', 'mtn_credit', 'celtiis'];

    public function index(Request $request, Shop $shop)
    {
        $this->ensureAccess($this->authOrFail($request), $shop);
        return $this->resource(['closures' => $shop->dailyClosures()->where('status', 'validated')->latest('date')->get()]);
    }

    public function today(Request $request, Shop $shop)
    {
        $this->ensureAccess($this->authOrFail($request), $shop);
        $date = $request->date('date')?->toDateString() ?? today()->toDateString();
        $existing = $shop->dailyClosures()->whereDate('date', $date)->first();
        return $this->resource(['closure' => [...$this->operationSummary($shop, $date),
            'id' => $existing?->id, 'status' => $existing?->status ?? 'open',
        ]]);
    }

    public function store(Request $request, Shop $shop)
    {
        $user = $this->authOrFail($request);
        $this->ensureAccess($user, $shop);
        abort_unless(in_array($user->role, ['seller', 'manager'], true) && $user->employee, 403, 'Seuls les vendeurs et responsables peuvent envoyer un rapport.');
        $data = $request->validate([
            'date' => 'required|date',
        ]);
        $existing = $shop->dailyClosures()->whereDate('date', $data['date'])->first();
        if ($existing?->status === 'validated') {
            return response()->json(['message' => 'Ce rapport a déjà été envoyé. L’administrateur doit le rouvrir avant modification.'], 422);
        }

        $summary = $this->operationSummary($shop, $data['date']);
        abort_if($summary['count'] === 0, 422, 'Ajoute au moins une opération avant d’envoyer le rapport.');
        $actual = $summary['balance'];
        $expected = $actual;
        $difference = 0.0;

        $closure = DB::transaction(function () use ($data, $summary, $shop, $user, $expected, $actual, $difference, $request) {
            $service = $summary['services'];
            $closure = DailyClosure::updateOrCreate(
                ['shop_id' => $shop->id, 'date' => $data['date']],
                [
                    'created_by' => $user->id, 'employee_id' => $user->employee?->id,
                    'validated_by' => $user->id, 'status' => 'validated',
                    'cash' => $service['other']['balance'],
                    ...collect(self::SERVICES)->reject(fn ($key) => $key === 'other')->mapWithKeys(fn ($key) => [$key => $service[$key]['balance']])->all(),
                    'expenses' => $summary['expenses'], 'debts' => $summary['debts'],
                    'virtual_credit_purchase' => $summary['virtual_credit_purchase'],
                    'expected_total' => $expected, 'actual_total' => $actual,
                    'difference' => $difference, 'difference_reason' => null,
                    'closed_at' => now(), 'submitted_at' => now(),
                ],
            );
            $report = Report::updateOrCreate(
                ['shop_id' => $shop->id, 'date' => $data['date']],
                ['total_in' => $summary['total_in'], 'total_out' => $summary['total_out'], 'cash_balance' => $actual,
                    'note' => 'Rapport envoyé par '.$user->name.'.'],
            );
            ActivityLog::create([
                'user_id' => $user->id, 'action' => 'report_sent', 'subject_type' => Report::class,
                'subject_id' => $report->id, 'details' => ['shop_id' => $shop->id, 'date' => $data['date'], 'difference' => $difference],
                'ip_address' => $request->ip(),
            ]);
            return $closure->fresh();
        });
        return $this->resource(['closure' => $closure, 'published' => true], 201);
    }

    private function operationSummary(Shop $shop, string $date): array
    {
        $operations = $shop->operations()->whereDate('occurred_at', $date)->get();
        $services = collect(self::SERVICES)->mapWithKeys(function ($key) use ($operations) {
            $items = $operations->where('service', $key);
            $entries = (float) $items->where('direction', 'in')->sum('amount');
            $outputs = (float) $items->where('direction', 'out')->sum('amount');
            return [$key => ['entries' => round($entries, 2), 'outputs' => round($outputs, 2), 'balance' => round($entries - $outputs, 2), 'count' => $items->count()]];
        });
        $totalIn = (float) $operations->where('direction', 'in')->sum('amount');
        $totalOut = (float) $operations->where('direction', 'out')->sum('amount');
        $lines = fn ($items) => $items->map(fn ($item) => ['label' => $item->description, 'amount' => $item->amount, 'operation_id' => $item->id])->values()->all();
        return [
            'date' => $date, 'services' => $services, 'count' => $operations->count(),
            'total_in' => round($totalIn, 2), 'total_out' => round($totalOut, 2), 'balance' => round($totalIn - $totalOut, 2),
            'expenses' => $lines($operations->where('type', 'expense')),
            'debts' => $lines($operations->where('type', 'debt')),
            'virtual_credit_purchase' => round((float) $operations->where('type', 'virtual_credit_purchase')->sum('amount'), 2),
        ];
    }

    private function ensureAccess($user, Shop $shop): void
    {
        abort_unless(($user->role === 'admin' && $shop->owner_id === $user->id) || $user->employee?->shop_id === $shop->id, 403, 'Accès non autorisé à ce point de vente.');
    }
}
