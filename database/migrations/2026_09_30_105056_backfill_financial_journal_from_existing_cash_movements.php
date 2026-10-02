<?php

use App\Models\CashMovement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('cash_movements')->orderBy('id')->chunk(500, function ($movements): void {
            foreach ($movements as $movement) {
                DB::table('financial_journal_entries')->insertOrIgnore([
                    'id' => (string) Str::uuid(),
                    'company_id' => $movement->company_id,
                    'account_type' => 'cash',
                    'account_id' => $movement->cash_register_id,
                    'direction' => $movement->direction,
                    'amount' => $movement->amount,
                    'occurred_at' => $movement->occurred_at,
                    'source_type' => CashMovement::class,
                    'source_id' => $movement->id,
                    'reference' => $movement->reference,
                    'description' => $movement->description,
                    'created_by' => $movement->created_by,
                    'created_at' => $movement->created_at,
                    'updated_at' => $movement->updated_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        // Keep audit history intact when rolling back this backfill migration.
    }
};
