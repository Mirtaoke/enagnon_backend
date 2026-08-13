<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class AuthController extends ApiController
{
    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => 'required|email|exists:users,email']);
        $code = (string) random_int(100000, 999999);
        DB::table('password_reset_codes')->where('email', $data['email'])->whereNull('used_at')->delete();
        DB::table('password_reset_codes')->insert([
            'email' => $data['email'], 'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(15), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Mail::raw("Votre code ENAGNON LEADER est : {$code}. Il expire dans 15 minutes.", function ($message) use ($data) {
            $message->to($data['email'])->subject('Code de récupération ENAGNON LEADER');
        });
        return $this->resource(['message' => 'Un code de récupération a été envoyé à votre adresse email.']);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|exists:users,email',
            'code' => 'required|digits:6',
            'password' => 'required|string|min:6|confirmed',
        ]);
        $reset = DB::table('password_reset_codes')->where('email', $data['email'])
            ->whereNull('used_at')->where('expires_at', '>', now())->latest('id')->first();
        if (! $reset || ! Hash::check($data['code'], $reset->code)) {
            return response()->json(['message' => 'Code invalide ou expiré.'], 422);
        }
        User::where('email', $data['email'])->firstOrFail()->update(['password' => $data['password'], 'api_token' => null]);
        DB::table('password_reset_codes')->where('id', $reset->id)->update(['used_at' => now(), 'updated_at' => now()]);
        return $this->resource(['message' => 'Mot de passe réinitialisé avec succès.']);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'login' => 'nullable|string|required_without:email',
            'email' => 'nullable|email|required_without:login',
            'password' => 'required|string|min:4',
        ]);

        $login = $credentials['login'] ?? $credentials['email'];
        $user = User::where('email', $login)->orWhere('username', $login)->first();

        if (!$user || $user->role === 'disabled' || !Hash::check($credentials['password'], $user->password)) {
            return response()->json(['message' => 'Identifiants invalides'], 401);
        }

        $user->forceFill(['api_token' => Str::random(60), 'last_login_at' => now()])->save();
        ActivityLog::create(['user_id' => $user->id, 'action' => 'login', 'subject_type' => User::class, 'subject_id' => $user->id, 'details' => ['user_agent' => $request->userAgent()], 'ip_address' => $request->ip()]);

        return $this->resource([
            'user' => $user->load('employee.shop'),
            'token' => $user->api_token,
        ]);
    }

    public function me(Request $request)
    {
        $user = $this->authOrFail($request);

        return $this->resource(['user' => $user->load('employee.shop')]);
    }

    public function logout(Request $request)
    {
        $user = $this->authOrFail($request);
        ActivityLog::create(['user_id' => $user->id, 'action' => 'logout', 'subject_type' => User::class, 'subject_id' => $user->id, 'details' => ['user_agent' => $request->userAgent()], 'ip_address' => $request->ip()]);
        $user->forceFill(['api_token' => null])->save();
        return response()->noContent();
    }

    public function updateProfile(Request $request)
    {
        $user = $this->authOrFail($request);
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:80|unique:users,username,'.$user->id,
            'email' => 'required|email|unique:users,email,'.$user->id,
        ]);
        $before = $user->only(['name', 'username', 'email']);
        $user->update($data);
        if ($user->employee) {
            $user->employee->update(['name' => $data['name'], 'email' => $data['email']]);
        }
        ActivityLog::create(['user_id' => $user->id, 'action' => 'profile_updated', 'subject_type' => User::class, 'subject_id' => $user->id, 'details' => ['before' => $before, 'after' => $data], 'ip_address' => $request->ip()]);
        return $this->resource(['user' => $user->fresh()->load('employee.shop')]);
    }

    public function updatePassword(Request $request)
    {
        $user = $this->authOrFail($request);
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);
        if (!Hash::check($data['current_password'], $user->password)) {
            return response()->json(['message' => 'Le mot de passe actuel est incorrect.'], 422);
        }
        $user->update(['password' => $data['password']]);
        return $this->resource(['message' => 'Mot de passe modifié avec succès.']);
    }
}
