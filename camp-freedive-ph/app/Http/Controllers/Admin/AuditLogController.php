<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    /**
     * Display the in-app audit logs list (Owner only).
     */
    public function index(Request $request): View
    {
        $currentUser = Auth::user();

        $query = AuditLog::with('user')->latest('created_at');

        // Filter by action
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        // Filter by search query (actor, email, description, ip)
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('actor_name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        // Date range filter
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        $perPage = max(5, min(100, (int) $request->input('per_page', 15)));
        $logs = $query->paginate($perPage)->withQueryString();

        $actions = AuditLog::select('action')->distinct()->pluck('action');

        return view('admin.audit_logs.index', compact('logs', 'currentUser', 'actions'));
    }
}
