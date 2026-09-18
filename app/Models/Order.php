<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'user_id', 'subtotal', 'shipping_cost', 'total', 'status',
        'payment_ref', 'zarinpal_authority', 'shipping_address', 'notes',
        'coupon_id', 'coupon_code', 'discount_amount', 'admin_notes',
    ];

    protected $hidden = [
        'admin_notes',
        'zarinpal_authority',
    ];

    protected $appends = ['cancellable'];

    public const CUSTOMER_CANCELABLE = ['pending', 'paid', 'processing'];

    public const ATTENTION_STATUSES = ['paid', 'processing', 'shipped'];

    protected function casts(): array
    {
        return ['shipping_address' => 'array'];
    }

    protected function cancellable(): Attribute
    {
        return Attribute::get(fn () => $this->canBeCancelledByCustomer());
    }

    public function canBeCancelledByCustomer(): bool
    {
        return in_array($this->status, self::CUSTOMER_CANCELABLE, true);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
