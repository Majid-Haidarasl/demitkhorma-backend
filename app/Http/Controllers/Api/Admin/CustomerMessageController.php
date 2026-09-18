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
            'channel' => ['nullable', 'string', Rule::in(array_keys(CustomerMessageCampaign::CHANNELS))],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $channel = $data['channel'] ?? 'sms';
        $users = $messages->recipients($data['audience'], $data['user_ids'] ?? [], $channel);

        return response()->json([
            'data' => [
                'count' => $users->count(),
                'max' => CustomerMessageService::MAX_RECIPIENTS,
                'channel' => $channel,
                'preview' => $users->take(40)->map(fn ($u) => [
                    'id' => $u->id,
                    'phone' => $u->phone,
                    'email' => $u->email,
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
        $channel = $request->input('channel', 'sms') === 'email' ? 'email' : 'sms';
        $data = $request->validate([
            'audience' => ['required', 'string', Rule::in(array_keys(CustomerMessageCampaign::AUDIENCES))],
            'channel' => ['required', 'string', Rule::in(array_keys(CustomerMessageCampaign::CHANNELS))],
            'subject' => ['nullable', 'string', 'max:120'],
            'message' => ['required', 'string', 'min:5', 'max:'.($channel === 'email' ? '2000' : '700')],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        set_time_limit(180);

        $campaign = $messages->send(
            $data['audience'],
            $data['message'],
            $data['user_ids'] ?? [],
            $request->user()?->id,
            $data['channel'],
            $data['subject'] ?? null,
        );

        return response()->json(['data' => $campaign], 201);
    }

    public function history(Request $request): JsonResponse
    {
        $rows = CustomerMessageCampaign::query()
            ->with('creator:id,username,name')
            ->latest()
            ->paginate($request->safePerPage(15));

        return response()->json($rows);
    }
}
