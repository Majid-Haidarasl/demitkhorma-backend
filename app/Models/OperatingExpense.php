<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatingExpense extends Model
{
    public const CATEGORIES = [
        'packaging' => 'بسته‌بندی',
        'advertising' => 'تبلیغات',
        'rent' => 'اجاره',
        'salary' => 'حقوق و دستمزد',
        'logistics' => 'حمل و ارسال',
        'utilities' => 'قبوض و جاری',
        'other' => 'سایر',
    ];

    protected $fillable = [
        'category',
        'title',
        'amount',
        'spent_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'spent_at' => 'date',
            'amount' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
