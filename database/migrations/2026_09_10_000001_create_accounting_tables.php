<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('avg_cost')->default(0)->after('stock');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('avg_cost')->default(0)->after('stock');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('cost_snapshot')->nullable()->after('price_snapshot');
        });

        Schema::create('inventory_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->unsignedInteger('qty');
            $table->unsignedInteger('unit_cost');
            $table->unsignedInteger('total_cost');
            $table->string('supplier')->nullable();
            $table->date('purchased_at');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('purchased_at');
            $table->index(['product_id', 'product_variant_id']);
        });

        Schema::create('operating_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('category', 32);
            $table->string('title');
            $table->unsignedInteger('amount');
            $table->date('spent_at');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['spent_at', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operating_expenses');
        Schema::dropIfExists('inventory_purchases');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('cost_snapshot');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('avg_cost');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('avg_cost');
        });
    }
};
