<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_applications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('artist_profile_id')
                ->constrained('artist_profiles')
                ->cascadeOnDelete();

            $table->foreignId('label_profile_id')
                ->constrained('label_profiles')
                ->cascadeOnDelete();

            $table->text('message')->nullable();

            // pending, rejected, invited, accepted, declined, expired
            $table->string('status')->default('pending');

            // Application history and invitation timing.
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('invitation_expires_at')->nullable();
            $table->timestamp('invitation_responded_at')->nullable();

            // Whether points were used for this application.
            $table->boolean('used_points')->default(false);

            $table->timestamps();

            // Allow application history and future reapplications.
            // The controller will prevent duplicate active applications.
            $table->index(
                ['artist_profile_id', 'label_profile_id', 'created_at'],
                'artist_label_application_history_index'
            );

            $table->index(
                ['label_profile_id', 'status'],
                'label_application_label_status_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_applications');
    }
};
