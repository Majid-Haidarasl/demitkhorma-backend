<?php

namespace App\Services;

use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupportTicketService
{
    public const MAX_OPEN = 15;

    public function create(User $user, string $subject, string $body): SupportTicket
    {
        $open = SupportTicket::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', 'closed')
            ->count();

        if ($open >= self::MAX_OPEN) {
            throw ValidationException::withMessages([
                'subject' => ['حداکثر '.self::MAX_OPEN.' درخواست باز مجاز است. یکی از درخواست‌های قبلی را ببندید.'],
            ]);
        }

        return DB::transaction(function () use ($user, $subject, $body) {
            $ticket = SupportTicket::create([
                'user_id' => $user->id,
                'subject' => $subject,
                'status' => 'open',
                'unread_by_admin' => true,
                'unread_by_customer' => false,
                'last_message_at' => now(),
            ]);

            $this->storeMessage($ticket, $user, 'customer', $body);

            ActivityLogger::log('support.ticket.created', $ticket, [
                'subject' => $subject,
            ]);

            return $ticket->fresh(['messages', 'lastMessage']);
        });
    }

    public function reply(SupportTicket $ticket, User $actor, string $sender, string $body): SupportTicket
    {
        if ($ticket->status === 'closed' && $sender === 'admin') {
            throw ValidationException::withMessages([
                'message' => ['این درخواست بسته شده است. ابتدا آن را باز کنید.'],
            ]);
        }

        $this->storeMessage($ticket, $actor, $sender, $body);

        $ticket->update([
            'status' => $sender === 'admin' ? 'answered' : 'open',
            'last_message_at' => now(),
            'unread_by_admin' => $sender === 'customer',
            'unread_by_customer' => $sender === 'admin',
        ]);

        ActivityLogger::log('support.ticket.replied', $ticket, [
            'sender' => $sender,
        ]);

        return $ticket->fresh(['messages', 'lastMessage', 'user']);
    }

    public function setStatus(SupportTicket $ticket, string $status): SupportTicket
    {
        if (! array_key_exists($status, SupportTicket::STATUSES)) {
            throw ValidationException::withMessages([
                'status' => ['وضعیت نامعتبر است.'],
            ]);
        }

        $ticket->update(['status' => $status]);

        ActivityLogger::log('support.ticket.status', $ticket, [
            'status' => $status,
        ]);

        return $ticket->fresh(['messages', 'lastMessage', 'user']);
    }

    public function markRead(SupportTicket $ticket, string $party): void
    {
        if ($party === 'admin' && $ticket->unread_by_admin) {
            $ticket->update(['unread_by_admin' => false]);
        }

        if ($party === 'customer' && $ticket->unread_by_customer) {
            $ticket->update(['unread_by_customer' => false]);
        }
    }

    private function storeMessage(SupportTicket $ticket, User $actor, string $sender, string $body): SupportMessage
    {
        return $ticket->messages()->create([
            'user_id' => $actor->id,
            'sender' => $sender,
            'body' => $body,
        ]);
    }
}
