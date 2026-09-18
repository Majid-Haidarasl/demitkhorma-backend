<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tickets = SupportTicket::query()
            ->with([
                'lastMessage',
                'user' => fn ($q) => $q->withCount('addresses'),
            ])
            ->withCount('messages')
            ->when($request->boolean('unread'), fn ($q) => $q->where('unread_by_admin', true))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($inner) use ($s) {
                    $inner->where('subject', 'like', "%{$s}%")
                        ->orWhereHas('user', function ($user) use ($s) {
                            $user->where('phone', 'like', "%{$s}%")
                                ->orWhere('first_name', 'like', "%{$s}%")
                                ->orWhere('last_name', 'like', "%{$s}%")
                                ->orWhere('name', 'like', "%{$s}%");
                        });
                });
            })
            ->orderByDesc('unread_by_admin')
            ->orderByDesc('last_message_at')
            ->paginate($request->safePerPage( 20));

        $tickets->getCollection()->transform(fn (SupportTicket $t) => $t->toApiArray('admin'));

        return response()->json($tickets);
    }

    public function show(SupportTicket $ticket, SupportTicketService $support): JsonResponse
    {
        $support->markRead($ticket, 'admin');
        $ticket->refresh()
            ->load(['messages', 'lastMessage', 'user' => fn ($q) => $q->withCount('addresses')])
            ->loadCount('messages');

        return response()->json(['data' => $ticket->toApiArray('admin')]);
    }

    public function reply(Request $request, SupportTicket $ticket, SupportTicketService $support): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        $ticket = $support->reply($ticket, $request->user(), 'admin', $data['message']);
        $ticket->load(['user' => fn ($q) => $q->withCount('addresses')])->loadCount('messages');

        return response()->json(['data' => $ticket->toApiArray('admin')]);
    }

    public function update(Request $request, SupportTicket $ticket, SupportTicketService $support): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(array_keys(SupportTicket::STATUSES))],
        ]);

        $ticket = $support->setStatus($ticket, $data['status']);
        $ticket->load(['messages', 'lastMessage', 'user' => fn ($q) => $q->withCount('addresses')])->loadCount('messages');

        return response()->json(['data' => $ticket->toApiArray('admin')]);
    }
}
