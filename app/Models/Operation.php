<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_uuid', 'shop_id', 'employee_id', 'user_id', 'service', 'direction', 'type', 'amount', 'phone', 'network', 'description', 'occurred_at'])]
class Operation extends Model
{
    protected $casts = ['amount' => 'float', 'occurred_at' => 'datetime'];

    public function shop(): BelongsTo { return $this->belongsTo(Shop::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
