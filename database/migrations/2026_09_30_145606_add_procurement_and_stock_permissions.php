<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'suppliers.view' => 'Voir les fournisseurs',
        'suppliers.create' => 'Créer des fournisseurs',
        'suppliers.update' => 'Modifier les fournisseurs',
        'purchases.view' => 'Voir les achats',
        'purchases.create' => 'Créer des achats',
        'purchases.update' => 'Modifier les achats',
        'purchases.confirm' => 'Confirmer les commandes fournisseurs',
        'purchases.receive' => 'Réceptionner les achats',
        'purchases.cancel' => 'Annuler les achats',
        'stock.view' => 'Voir les stocks',
        'stock.create' => 'Créer des mouvements de stock',
        'stock.adjust' => 'Ajuster les stocks',
        'stock.transfer' => 'Transférer les stocks',
        'stock.inventory' => 'Créer des inventaires',
        'stock.validate' => 'Valider les mouvements et inventaires',
        'stock.valuation.view' => 'Voir la valorisation du stock',
        'margins.view' => 'Voir les marges commerciales',
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::PERMISSIONS as $slug => $name) {
            DB::table('permissions')->insertOrIgnore(['slug' => $slug, 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }

        $permissionIds = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id', 'slug');
        foreach (DB::table('roles')->get(['id', 'slug']) as $role) {
            $allowed = match ($role->slug) {
                'super_admin', 'super-administrator' => array_keys(self::PERMISSIONS),
                'company_admin' => array_keys(self::PERMISSIONS),
                'operator' => ['stock.view'],
                default => [],
            };
            foreach ($allowed as $slug) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionIds[$slug]]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
