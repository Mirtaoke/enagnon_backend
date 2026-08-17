<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\Operation;
use App\Models\OperationEditRequest;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationController extends ApiController
{
    private const SERVICES = ['other', 'moov_credit', 'flooz', 'momo', 'mtn_credit', 'celtiis'];
    private const TYPES = ['deposit', 'withdrawal', 'expense', 'virtual_credit_purchase', 'debt', 'debt_repayment', 'other'];

    public function index(Request $request, Shop $shop)
    {
        $this->ensureAccess($this->authOrFail($request), $shop);
        $filters = $request->validate([
            'service' => 'nullable|in:'.implode(',', self::SERVICES),
            'date' => 'nullable|date',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);
        $query = $shop->operations()->with(['user:id,name', 'editRequests' => fn ($query) => $query->where('requested_by', $this->authOrFail($request)->id)->latest()])->latest('occurred_at');
        if ($filters['service'] ?? null) $query->where('service', $filters['service']);
        if ($filters['date'] ?? null) $query->whereDate('occurred_at', $filters['date']);
        if ($filters['from'] ?? null) $query->whereDate('occurred_at', '>=', $filters['from']);
        if ($filters['to'] ?? null) $query->whereDate('occurred_at', '<=', $filters['to']);
        return $this->resource(['operations' => $query->limit(500)->get()]);
    }

    public function summary(Request $request, Shop $shop)
    {
        $this->ensureAccess($this->authOrFail($request), $shop);
        $date = $request->date('date')?->toDateString() ?? today()->toDateString();
        return $this->resource($this->summaryFor($shop, $date));
    }

    public function store(Request $request, Shop $shop)
    {
        $user = $this->authOrFail($request);
        $this->ensureAccess($user, $shop);
        abort_unless(in_array($user->role, ['seller', 'manager'], true) && $user->employee, 403, 'Seuls les vendeurs et responsables peuvent ajouter une opération.');
        $data = $this->validated($request);
        $existing = Operation::where('client_uuid', $data['client_uuid'])->first();
        if ($existing) return $this->resource(['operation' => $existing->load('user:id,name'), 'duplicate' => true]);
        $operation = DB::transaction(function () use ($data, $shop, $user) {
            $lockedShop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
            $this->assertFundsAvailable($lockedShop, $data);
            $operation = Operation::firstOrCreate(['client_uuid' => $data['client_uuid']], [...$data, 'shop_id' => $shop->id, 'user_id' => $user->id, 'employee_id' => $user->employee?->id]);
            if ($operation->wasRecentlyCreated) {
                $this->recalculateVirtualBalances($shop);
                $operation->refresh();
            }
            return $operation;
        });
        if ($operation->wasRecentlyCreated) {
            $this->log($request, $user->id, $operation, 'operation_created');
        }
        return $this->resource(['operation' => $operation->load('user:id,name'), 'duplicate' => ! $operation->wasRecentlyCreated], $operation->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, Shop $shop, Operation $operation)
    {
        $user = $this->authOrFail($request);
        $this->ensureAccess($user, $shop);
        abort_unless(in_array($user->role, ['seller', 'manager'], true) && $user->employee, 403, 'L’administrateur dispose uniquement d’un accès en lecture.');
        abort_unless($operation->shop_id === $shop->id, 404);
        abort_unless($operation->user_id === $user->id, 403, 'Tu ne peux modifier que tes propres opérations.');
        $authorization = $operation->editRequests()->where('requested_by', $user->id)->where('status', 'approved')->whereNull('used_at')->latest()->first();
        abort_unless($authorization, 403, 'Cette modification doit d’abord être autorisée par l’administrateur.');
        $before = $operation->toArray();
        DB::transaction(function () use ($operation, $request, $authorization, $shop) {
            $lockedShop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
            $data = $this->validated($request, false);
            $this->assertFundsAvailable($lockedShop, $data, $operation);
            $operation->update($data);
            $authorization->update(['used_at' => now()]);
            $this->recalculateVirtualBalances($shop);
        });
        $this->log($request, $user->id, $operation, 'operation_updated', ['before' => $before]);
        return $this->resource(['operation' => $operation->fresh()->load('user:id,name')]);
    }

    public function destroy(Request $request, Shop $shop, Operation $operation)
    {
        $user = $this->authOrFail($request);
        $this->ensureAccess($user, $shop);
        abort_unless(in_array($user->role, ['seller', 'manager'], true) && $user->employee, 403, 'L’administrateur dispose uniquement d’un accès en lecture.');
        abort_unless($operation->shop_id === $shop->id, 404);
        abort_unless($operation->user_id === $user->id, 403, 'Tu ne peux supprimer que tes propres opérations.');
        $details = $operation->toArray();
        $id = $operation->id;
        DB::transaction(function () use ($operation, $shop) {
            $lockedShop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();
            $this->assertDeletionAllowed($lockedShop, $operation);
            $operation->delete();
            $this->recalculateVirtualBalances($shop);
        });
        ActivityLog::create(['user_id' => $user->id, 'action' => 'operation_deleted', 'subject_type' => Operation::class, 'subject_id' => $id, 'details' => $details, 'ip_address' => $request->ip()]);
        return response()->noContent();
    }

    public function requestEdit(Request $request, Shop $shop, Operation $operation)
    {
        $user = $this->authOrFail($request); $this->ensureAccess($user, $shop);
        abort_unless(in_array($user->role, ['seller', 'manager'], true) && $operation->shop_id === $shop->id && $operation->user_id === $user->id, 403, 'Demande non autorisée.');
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        abort_if($operation->editRequests()->where('requested_by', $user->id)->where('status', 'rejected')->exists(), 422, 'La demande de modification de cette opération a été refusée. Aucune nouvelle demande n’est autorisée.');
        abort_if($operation->editRequests()->where('requested_by', $user->id)->where('status', 'pending')->exists(), 422, 'Une demande est déjà en attente pour cette opération.');
        $editRequest = $operation->editRequests()->create(['requested_by' => $user->id, 'reason' => $data['reason']]);
        ActivityLog::create(['user_id' => $user->id, 'action' => 'operation_edit_requested', 'subject_type' => Operation::class, 'subject_id' => $operation->id, 'details' => ['reason' => $data['reason']], 'ip_address' => $request->ip()]);
        return $this->resource(['request' => $editRequest], 201);
    }

    public function editRequests(Request $request)
    {
        $admin = $this->authOrFail($request); abort_unless($admin->role === 'admin', 403, 'Réservé à l’administrateur.');
        $items = OperationEditRequest::with(['operation.shop:id,name', 'requester:id,name'])->whereHas('operation.shop', fn ($query) => $query->where('owner_id', $admin->id))->latest()->limit(200)->get();
        return $this->resource(['requests' => $items]);
    }

    public function reviewEditRequest(Request $request, OperationEditRequest $editRequest)
    {
        $admin = $this->authOrFail($request);
        abort_unless($admin->role === 'admin' && $editRequest->operation?->shop?->owner_id === $admin->id, 403, 'Décision non autorisée.');
        abort_unless($editRequest->status === 'pending', 422, 'Cette demande a déjà été traitée.');
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'review_note' => 'nullable|string|max:1000']);
        $editRequest->update(['status' => $data['decision'], 'review_note' => $data['review_note'] ?? null, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
        ActivityLog::create(['user_id' => $admin->id, 'action' => $data['decision'] === 'approved' ? 'operation_edit_approved' : 'operation_edit_rejected', 'subject_type' => Operation::class, 'subject_id' => $editRequest->operation_id, 'details' => ['reason' => $editRequest->reason, 'review_note' => $data['review_note'] ?? null], 'ip_address' => $request->ip()]);
        return $this->resource(['request' => $editRequest->fresh()]);
    }

    public function summaryFor(Shop $shop, string $date): array
    {
        $operations = $shop->operations()->whereDate('occurred_at', $date)->get();
        $services = collect(self::SERVICES)->mapWithKeys(function ($service) use ($operations, $shop) {
            $items = $operations->where('service', $service);
            if ($service === 'other') {
                $entries = (float) $items->where('direction', 'in')->sum('amount');
                $outputs = (float) $items->where('direction', 'out')->sum('amount');
            } else {
                $entries = (float) $items->where('direction', 'out')->sum('amount') + (float) $operations->where('type', 'virtual_credit_purchase')->where('network', $service)->sum('amount');
                $outputs = (float) $items->where('direction', 'in')->sum('amount');
            }
            $balance = $service === 'other' ? $entries - $outputs : (float) $shop->{$service.'_virtual_balance'};
            return [$service => ['entries' => round($entries, 2), 'outputs' => round($outputs, 2), 'balance' => round($balance, 2), 'count' => $items->count(), 'balance_kind' => $service === 'other' ? 'cash' : 'virtual']];
        });
        return [
            'date' => $date,
            'services' => $services,
            'total_in' => round((float) $operations->where('direction', 'in')->sum('amount'), 2),
            'total_out' => round((float) $operations->where('direction', 'out')->sum('amount'), 2),
            'balance' => round((float) $operations->where('direction', 'in')->sum('amount') - (float) $operations->where('direction', 'out')->sum('amount'), 2),
            'count' => $operations->count(),
        ];
    }

    private function validated(Request $request, bool $creating = true): array
    {
        $data = $request->validate([
            'client_uuid' => ($creating ? 'required' : 'sometimes').'|uuid',
            'service' => 'required|in:'.implode(',', self::SERVICES),
            'direction' => 'required|in:in,out',
            'type' => 'required|in:'.implode(',', self::TYPES),
            'amount' => 'required|numeric|gt:0',
            'phone' => 'nullable|digits:10',
            'network' => 'nullable|in:moov_credit,flooz,momo,mtn_credit,celtiis',
            'description' => 'nullable|string|max:1000',
            'occurred_at' => 'required|date',
        ]);
        if ($data['service'] === 'other') {
            $allowed = in_array($data['type'], ['expense', 'virtual_credit_purchase', 'debt', 'debt_repayment'], true);
            $expectedDirection = $data['type'] === 'debt_repayment' ? 'in' : 'out';
            abort_unless($allowed && $data['direction'] === $expectedDirection, 422, 'La nature et le sens de cette opération Autres sont invalides.');
            if ($data['type'] === 'virtual_credit_purchase') abort_unless(filled($data['network'] ?? null), 422, 'Le réseau est obligatoire pour un achat de crédit.');
            if ($data['type'] === 'expense') abort_unless(filled($data['description'] ?? null), 422, 'La raison de la dépense est obligatoire.');
            if ($data['type'] === 'debt') abort_unless(filled($data['description'] ?? null), 422, 'Le motif de la dette est obligatoire.');
            if ($data['type'] === 'debt_repayment') abort_unless(filled($data['description'] ?? null), 422, 'Le motif du remboursement est obligatoire.');
        } else {
            abort_unless(filled($data['phone'] ?? null), 422, 'Le numéro à 10 chiffres est obligatoire.');
            abort_unless(filled($data['description'] ?? null), 422, 'Le détail de l’opération est obligatoire.');
        }
        return $data;
    }

    private function ensureAccess($user, Shop $shop): void
    {
        abort_unless(($user->role === 'admin' && $shop->owner_id === $user->id) || $user->employee?->shop_id === $shop->id, 403, 'Accès non autorisé à ce point de vente.');
    }

    private function log(Request $request, int $userId, Operation $operation, string $action, array $extra = []): void
    {
        ActivityLog::create(['user_id' => $userId, 'action' => $action, 'subject_type' => Operation::class, 'subject_id' => $operation->id, 'details' => [...$extra, 'operation' => $operation->toArray()], 'ip_address' => $request->ip()]);
    }

    private function recalculateVirtualBalances(Shop $shop): void
    {
        $services = array_values(array_filter(self::SERVICES, fn ($service) => $service !== 'other'));
        $balances = collect($services)->mapWithKeys(fn ($service) => [$service => (float) $shop->{$service.'_initial_balance'}])->all();
        foreach ($shop->operations()->orderBy('occurred_at')->orderBy('id')->get() as $operation) {
            $virtualService = $operation->type === 'virtual_credit_purchase' ? $operation->network : (in_array($operation->service, $services, true) ? $operation->service : null);
            if (! $virtualService || ! array_key_exists($virtualService, $balances)) { $operation->updateQuietly(['virtual_balance_after' => null]); continue; }
            $delta = $operation->type === 'virtual_credit_purchase' ? (float) $operation->amount : ($operation->direction === 'in' ? -(float) $operation->amount : (float) $operation->amount);
            $balances[$virtualService] = round($balances[$virtualService] + $delta, 2);
            $operation->updateQuietly(['virtual_balance_after' => $balances[$virtualService]]);
        }
        $shop->updateQuietly(collect($balances)->mapWithKeys(fn ($balance, $service) => [$service.'_virtual_balance' => $balance])->all());
    }

    private function assertFundsAvailable(Shop $shop, array $data, ?Operation $replaced = null): void
    {
        $cash = (float) $shop->operations()->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) balance")->value('balance');
        if ($replaced) $cash -= $replaced->direction === 'in' ? (float) $replaced->amount : -(float) $replaced->amount;
        if ($data['direction'] === 'out' && (float) $data['amount'] > $cash + 0.001) {
            abort(422, 'Solde espèces insuffisant. La caisse contient actuellement '.number_format(max(0, $cash), 0, ',', ' ').' FCFA.');
        }
        $services = array_values(array_filter(self::SERVICES, fn ($service) => $service !== 'other'));
        $newService = $data['type'] === 'virtual_credit_purchase' ? ($data['network'] ?? null) : (in_array($data['service'], $services, true) ? $data['service'] : null);
        if (! $newService || $data['type'] === 'virtual_credit_purchase' || $data['direction'] !== 'in') return;
        $available = (float) $shop->{$newService.'_virtual_balance'};
        if ($replaced) {
            $oldService = $replaced->type === 'virtual_credit_purchase' ? $replaced->network : (in_array($replaced->service, $services, true) ? $replaced->service : null);
            if ($oldService === $newService) $available -= $this->virtualDelta($replaced);
        }
        abort_if((float) $data['amount'] > $available + 0.001, 422, 'Solde virtuel insuffisant pour ce réseau. Il reste '.number_format(max(0, $available), 0, ',', ' ').' FCFA.');
    }

    private function assertDeletionAllowed(Shop $shop, Operation $operation): void
    {
        $cash = (float) $shop->operations()->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) balance")->value('balance');
        $cashAfter = $cash - ($operation->direction === 'in' ? (float) $operation->amount : -(float) $operation->amount);
        abort_if($cashAfter < -0.001, 422, 'Suppression impossible : elle rendrait le solde espèces négatif.');
        $services = array_values(array_filter(self::SERVICES, fn ($service) => $service !== 'other'));
        $service = $operation->type === 'virtual_credit_purchase' ? $operation->network : (in_array($operation->service, $services, true) ? $operation->service : null);
        if ($service) abort_if((float) $shop->{$service.'_virtual_balance'} - $this->virtualDelta($operation) < -0.001, 422, 'Suppression impossible : elle rendrait le solde virtuel de ce réseau négatif.');
    }

    private function virtualDelta(Operation $operation): float
    {
        return $operation->type === 'virtual_credit_purchase' ? (float) $operation->amount : ($operation->direction === 'in' ? -(float) $operation->amount : (float) $operation->amount);
    }
}
