<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            // merchant_id is derivable via customer, but is kept here so tenant-scoped
            // queries (dashboard, billing runs) never need a join.
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            // Current plan (the plan of the latest segment); pricing lives on segments.
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('billing_interval', 16);
            $table->string('status', 16);
            $table->date('started_on');
            $table->date('ends_on')->nullable();
            // Last day covered by an issued invoice; drives "what is due next".
            $table->date('billed_through')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['merchant_id', 'status']);
        });

        // A subscription is a sequence of non-overlapping segments. Each segment
        // snapshots the plan's pricing at the time it started, so a mid-cycle plan
        // change bills each part of the cycle at the price that applied then, and
        // editing a plan never rewrites history.
        Schema::create('subscription_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();               // inclusive; null = open
            $table->unsignedBigInteger('base_price');
            $table->unsignedBigInteger('included_units');
            $table->decimal('overage_rate', 18, 6);
            $table->timestamps();

            $table->unique(['subscription_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_segments');
        Schema::dropIfExists('subscriptions');
    }
};
