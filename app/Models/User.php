<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    protected $fillable = ['name', 'first_name', 'last_name', 'username', 'password', 'phone', 'email', 'phone_verified_at', 'birth_date'];

    protected $hidden = ['password', 'remember_token'];

    protected $appends = ['has_password', 'addresses_count', 'profile_complete'];

    protected function casts(): array
    {
        return [
            'phone_verified_at' => 'datetime',
            'birth_date' => 'date:Y-m-d',
            'password' => 'hashed',
        ];
    }

    protected function hasPassword(): Attribute
    {
        return Attribute::get(fn () => ! empty($this->attributes['password'] ?? null));
    }

    protected function addressesCount(): Attribute
    {
        return Attribute::get(function () {
            if (array_key_exists('addresses_count', $this->attributes)) {
                return (int) $this->attributes['addresses_count'];
            }

            return $this->addresses()->count();
        });
    }

    protected function profileComplete(): Attribute
    {
        return Attribute::get(fn () => $this->hasCompleteProfile());
    }

    public function hasCompleteProfile(): bool
    {
        return filled($this->first_name)
            && filled($this->last_name)
            && $this->addresses_count > 0;
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function wishlistProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'wishlists')->withTimestamps();
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
