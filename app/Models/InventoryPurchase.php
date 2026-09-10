<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryPurchase extends Model
{
    protected $fillable = [
        'product_id',
        'product_variant_id',
        'qty',
        'unit_cost',
        'total_cost',
        'supplier',
        'purchased_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'purchased_at' => 'date',
            'qty' => 'integer',
            'unit_cost' => 'integer',
            'total_cost' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
