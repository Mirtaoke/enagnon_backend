<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'description', 'address', 'manager_name', 'phone', 'is_active', 'currency', 'owner_id'])]
class Shop extends Model
{
    use HasFactory;

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
