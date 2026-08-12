<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['chat_id', 'sender_name', 'receiver_name', 'body'])]
class Message extends Model
{
    use HasFactory;

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }
}
