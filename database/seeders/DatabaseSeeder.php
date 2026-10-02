<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\NumberingSetting;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $company = Company::firstOrCreate(['name' => 'HESTAM ÉDUCATION'], [
            'legal_name' => 'Hautes Études Supérieures des Technologies Appliquées et de Management du Congo',
            'slogan' => 'Former pour travailler, former pour entreprendre.',
            'email' => 'hestameducations@gmail.com',
            'phone' => '+242066141000',
            'currency_code' => 'XAF',
        ]);

        $permissions = collect(['dashboard.view', 'customers.manage', 'leads.manage', 'products.manage', 'quotes.manage', 'invoices.manage', 'payments.manage', 'reports.view', 'settings.manage', 'users.manage'])->mapWithKeys(fn (string $slug): array => [$slug => Permission::firstOrCreate(['slug' => $slug], ['name' => Str::headline($slug)])]);
        $adminRole = Role::firstOrCreate(['company_id' => $company->id, 'slug' => 'super-administrator'], ['name' => 'Super Administrateur']);
        $adminRole->permissions()->sync($permissions->pluck('id'));

        $admin = User::firstOrCreate(['email' => env('MICRO_ADMIN_EMAIL', 'admin@micro.local')], [
            'company_id' => $company->id,
            'name' => env('MICRO_ADMIN_NAME', 'Administrateur MICRO'),
            'password' => env('MICRO_ADMIN_PASSWORD', 'change-me-immediately'),
        ]);
        $admin->update(['company_id' => $company->id]);
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        foreach ([['name' => 'TVA 18 %', 'rate' => 18], ['name' => 'Exonéré', 'rate' => 0]] as $tax) {
            Tax::firstOrCreate(['company_id' => $company->id, 'name' => $tax['name']], $tax);
        }
        foreach ([['name' => 'Espèces', 'code' => 'cash'], ['name' => 'Virement bancaire', 'code' => 'bank'], ['name' => 'Mobile Money', 'code' => 'mobile_money'], ['name' => 'Carte bancaire', 'code' => 'card'], ['name' => 'Chèque', 'code' => 'check']] as $method) {
            PaymentMethod::firstOrCreate(['company_id' => $company->id, 'code' => $method['code']], $method);
        }
        foreach (['CLI', 'DEV', 'FAC', 'PAY', 'REC'] as $type) {
            NumberingSetting::firstOrCreate(['company_id' => $company->id, 'document_type' => $type, 'year' => now()->year], ['prefix' => $type, 'padding' => 6]);
        }
        foreach ([['code' => 'FORM-INS', 'name' => 'Inscription formation', 'sale_price' => 50000], ['code' => 'FORM-FRAIS', 'name' => 'Frais de formation', 'sale_price' => 150000], ['code' => 'INFO', 'name' => 'Prestation informatique', 'sale_price' => 100000], ['code' => 'CONSEIL', 'name' => 'Prestation de conseil', 'sale_price' => 75000]] as $product) {
            Product::firstOrCreate(['company_id' => $company->id, 'code' => $product['code']], $product);
        }
    }
}
