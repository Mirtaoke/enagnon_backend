<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description', 'address', 'manager_name', 'phone', 'is_active', 'currency', 'owner_id', 'moov_credit_initial_balance', 'flooz_initial_balance', 'momo_initial_balance', 'mtn_credit_initial_balance', 'celtiis_initial_balance', 'moov_credit_virtual_balance', 'flooz_virtual_balance', 'momo_virtual_balance', 'mtn_credit_virtual_balance', 'celtiis_virtual_balance'])]
class Shop extends Model
{
    use HasFactory;
    protected $casts = ['is_active' => 'boolean', 'moov_credit_initial_balance' => 'float', 'flooz_initial_balance' => 'float', 'momo_initial_balance' => 'float', 'mtn_credit_initial_balance' => 'float', 'celtiis_initial_balance' => 'float', 'moov_credit_virtual_balance' => 'float', 'flooz_virtual_balance' => 'float', 'momo_virtual_balance' => 'float', 'mtn_credit_virtual_balance' => 'float', 'celtiis_virtual_balance' => 'float'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function dailyClosures(): HasMany
    {
        return $this->hasMany(DailyClosure::class);
    }

    public function operations(): HasMany
    {
        return $this->hasMany(Operation::class);
    }
}
