<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerMessageCampaign extends Model
{
    public const AUDIENCES = [
        'all' => 'همه مشتریان',
        'birthday_today' => 'تولد امروز',
        'birthday_month' => 'تولد این ماه',
        'profile_incomplete' => 'پروفایل ناقص',
        'profile_complete' => 'پروفایل کامل',
        'no_orders' => 'بدون سفارش',
        'has_orders' => 'دارای سفارش',
        'selected' => 'انتخاب تکی',
    ];

    protected $appends = ['audience_label'];

    protected $fillable = [
        'audience',
        'message',
        'recipients_count',
        'sent_count',
        'failed_count',
        'created_by',
    ];

    protected function audienceLabel(): Attribute
    {
        return Attribute::get(fn () => self::AUDIENCES[$this->audience] ?? $this->audience);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CustomerMessageLog::class, 'campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
