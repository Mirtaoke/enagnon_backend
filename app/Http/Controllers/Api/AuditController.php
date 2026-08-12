<?php

namespace App\Http\Controllers\Api;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class AuditController extends ApiController
{
    public function index(Request $request)
    {
        $user = $this->authOrFail($request);
        abort_unless($user->role === 'admin', 403, 'Réservé à l’administrateur.');
        $logs = ActivityLog::with('user:id,name,role')
            ->latest()->paginate(min((int) $request->input('per_page', 50), 100));
        return $this->resource(['logs' => $logs->items(), 'next_page' => $logs->nextPageUrl()]);
    }
}
