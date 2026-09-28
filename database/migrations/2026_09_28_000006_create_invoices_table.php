<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->char('currency', 3);
            $table->unsignedBigInteger('total');               // minor units
            $table->string('status', 16);
            $table->timestamp('issued_at');
            $table->timestamps();

            // One invoice per subscription per cycle: the database is the final
            // guard against a job running twice.
            $table->unique(['subscription_id', 'period_start']);
            $table->index(['merchant_id', 'period_start']);
            $table->index(['customer_id', 'period_start']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);                        // base | overage
            $table->string('description');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('quantity');
            $table->decimal('unit_price', 18, 6);
            $table->unsignedBigInteger('amount');              // minor units
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
