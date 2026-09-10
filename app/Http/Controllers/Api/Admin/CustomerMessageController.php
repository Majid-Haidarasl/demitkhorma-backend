<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerMessageCampaign;
use App\Services\CustomerMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerMessageController extends Controller
{
    public function audiences(): JsonResponse
    {
        return response()->json([
            'data' => collect(CustomerMessageCampaign::AUDIENCES)->map(fn ($label, $key) => [
                'key' => $key,
                'label' => $label,
            ])->values(),
        ]);
    }

    public function recipients(Request $request, CustomerMessageService $messages): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', 'string', Rule::in(array_keys(CustomerMessageCampaign::AUDIENCES))],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $users = $messages->recipients($data['audience'], $data['user_ids'] ?? []);

        return response()->json([
            'data' => [
                'count' => $users->count(),
                'max' => CustomerMessageService::MAX_RECIPIENTS,
                'preview' => $users->take(40)->map(fn ($u) => [
                    'id' => $u->id,
                    'phone' => $u->phone,
                    'first_name' => $u->first_name,
                    'last_name' => $u->last_name,
                    'name' => $u->name,
                    'profile_complete' => $u->hasCompleteProfile(),
                    'addresses_count' => $u->addresses_count,
                    'birth_date' => $u->birth_date?->toDateString(),
                ])->values(),
            ],
        ]);
    }

    public function send(Request $request, CustomerMessageService $messages): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', 'string', Rule::in(array_keys(CustomerMessageCampaign::AUDIENCES))],
            'message' => ['required', 'string', 'min:5', 'max:700'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        set_time_limit(180);

        $campaign = $messages->send(
            $data['audience'],
            $data['message'],
            $data['user_ids'] ?? [],
            $request->user()?->id,
        );

        return response()->json(['data' => $campaign], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $rows = CustomerMessageCampaign::query()
            ->with('creator:id,username,name')
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return response()->json($rows);
    }
}
