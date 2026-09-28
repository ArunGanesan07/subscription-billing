<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyUsage extends Model
{
    protected $table = 'daily_usage';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'usage_date' => DateOnly::class,
            'units' => 'integer',
            'event_count' => 'integer',
            'aggregated_at' => 'immutable_datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
