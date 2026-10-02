<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('valuation_method', 20)->default('FIFO');
            $table->boolean('allow_negative_stock')->default(false);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_stockable')->default(false);
            $table->decimal('purchase_price', 18, 4)->nullable();
            $table->decimal('stock_minimum', 14, 3)->nullable();
            $table->decimal('stock_maximum', 14, 3)->nullable();
            $table->decimal('reorder_level', 14, 3)->nullable();
            $table->index(['company_id', 'is_stockable', 'is_active'], 'products_stock_scope_idx');
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->decimal('cost_of_goods_sold', 18, 2)->nullable();
            $table->string('stock_cost_method', 20)->nullable();
            $table->json('stock_cost_details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->dropColumn(['cost_of_goods_sold', 'stock_cost_method', 'stock_cost_details']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_stock_scope_idx');
            $table->dropColumn(['is_stockable', 'purchase_price', 'stock_minimum', 'stock_maximum', 'reorder_level']);
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['valuation_method', 'allow_negative_stock']);
        });
    }
};
