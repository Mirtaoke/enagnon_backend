<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Http\Request;

class AttendanceController extends ApiController
{
    public function index(Request $request)
    {
        $admin = $this->authOrFail($request);
        abort_unless($admin->role === 'admin', 403, 'Réservé à l’administrateur.');
        $shopIds = $admin->shops()->pluck('id');
        if ($request->input('date') === 'today') {
            $employees = Employee::with('shop:id,name')->whereIn('shop_id', $shopIds)->where('is_active', true)->get();
            $today = Attendance::whereIn('employee_id', $employees->pluck('id'))->whereDate('date', today())->get()->keyBy('employee_id');
            $attendances = $employees->map(function ($employee) use ($today) {
                $attendance = $today->get($employee->id);
                return [
                    'id' => $attendance?->id, 'date' => today()->toDateString(),
                    'arrival_at' => $attendance?->arrival_at, 'departure_at' => $attendance?->departure_at,
                    'employee' => $employee->only(['id', 'name', 'role']),
                    'shop' => $employee->shop?->only(['id', 'name']),
                ];
            });
        } else {
            $attendances = Attendance::with(['employee:id,name,role', 'shop:id,name'])
                ->whereIn('shop_id', $shopIds)->latest('date')->latest('arrival_at')->limit(200)->get();
        }
        return $this->resource(['attendances' => $attendances]);
    }

    public function today(Request $request)
    {
        $user = $this->authOrFail($request);
        abort_unless($user->employee, 403, 'Aucun profil employé associé.');
        $attendance = $this->todayAttendance($user->employee);
        return $this->resource(['attendance' => $attendance]);
    }

    public function checkIn(Request $request)
    {
        $user = $this->authOrFail($request);
        abort_unless($user->employee, 403);
        $attendance = $this->todayAttendance($user->employee);
        abort_if($attendance->arrival_at, 422, 'L’arrivée a déjà été enregistrée.');
        $attendance->update(['arrival_at' => now()]);
        $this->log($request, $user->id, $attendance, 'check_in');
        return $this->resource(['attendance' => $attendance->fresh()]);
    }

    public function checkOut(Request $request)
    {
        $user = $this->authOrFail($request);
        abort_unless($user->employee, 403);
        $attendance = Attendance::where('employee_id', $user->employee->id)->whereDate('date', today())->firstOrFail();
        abort_unless($attendance->arrival_at, 422, 'Enregistre d’abord ton heure d’arrivée.');
        abort_if($attendance->departure_at, 422, 'Le départ a déjà été enregistré.');
        $attendance->update(['departure_at' => now()]);
        $this->log($request, $user->id, $attendance, 'check_out');
        return $this->resource(['attendance' => $attendance->fresh()]);
    }

    private function log(Request $request, int $userId, Attendance $attendance, string $action): void
    {
        ActivityLog::create(['user_id' => $userId, 'action' => $action, 'subject_type' => Attendance::class, 'subject_id' => $attendance->id, 'details' => $attendance->toArray(), 'ip_address' => $request->ip()]);
    }

    private function todayAttendance(Employee $employee): Attendance
    {
        return Attendance::where('employee_id', $employee->id)
            ->whereDate('date', today())
            ->first() ?? Attendance::create([
                'employee_id' => $employee->id,
                'shop_id' => $employee->shop_id,
                'date' => today()->toDateString(),
            ]);
    }
}
