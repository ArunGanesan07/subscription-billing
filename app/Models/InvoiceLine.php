<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\InvoiceLineType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => InvoiceLineType::class,
            'period_start' => DateOnly::class,
            'period_end' => DateOnly::class,
            'quantity' => 'integer',
            'unit_price' => 'decimal:6',
            'amount' => 'integer',
            'meta' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
