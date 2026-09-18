<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $customers = User::query()
            ->where('role', 'customer')
            ->withCount(['orders', 'addresses'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($inner) use ($s) {
                    $inner->where('phone', 'like', "%{$s}%")
                        ->orWhere('email', 'like', "%{$s}%")
                        ->orWhere('name', 'like', "%{$s}%")
                        ->orWhere('first_name', 'like', "%{$s}%")
                        ->orWhere('last_name', 'like', "%{$s}%");
                });
            })
            ->latest()
            ->paginate($request->safePerPage( 20));

        return response()->json($customers);
    }

    public function show(User $user): JsonResponse
    {
        if ($user->role !== 'customer') {
            abort(404);
        }

        return response()->json([
            'data' => $user->loadCount('addresses')->load(['orders' => fn ($q) => $q->latest()->limit(20)]),
        ]);
    }
}
