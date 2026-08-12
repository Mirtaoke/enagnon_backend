<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    protected function currentUser(Request $request): ?User
    {
        $token = $request->bearerToken();

        if (!$token) {
            return null;
        }

        return User::where('api_token', $token)->first();
    }

    protected function authOrFail(Request $request): User
    {
        $user = $this->currentUser($request);

        if (!$user) {
            abort(response()->json(['message' => 'Token manquant ou invalide'], 401));
        }

        return $user;
    }

    protected function resource(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status);
    }
}
