<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['shop_id', 'date', 'total_in', 'total_out', 'cash_balance', 'note'])]
class Report extends Model
{
    use HasFactory;

    protected $casts = [
        'total_in' => 'float',
        'total_out' => 'float',
        'cash_balance' => 'float',
        'date' => 'date',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function movements(): HasMany
    {
        return $this->shop->movements()->whereDate('created_at', $this->date);
    }

    /**
     * Obtient les mouvements groupés par type avec détails
     */
    public function getDetailedMovements()
    {
        $movements = $this->shop->movements()->whereDate('created_at', $this->date)->get();

        return $movements->groupBy('type')->map(function ($items, $type) {
            return [
                'type' => $type,
                'items' => $items->map(function ($movement) {
                    return [
                        'id' => $movement->id,
                        'item' => $movement->item,
                        'quantity' => $movement->quantity,
                        'unit_price' => $movement->quantity > 0 ? round($movement->amount / $movement->quantity, 2) : 0,
                        'total_amount' => round($movement->amount, 2),
                        'employee' => $movement->employee?->name,
                        'note' => $movement->note,
                        'created_at' => $movement->created_at,
                    ];
                })->values(),
                'total_quantity' => $items->sum('quantity'),
                'total_amount' => round($items->sum('amount'), 2),
            ];
        })->values();
    }
}
