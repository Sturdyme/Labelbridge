<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artist_profiles', function (Blueprint $table) {
            $table->id();

            // Link each profile to an existing user account.
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            // Public artist information.
            $table->string('stage_name')->unique();
            $table->text('bio')->nullable();
            $table->string('state', 100)->nullable();
            $table->string('profile_photo')->nullable();

            // Examples: singer, songwriter, producer.
            $table->json('creative_roles')->nullable();

            // Keep social handles private unless paid visibility is active.
            $table->json('social_handles')->nullable();
            $table->timestamp('social_visibility_expires_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_profiles');
    }
};
