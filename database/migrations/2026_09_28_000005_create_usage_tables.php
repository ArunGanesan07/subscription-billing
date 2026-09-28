<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hot, append-only table. Kept deliberately narrow with the minimum set of
        // indexes; no foreign keys, since every FK is an extra lookup per insert and
        // ownership is already validated in the application before the write.
        // See README "Scaling usage_events to 50L+ rows".
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('idempotency_key', 64);
            $table->unsignedInteger('units');
            $table->date('usage_date');
            $table->timestamp('created_at')->nullable();

            // Idempotency: a retried request with the same key cannot insert twice.
            $table->unique(['merchant_id', 'idempotency_key']);
            // Aggregation: SUM(units) per customer per day. Including `units` makes
            // this a covering index, so the rollup never touches the table rows.
            $table->index(['customer_id', 'usage_date', 'units']);
        });

        // Daily rollup; everything downstream (billing, dashboard) reads this, never
        // the raw events. Rows are recomputed (not incremented) so re-runs are safe.
        Schema::create('daily_usage', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('merchant_id');
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->date('usage_date');
            $table->unsignedBigInteger('units');
            $table->unsignedInteger('event_count');
            $table->timestamp('aggregated_at');

            $table->unique(['customer_id', 'usage_date']);
            // Dashboard: per-merchant ranges grouped by customer (covering).
            $table->index(['merchant_id', 'usage_date', 'customer_id', 'units']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_usage');
        Schema::dropIfExists('usage_events');
    }
};
