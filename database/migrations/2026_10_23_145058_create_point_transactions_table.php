<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artist_profile_id')
                ->constrained('artist_profiles')
                ->cascadeOnDelete();

            $table->string('type');
            $table->unsignedInteger('points');
            $table->unsignedInteger('balance_after');

            $table->string('payment_reference')->nullable()->unique();

            $table->foreignId('label_application_id')
                ->nullable()
                ->constrained('label_applications')
                ->nullOnDelete();

            $table->string('description')->nullable();

            $table->timestamps();

            $table->index(['artist_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_transactions');
    }
};
