<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use function Laravel\Prompts\table;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('label_certification_purchases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('label_profile_id')->constrained('label_profiles')->cascadeOnDelete();

            $table->string('reference')->unique();
            $table->unsignedBigInteger('amount_kobo');
            $table->string('status')->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->string('provider_transaction_id')->nullable()->unique();


            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('label_certification_purchases');
    }
};
