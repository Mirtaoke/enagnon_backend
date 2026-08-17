<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['operation_id', 'requested_by', 'reviewed_by', 'reason', 'status', 'review_note', 'reviewed_at', 'used_at'])]
class OperationEditRequest extends Model
{
    protected $casts = ['reviewed_at' => 'datetime', 'used_at' => 'datetime'];
    public function operation(): BelongsTo { return $this->belongsTo(Operation::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
