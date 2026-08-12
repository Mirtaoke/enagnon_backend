<?php

namespace App\Http\Controllers\Api;

use App\Models\Chat;
use App\Models\Message;
use App\Models\Shop;
use Illuminate\Http\Request;

class ChatController extends ApiController
{
    public function index(Request $request, Shop $shop)
    {
        $this->ensureAccess($this->authOrFail($request), $shop);

        $chats = $shop->chats()->withCount('messages')->get();

        if ($chats->isEmpty()) {
            $chats[] = Chat::create([
                'shop_id' => $shop->id,
                'subject' => 'Forum de la boutique « ' . $shop->name . ' »',
            ]);
        }

        return $this->resource(['chats' => $chats]);
    }

    public function messages(Request $request, Chat $chat)
    {
        $this->ensureAccess($this->authOrFail($request), $chat->shop);

        return $this->resource(['messages' => $chat->messages()->orderBy('created_at', 'desc')->get()]);
    }

    public function sendMessage(Request $request, Chat $chat)
    {
        $this->ensureAccess($this->authOrFail($request), $chat->shop);

        $data = $request->validate([
            'sender_name' => 'required|string|max:255',
            'body' => 'required|string',
        ]);

        $message = Message::create([
            'chat_id' => $chat->id,
            'sender_name' => $data['sender_name'],
            'receiver_name' => null,
            'body' => $data['body'],
        ]);

        return $this->resource(['message' => $message], 201);
    }

    private function ensureAccess($user, Shop $shop): void
    {
        abort_unless(
            ($user->role === 'admin' && $shop->owner_id === $user->id)
                || $user->employee?->shop_id === $shop->id,
            403,
            'Accès non autorisé à ce forum.',
        );
    }
}
