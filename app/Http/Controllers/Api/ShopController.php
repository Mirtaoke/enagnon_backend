<?php

namespace App\Http\Controllers\Api;

use App\Models\Report;
use App\Models\Shop;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopController extends ApiController
{
    public function summary(Request $request)
    {
        $user = $this->authOrFail($request);
        $filters = $request->validate([
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'chart_period' => ['nullable', 'in:day,week,month'],
        ]);
        $shops = $this->visibleShops($user)->withCount(['employees' => fn ($query) => $query->where('is_active', true)])->get();
        $totalEmployees = $shops->sum('employees_count');
        $totalShops = $shops->count();
        $presentEmployeeIds = \App\Models\Attendance::whereIn('shop_id', $shops->pluck('id'))
            ->whereDate('date', today())->whereNotNull('arrival_at')->distinct()->pluck('employee_id');
        $cashBalance = $shops->reduce(function ($carry, Shop $shop) {
            return $carry + $this->shopCashBalance($shop);
        }, 0.0);
        $shopIds = $shops->pluck('id');
        $sales = \App\Models\Report::whereIn('shop_id', $shopIds);
        $month = isset($filters['month'])
            ? Carbon::createFromFormat('Y-m-d', $filters['month'].'-01')->startOfMonth()
            : now()->startOfMonth();
        $chartPeriod = $filters['chart_period'] ?? 'day';
        $rangeStart = $chartPeriod === 'month' ? $month->copy()->startOfYear() : $month->copy()->startOfMonth();
        $rangeEnd = $chartPeriod === 'month' ? $month->copy()->endOfYear() : $month->copy()->endOfMonth();
        $operations = \App\Models\Operation::whereIn('shop_id', $shopIds)
            ->whereBetween('occurred_at', [$rangeStart->copy()->startOfDay(), $rangeEnd->copy()->endOfDay()])
            ->get()
            ->groupBy(function ($operation) use ($chartPeriod) {
                $date = Carbon::parse($operation->occurred_at);
                return match ($chartPeriod) {
                    'week' => $date->copy()->startOfWeek()->toDateString(),
                    'month' => $date->copy()->startOfMonth()->toDateString(),
                    default => $date->toDateString(),
                };
            });
        if ($chartPeriod === 'month') {
            $dates = collect(range(1, 12))->map(fn ($value) => $month->copy()->month($value)->startOfMonth());
        } elseif ($chartPeriod === 'week') {
            $dates = collect();
            for ($cursor = $month->copy()->startOfMonth()->startOfWeek(); $cursor->lte($month->copy()->endOfMonth()); $cursor->addWeek()) {
                $dates->push($cursor->copy());
            }
        } else {
            $dates = collect(range(1, $month->daysInMonth))->map(fn ($value) => $month->copy()->day($value));
        }
        $previousBalance = 0.0;
        $chart = $dates->map(function ($date) use ($operations, &$previousBalance) {
            $key = $date->toDateString();
            $items = $operations->get($key, collect());
            $entries = round((float) $items->where('direction', 'in')->sum('amount'), 2);
            $outputs = round((float) $items->where('direction', 'out')->sum('amount'), 2);
            $balance = round($entries - $outputs, 2);
            $difference = round($balance - $previousBalance, 2);
            $previousBalance = $balance;
            return ['date' => $key, 'total' => $entries, 'entries' => $entries, 'outputs' => $outputs, 'balance' => $balance, 'difference' => $difference];
        });

        return $this->resource([
            'shops' => $shops,
            'summary' => [
                'shop_count' => $totalShops,
                'employee_count' => $totalEmployees,
                'present_employee_count' => $presentEmployeeIds->count(),
                'cash_balance' => round($cashBalance, 2),
                'today_sales' => (float) (clone $sales)->whereDate('date', today())->sum('total_in'),
                'week_sales' => (float) (clone $sales)->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()])->sum('total_in'),
                'month_sales' => (float) (clone $sales)->whereYear('date', now()->year)->whereMonth('date', now()->month)->sum('total_in'),
                'active_shop_count' => $shops->where('is_active', true)->count(),
                'report_count' => \App\Models\Report::whereIn('shop_id', $shopIds)
                    ->whereExists(function ($query) {
                        $query->selectRaw('1')
                            ->from('daily_closures')
                            ->whereColumn('daily_closures.shop_id', 'reports.shop_id')
                            ->whereColumn('daily_closures.date', 'reports.date')
                            ->where('daily_closures.status', 'validated');
                    })
                    ->count(),
                'difference_count' => \App\Models\DailyClosure::whereIn('shop_id', $shopIds)->where('difference', '!=', 0)->count(),
            ],
            'sales_chart' => $chart,
            'chart_month' => $month->format('Y-m'),
            'chart_period' => $chartPeriod,
        ]);
    }

    public function index(Request $request)
    {
        $user = $this->authOrFail($request);

        $shops = $this->visibleShops($user)->withCount(['employees' => fn ($query) => $query->where('is_active', true)])
            ->with(['reports' => function ($query) {
                $query->orderBy('date', 'desc')->limit(1);
            }])
            ->get();

        return $this->resource(['shops' => $shops]);
    }

    public function store(Request $request)
    {
        $user = $this->authOrFail($request);
        abort_unless($user->role === 'admin', 403, 'Réservé à l’administrateur.');
        $data = $request->validate([
            'code' => 'required|string|max:50|unique:shops,code',
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:500',
            'manager_name' => 'required|string|max:255',
            'phone' => 'required|digits:10',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);
        $shop = Shop::create([...$data, 'owner_id' => $user->id, 'currency' => 'FCFA']);
        ActivityLog::create(['user_id' => $user->id, 'action' => 'created', 'subject_type' => Shop::class, 'subject_id' => $shop->id, 'details' => $data, 'ip_address' => $request->ip()]);
        return $this->resource(['shop' => $shop->loadCount('employees')], 201);
    }

    public function update(Request $request, Shop $shop)
    {
        $user = $this->authOrFail($request);
        abort_unless($user->role === 'admin' && $shop->owner_id === $user->id, 403);
        $data = $request->validate([
            'code' => 'sometimes|required|string|max:50|unique:shops,code,'.$shop->id,
            'name' => 'sometimes|required|string|max:255',
            'address' => 'sometimes|required|string|max:500',
            'manager_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);
        $before = $shop->only(array_keys($data));
        $shop->update($data);
        ActivityLog::create(['user_id' => $user->id, 'action' => 'updated', 'subject_type' => Shop::class, 'subject_id' => $shop->id, 'details' => ['before' => $before, 'after' => $data], 'ip_address' => $request->ip()]);
        return $this->resource(['shop' => $shop->fresh()->loadCount('employees')]);
    }

    public function destroy(Request $request, Shop $shop)
    {
        $user = $this->authOrFail($request);
        abort_unless($user->role === 'admin' && $shop->owner_id === $user->id, 403, 'Réservé à l’administrateur.');
        abort_if($shop->employees()->exists() || $shop->operations()->exists() || $shop->reports()->exists(), 422, 'Ce point contient encore des membres, opérations ou rapports. Supprime-les avant de supprimer le point.');
        ActivityLog::create(['user_id' => $user->id, 'action' => 'shop_deleted', 'subject_type' => Shop::class, 'subject_id' => $shop->id, 'details' => ['name' => $shop->name], 'ip_address' => $request->ip()]);
        $shop->delete();
        return response()->noContent();
    }

    public function show(Request $request, Shop $shop)
    {
        $this->ensureShopAccess($this->authOrFail($request), $shop);

        $shop->load(['employees', 'reports' => function ($query) {
            $query->orderBy('date', 'desc')->limit(5);
        }]);

        $todayOperations = $shop->operations()->whereDate('occurred_at', today())->get();
        $groupedOperations = $todayOperations->groupBy('service');
        $serviceSummary = collect(['other', 'moov_credit', 'flooz', 'momo', 'mtn_credit', 'celtiis'])->mapWithKeys(function ($service) use ($groupedOperations) {
            $items = $groupedOperations->get($service, collect());
            $entries = (float) $items->where('direction', 'in')->sum('amount');
            $outputs = (float) $items->where('direction', 'out')->sum('amount');
            return [$service => ['entries' => round($entries, 2), 'outputs' => round($outputs, 2), 'balance' => round($entries - $outputs, 2), 'count' => $items->count()]];
        });
        $todayIn = (float) $todayOperations->where('direction', 'in')->sum('amount');
        $todayOut = (float) $todayOperations->where('direction', 'out')->sum('amount');

        return $this->resource([
            'shop' => $shop,
            'cash' => [
                'balance' => $this->shopCashBalance($shop),
                'day_balance' => round($todayIn - $todayOut, 2),
                'total_in' => round($todayIn, 2),
                'total_out' => round($todayOut, 2),
            ],
            'service_summary' => $serviceSummary,
        ]);
    }

    public function employees(Request $request, Shop $shop)
    {
        $this->ensureShopAccess($this->authOrFail($request), $shop);

        return $this->resource(['employees' => $shop->employees()->where('is_active', true)->orderBy('name')->get()]);
    }

    public function cash(Request $request, Shop $shop)
    {
        $this->ensureShopAccess($this->authOrFail($request), $shop);

        $totalIn = (float) $shop->operations()->where('direction', 'in')->sum('amount');
        $totalOut = (float) $shop->operations()->where('direction', 'out')->sum('amount');

        return $this->resource([
            'cash' => [
                'balance' => round($totalIn - $totalOut, 2),
                'total_in' => round($totalIn, 2),
                'total_out' => round($totalOut, 2),
            ],
        ]);
    }

    public function adjustCash(Request $request, Shop $shop)
    {
        $admin = $this->authOrFail($request);
        abort_unless($admin->role === 'admin' && $shop->owner_id === $admin->id, 403, 'Réservé à l’administrateur.');
        $data = $request->validate([
            'direction' => 'required|in:in,out', 'amount' => 'required|numeric|gt:0',
            'description' => 'required|string|max:1000',
        ]);
        $operation = $shop->operations()->create([
            'client_uuid' => (string) Str::uuid(), 'user_id' => $admin->id,
            'service' => 'other', 'direction' => $data['direction'],
            'type' => $data['direction'] === 'in' ? 'deposit' : 'withdrawal',
            'amount' => $data['amount'], 'description' => $data['description'], 'occurred_at' => now(),
        ]);
        ActivityLog::create(['user_id' => $admin->id, 'action' => 'cash_adjusted', 'subject_type' => Shop::class, 'subject_id' => $shop->id, 'details' => $operation->toArray(), 'ip_address' => $request->ip()]);
        return $this->resource(['operation' => $operation, 'cash_balance' => $this->shopCashBalance($shop)], 201);
    }

    public function cashAdjustments(Request $request)
    {
        $admin = $this->authOrFail($request);
        abort_unless($admin->role === 'admin', 403, 'Réservé à l’administrateur.');
        $items = \App\Models\Operation::with('shop:id,name')
            ->whereIn('shop_id', $admin->shops()->pluck('id'))
            ->whereNull('employee_id')->whereIn('type', ['deposit', 'withdrawal', 'admin_cash_deposit', 'admin_cash_withdrawal'])
            ->latest('occurred_at')->limit(200)->get();
        return $this->resource(['movements' => $items]);
    }

    private function shopCashBalance(Shop $shop): float
    {
        $entries = (float) $shop->operations()->where('direction', 'in')->sum('amount');
        $outputs = (float) $shop->operations()->where('direction', 'out')->sum('amount');
        return round($entries - $outputs, 2);
    }

    private function visibleShops(\App\Models\User $user)
    {
        if ($user->role === 'admin') {
            return Shop::query()->where('owner_id', $user->id);
        }
        return Shop::query()->whereKey(optional($user->employee)->shop_id ?? 0);
    }

    private function ensureShopAccess(\App\Models\User $user, Shop $shop): void
    {
        abort_unless(
            ($user->role === 'admin' && $shop->owner_id === $user->id)
                || optional($user->employee)->shop_id === $shop->id,
            403,
            'Accès non autorisé à ce point de vente.'
        );
    }
}
