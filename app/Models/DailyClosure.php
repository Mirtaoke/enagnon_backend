<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['shop_id', 'employee_id', 'created_by', 'validated_by', 'date', 'status', 'cash', 'moov_credit', 'flooz', 'momo', 'mtn_credit', 'celtiis', 'expenses', 'virtual_credit_purchase', 'debts', 'expected_total', 'actual_total', 'difference', 'difference_reason', 'closed_at', 'submitted_at'])]
class DailyClosure extends Model
{
    use HasFactory;

    protected $casts = [
        'date' => 'date',
        'closed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'expenses' => 'array',
        'debts' => 'array',
        'cash' => 'float',
        'moov_credit' => 'float',
        'flooz' => 'float',
        'momo' => 'float',
        'mtn_credit' => 'float',
        'celtiis' => 'float',
        'virtual_credit_purchase' => 'float',
        'expected_total' => 'float',
        'actual_total' => 'float',
        'difference' => 'float',
    ];

    public function validator() { return $this->belongsTo(User::class, 'validated_by'); }
}
