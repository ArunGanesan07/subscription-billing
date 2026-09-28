<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            // The merchant's own identifier for this customer (optional).
            $table->string('external_id', 100)->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
