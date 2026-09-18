<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = ActivityLog::with('user:id,username,name')
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where('action', 'like', "%{$s}%");
            })
            ->latest()
            ->paginate($request->safePerPage( 30));

        return response()->json($logs);
    }
}
