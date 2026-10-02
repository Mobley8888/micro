<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 100);
            $table->string('name');
            $table->text('address')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('stock_lots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('supplier_id')->nullable()->constrained('suppliers')->restrictOnDelete();
            $table->foreignUuid('purchase_receipt_item_id')->nullable()->constrained('purchase_receipt_items')->restrictOnDelete();
            $table->uuid('origin_lot_id')->nullable();
            $table->string('lot_number', 150);
            $table->timestamp('received_at');
            $table->date('expires_at')->nullable();
            $table->decimal('initial_quantity', 14, 3);
            $table->decimal('remaining_quantity', 14, 3);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('initial_value', 18, 2);
            $table->decimal('remaining_value', 18, 2);
            $table->string('status', 20)->default('available');
            $table->timestamps();
            $table->unique(['company_id', 'warehouse_id', 'product_id', 'lot_number']);
            $table->index(['company_id', 'warehouse_id', 'product_id', 'received_at', 'status'], 'stock_lots_fifo_idx');
        });

        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->foreign('origin_lot_id')->references('id')->on('stock_lots')->restrictOnDelete();
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('stock_lot_id')->nullable()->constrained('stock_lots')->restrictOnDelete();
            $table->string('type', 40);
            $table->string('direction', 10);
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->timestamp('occurred_at');
            $table->string('reference')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->uuid('idempotency_key')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'product_id', 'occurred_at']);
            $table->index(['company_id', 'warehouse_id', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('stock_movement_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_movement_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('stock_lot_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_cost', 18, 4);
            $table->decimal('total_cost', 18, 2);
            $table->timestamps();
            $table->index(['stock_movement_id', 'stock_lot_id']);
        });

        Schema::create('stock_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignUuid('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('number');
            $table->timestamp('transferred_at');
            $table->string('status', 20)->default('posted');
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 14, 3);
            $table->timestamps();
        });

        Schema::table('purchase_receipt_items', function (Blueprint $table): void {
            $table->foreignUuid('stock_lot_id')->nullable()->constrained('stock_lots')->restrictOnDelete();
        });

        Schema::create('inventory_counts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('number');
            $table->string('status', 20)->default('draft');
            $table->timestamp('started_at');
            $table->timestamp('validated_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('validated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->uuid('idempotency_key')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'status', 'started_at']);
        });

        Schema::create('inventory_count_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('inventory_count_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->decimal('expected_quantity', 14, 3);
            $table->decimal('counted_quantity', 14, 3)->nullable();
            $table->decimal('difference_quantity', 14, 3)->nullable();
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('difference_value', 18, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['inventory_count_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_count_items');
        Schema::dropIfExists('inventory_counts');
        Schema::table('purchase_receipt_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('stock_lot_id');
        });
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_movement_allocations');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_lots');
        Schema::dropIfExists('warehouses');
    }
};
