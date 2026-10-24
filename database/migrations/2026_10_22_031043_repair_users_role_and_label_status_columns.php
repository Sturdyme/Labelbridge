
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role', 20)->default('artist')->index();
            }

            if (! Schema::hasColumn('users', 'label_status')) {
                $table->string('label_status', 20)
                    ->nullable()
                    ->default('pending');
            }
        });
    }

    public function down(): void
    {
        // Intentionally leave existing columns and user data untouched.
    }
};
