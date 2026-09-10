<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMessageLog extends Model
{
    protected $fillable = [
        'campaign_id',
        'user_id',
        'phone',
        'status',
        'error',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CustomerMessageCampaign::class, 'campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
