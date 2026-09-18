<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_message_campaigns', function (Blueprint $table) {
            $table->string('channel', 16)->default('sms')->after('audience');
            $table->string('subject', 120)->nullable()->after('channel');
        });

        Schema::table('customer_message_logs', function (Blueprint $table) {
            $table->string('email', 190)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('customer_message_campaigns', function (Blueprint $table) {
            $table->dropColumn(['channel', 'subject']);
        });

        Schema::table('customer_message_logs', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
