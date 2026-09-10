<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $fillable = ['product_id', 'weight_grams', 'price', 'sku', 'stock', 'avg_cost'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
