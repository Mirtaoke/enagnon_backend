<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\Operation;
use App\Models\Shop;
use Illuminate\Http\Request;

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
        $query = $shop->operations()->with('user:id,name')->latest('occurred_at');
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
        $operation = Operation::firstOrCreate(
            ['client_uuid' => $data['client_uuid']],
            [...$data, 'shop_id' => $shop->id, 'user_id' => $user->id, 'employee_id' => $user->employee?->id],
        );
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
        $before = $operation->toArray();
        $operation->update($this->validated($request, false));
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
        $operation->delete();
        ActivityLog::create(['user_id' => $user->id, 'action' => 'operation_deleted', 'subject_type' => Operation::class, 'subject_id' => $id, 'details' => $details, 'ip_address' => $request->ip()]);
        return response()->noContent();
    }

    public function summaryFor(Shop $shop, string $date): array
    {
        $operations = $shop->operations()->whereDate('occurred_at', $date)->get();
        $services = collect(self::SERVICES)->mapWithKeys(function ($service) use ($operations) {
            $items = $operations->where('service', $service);
            $entries = (float) $items->where('direction', 'in')->sum('amount');
            $outputs = (float) $items->where('direction', 'out')->sum('amount');
            return [$service => ['entries' => round($entries, 2), 'outputs' => round($outputs, 2), 'balance' => round($entries - $outputs, 2), 'count' => $items->count()]];
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
            'network' => 'nullable|in:MTN,MOOV,CELTIIS',
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
            if (in_array($data['service'], ['mtn_credit', 'moov_credit'], true)) {
                abort_unless($data['direction'] === 'in', 422, 'MTN crédit et Moov crédit acceptent uniquement les encaissements.');
            }
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
}
