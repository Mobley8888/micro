<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'dashboard.view' => 'Consulter le tableau de bord',
        'customer.view' => 'Consulter les clients',
        'customer.create' => 'Créer des clients',
        'invoice.view' => 'Consulter les factures',
        'invoice.create' => 'Créer des factures',
        'payment.view' => 'Consulter les paiements',
        'payment.create' => 'Enregistrer des paiements',
        'receipt.view' => 'Consulter les reçus',
    ];

    public function up(): void
    {
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
                'super_admin', 'super-administrator', 'company_admin' => array_keys(self::PERMISSIONS),
                'operator' => ['dashboard.view', 'customer.view', 'customer.create', 'invoice.view', 'invoice.create', 'payment.view', 'payment.create', 'receipt.view'],
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
        $newPermissionSlugs = array_values(array_diff(array_keys(self::PERMISSIONS), ['dashboard.view']));
        $permissionIds = DB::table('permissions')->whereIn('slug', $newPermissionSlugs)->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('slug', $newPermissionSlugs)->delete();
    }
};
