<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupportTicket extends Model
{
    public const STATUSES = [
        'open' => 'در انتظار پاسخ',
        'answered' => 'پاسخ داده شد',
        'closed' => 'بسته شده',
    ];

    protected $fillable = [
        'user_id',
        'subject',
        'status',
        'unread_by_admin',
        'unread_by_customer',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'unread_by_admin' => 'boolean',
            'unread_by_customer' => 'boolean',
            'last_message_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id')->orderBy('id');
    }

    public function lastMessage(): HasOne
    {
        return $this->hasOne(SupportMessage::class, 'ticket_id')->latestOfMany();
    }

    public function toApiArray(string $for = 'customer'): array
    {
        $unread = $for === 'admin' ? $this->unread_by_admin : $this->unread_by_customer;

        $data = [
            'id' => $this->id,
            'subject' => $this->subject,
            'status' => $this->status,
            'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'unread' => (bool) $unread,
            'last_message_at' => $this->last_message_at,
            'last_message' => $this->lastMessage?->body,
            'messages_count' => (int) ($this->messages_count ?? $this->messages()->count()),
            'created_at' => $this->created_at,
        ];

        if ($this->relationLoaded('user') && $this->user) {
            $data['user'] = [
                'id' => $this->user->id,
                'phone' => $this->user->phone,
                'first_name' => $this->user->first_name,
                'last_name' => $this->user->last_name,
                'name' => $this->user->name,
                'profile_complete' => $this->user->hasCompleteProfile(),
                'addresses_count' => $this->user->addresses_count,
            ];
        }

        if ($this->relationLoaded('messages')) {
            $data['messages'] = $this->messages->map(fn (SupportMessage $m) => [
                'id' => $m->id,
                'sender' => $m->sender,
                'body' => $m->body,
                'created_at' => $m->created_at,
            ])->values();
        }

        return $data;
    }
}
