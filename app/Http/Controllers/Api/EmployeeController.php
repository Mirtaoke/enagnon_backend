<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmployeeController extends ApiController
{
    public function store(Request $request, Shop $shop)
    {
        $admin = $this->authOrFail($request);
        abort_unless($admin->role === 'admin' && $shop->owner_id === $admin->id, 403, 'Réservé à l’administrateur.');

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:80|unique:users,username',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|digits:10',
            'role' => 'required|in:seller,manager',
            'password' => 'required|string|min:6',
        ]);
        $password = $data['password'] ?? 'password';

        $employee = DB::transaction(function () use ($data, $password, $shop, $admin, $request) {
            $user = User::create([
                'name' => $data['name'], 'username' => $data['username'],
                'email' => $data['email'], 'role' => $data['role'], 'password' => $password,
            ]);
            $employee = Employee::create([
                'user_id' => $user->id, 'shop_id' => $shop->id, 'name' => $data['name'],
                'email' => $data['email'], 'phone' => $data['phone'] ?? null,
                'role' => $data['role'] === 'manager' ? 'Responsable' : 'Vendeur',
            ]);
            ActivityLog::create([
                'user_id' => $admin->id, 'action' => 'created', 'subject_type' => Employee::class,
                'subject_id' => $employee->id, 'details' => ['email' => $data['email'], 'role' => $data['role']],
                'ip_address' => $request->ip(),
            ]);
            return $employee->load('user');
        });

        return $this->resource([
            'employee' => $employee,
            'credentials' => ['login' => $data['username'], 'password' => $password],
        ], 201);
    }

    public function update(Request $request, Shop $shop, Employee $employee)
    {
        $admin = $this->authOrFail($request);
        abort_unless($admin->role === 'admin' && $shop->owner_id === $admin->id && $employee->shop_id === $shop->id, 403, 'Réservé à l’administrateur.');
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,'.$employee->user_id,
            'phone' => 'required|digits:10',
            'role' => 'required|in:seller,manager',
        ]);
        $before = $employee->toArray();
        DB::transaction(function () use ($employee, $data) {
            $employee->update([
                'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'],
                'role' => $data['role'] === 'manager' ? 'Responsable' : 'Vendeur / agent',
            ]);
            $employee->user?->update(['name' => $data['name'], 'email' => $data['email'], 'role' => $data['role']]);
        });
        ActivityLog::create(['user_id' => $admin->id, 'action' => 'employee_updated', 'subject_type' => Employee::class, 'subject_id' => $employee->id, 'details' => ['before' => $before, 'after' => $data], 'ip_address' => $request->ip()]);
        return $this->resource(['employee' => $employee->fresh()->load('user')]);
    }

    public function destroy(Request $request, Shop $shop, Employee $employee)
    {
        $admin = $this->authOrFail($request);
        abort_unless($admin->role === 'admin' && $shop->owner_id === $admin->id && $employee->shop_id === $shop->id, 403, 'Réservé à l’administrateur.');
        DB::transaction(function () use ($employee, $admin, $request) {
            ActivityLog::create(['user_id' => $admin->id, 'action' => 'employee_deleted', 'subject_type' => Employee::class, 'subject_id' => $employee->id, 'details' => ['name' => $employee->name], 'ip_address' => $request->ip()]);
            $employee->update(['is_active' => false]);
            $employee->user?->forceFill(['role' => 'disabled', 'api_token' => null])->save();
        });
        return response()->noContent();
    }
}
