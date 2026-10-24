<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('point_purchases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artist_profile_id')->constrained()->cascadeOnDelete();

            $table->string('reference')->unique();
            $table->string('package_code');
            $table->unsignedBigInteger('amount_kobo');
            $table->unsignedInteger('points');

            $table->string('status')->default('pending');

            $table->timestamp('paid_at')->nullable();

            $table->string('provider_transaction_id')->nullable()->unique();

            $table->timestamps();

            $table->index(['artist_profile_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('point_purchases');
    }
};
