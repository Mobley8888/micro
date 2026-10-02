<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'cash.view' => 'Voir les caisses',
        'cash.create' => 'Gérer les caisses',
        'cash.open' => 'Ouvrir une session de caisse',
        'cash.move' => 'Enregistrer des mouvements de caisse',
        'cash.close' => 'Clôturer une session de caisse',
        'cash.adjust' => 'Ajuster une caisse',
    ];

    public function up(): void
    {
        Schema::create('cash_registers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('cash_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('cash_register_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->decimal('opening_amount', 18, 2);
            $table->string('status')->default('open');
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('closing_amount', 18, 2)->nullable();
            $table->decimal('expected_amount', 18, 2)->nullable();
            $table->decimal('difference', 18, 2)->nullable();
            $table->text('closing_note')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status', 'opened_at']);
        });

        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('cash_register_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('cash_session_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('direction');
            $table->decimal('amount', 18, 2);
            $table->timestamp('occurred_at');
            $table->text('description')->nullable();
            $table->string('reference')->nullable();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->index(['source_type', 'source_id']);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'source_type', 'source_id'], 'cash_movements_source_unique');
            $table->index(['cash_session_id', 'occurred_at']);
            $table->index(['company_id', 'type', 'occurred_at']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignUuid('cash_session_id')->nullable()->after('payment_method_id')
                ->constrained('cash_sessions')->restrictOnDelete();
        });

        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX cash_sessions_one_open_per_register_unique ON cash_sessions (cash_register_id) WHERE status = 'open'");
        } elseif ($driver === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX cash_sessions_one_open_per_register_unique ON cash_sessions (cash_register_id) WHERE status = 'open'");
        } else {
            throw new RuntimeException('Le module caisse nécessite PostgreSQL ou SQLite pour garantir une session ouverte par caisse.');
        }

        $now = now();
        foreach (self::PERMISSIONS as $slug => $name) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $name,
                'slug' => $slug,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $permissionIds = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id', 'slug');
        $rolePermissions = [];
        foreach (DB::table('roles')->get(['id', 'slug']) as $role) {
            $allowed = match ($role->slug) {
                'super_admin', 'super-administrator' => array_keys(self::PERMISSIONS),
                'company_admin' => ['cash.view', 'cash.create', 'cash.open', 'cash.move', 'cash.close'],
                'operator' => ['cash.view', 'cash.open', 'cash.move', 'cash.close'],
                default => [],
            };

            foreach ($allowed as $permission) {
                $rolePermissions[] = ['role_id' => $role->id, 'permission_id' => $permissionIds[$permission]];
            }
        }

        if ($rolePermissions !== []) {
            DB::table('permission_role')->insertOrIgnore($rolePermissions);
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->delete();

        if (Schema::hasTable('cash_sessions')) {
            DB::statement('DROP INDEX IF EXISTS cash_sessions_one_open_per_register_unique');
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cash_session_id');
        });

        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('cash_registers');
    }
};
