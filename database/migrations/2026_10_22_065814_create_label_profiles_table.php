<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_profiles', function (Blueprint $table) {
            $table->id();

            // One label profile per user account.
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            // Public label information.
            $table->string('label_name')->unique();
            $table->text('bio')->nullable();
            $table->string('logo')->nullable();
            $table->json('preferred_genres')->nullable();
            $table->text('artist_requirements')->nullable();

            // Private office address for admin verification.
            $table->string('office_address');
            $table->string('office_city', 100);
            $table->string('office_state', 100);
            $table->string('office_country', 100);

            // Admin verification of the submitted office address.
            $table->string('address_verification_status')
                ->default('pending');

            $table->timestamp('address_verified_at')->nullable();

            // Paid annual blue-check certification.
            $table->string('certification_status')
                ->default('inactive');

            $table->timestamp('certified_at')->nullable();
            $table->timestamp('certification_expires_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_profiles');
    }
};
