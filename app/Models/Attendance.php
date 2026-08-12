<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['employee_id', 'shop_id', 'date', 'arrival_at', 'departure_at'])]
class Attendance extends Model
{
    protected $casts = ['date' => 'date', 'arrival_at' => 'datetime', 'departure_at' => 'datetime'];

    public function employee() { return $this->belongsTo(Employee::class); }
    public function shop() { return $this->belongsTo(Shop::class); }
}
