<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Services\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->customer($request);

        $tickets = SupportTicket::query()
            ->where('user_id', $user->id)
            ->with('lastMessage')
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        $tickets->getCollection()->transform(fn (SupportTicket $t) => $t->toApiArray('customer'));

        return response()->json($tickets);
    }

    public function store(Request $request, SupportTicketService $support): JsonResponse
    {
        $user = $this->customer($request);
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:3', 'max:160'],
            'message' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $ticket = $support->create($user, $data['subject'], $data['message']);

        return response()->json(['data' => $ticket->loadCount('messages')->toApiArray('customer')], 201);
    }

    public function show(Request $request, SupportTicket $ticket, SupportTicketService $support): JsonResponse
    {
        $this->ownTicket($request, $ticket);
        $support->markRead($ticket, 'customer');
        $ticket->refresh()->load(['messages', 'lastMessage'])->loadCount('messages');

        return response()->json(['data' => $ticket->toApiArray('customer')]);
    }

    public function reply(Request $request, SupportTicket $ticket, SupportTicketService $support): JsonResponse
    {
        $user = $this->ownTicket($request, $ticket);
        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        $ticket = $support->reply($ticket, $user, 'customer', $data['message']);
        $ticket->loadCount('messages');

        return response()->json(['data' => $ticket->toApiArray('customer')]);
    }

    public function close(Request $request, SupportTicket $ticket, SupportTicketService $support): JsonResponse
    {
        $this->ownTicket($request, $ticket);
        $ticket = $support->setStatus($ticket, 'closed');
        $ticket->load(['messages', 'lastMessage'])->loadCount('messages');

        return response()->json(['data' => $ticket->toApiArray('customer')]);
    }

    private function customer(Request $request)
    {
        $user = $request->user();
        if (! $user || $user->role !== 'customer') {
            abort(403, 'فقط مشتریان می‌توانند از پشتیبانی استفاده کنند.');
        }

        return $user;
    }

    private function ownTicket(Request $request, SupportTicket $ticket)
    {
        $user = $this->customer($request);
        if ((int) $ticket->user_id !== (int) $user->id) {
            abort(404);
        }

        return $user;
    }
}
