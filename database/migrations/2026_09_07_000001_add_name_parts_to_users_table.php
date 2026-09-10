<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
        });

        User::query()->whereNotNull('name')->where('name', '!=', '')->each(function (User $user) {
            if ($user->first_name) {
                return;
            }
            $parts = preg_split('/\s+/', trim((string) $user->name), 2) ?: [];
            $user->forceFill([
                'first_name' => $parts[0] ?? null,
                'last_name' => $parts[1] ?? null,
            ])->save();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
