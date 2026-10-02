<?php

namespace Tests\Feature\Commercial;

use App\Models\Company;
use App\Models\Product;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_can_be_listed(): void
    {
        $company = Company::factory()->create();
        $product = Product::factory()->for($company)->create(['code' => 'FORM-001', 'name' => 'Formation test']);
        $this->actingAs($this->createUserWithPermissions($company, ['products.manage']));

        $this->get(route('products.index'))->assertOk()->assertSee($product->name);
    }

    public function test_product_can_be_created_and_persisted(): void
    {
        $company = Company::factory()->create();
        $tax = Tax::create(['company_id' => $company->id, 'name' => 'TVA test', 'rate' => 18]);
        $this->actingAs($this->createUserWithPermissions($company, ['products.manage']));

        $response = $this->post(route('products.store'), [
            'code' => 'SRV-001',
            'name' => 'Conseil test',
            'type' => 'service',
            'sale_price' => '125000.00',
            'tax_id' => $tax->id,
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('products.index'));
        $this->assertDatabaseHas('products', ['company_id' => $company->id, 'code' => 'SRV-001', 'name' => 'Conseil test']);
    }

    public function test_product_code_is_unique_per_company(): void
    {
        $company = Company::factory()->create();
        Product::factory()->for($company)->create(['code' => 'DUP-001']);
        $this->actingAs($this->createUserWithPermissions($company, ['products.manage']));

        $this->from(route('products.create'))->post(route('products.store'), [
            'code' => 'DUP-001',
            'name' => 'Doublon',
            'type' => 'product',
            'sale_price' => 1000,
        ])->assertRedirect(route('products.create'));
    }

    public function test_stockable_product_can_store_inventory_attributes(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->createUserWithPermissions($company, ['products.manage']));

        $this->post(route('products.store'), [
            'code' => 'STK-001',
            'name' => 'Ordinateur portable',
            'type' => 'product',
            'sale_price' => '1200.00',
            'purchase_price' => '950.00',
            'is_stockable' => '1',
            'stock_minimum' => '2',
            'stock_maximum' => '25',
            'reorder_level' => '5',
            'is_active' => '1',
        ])->assertRedirect(route('products.index'));

        $product = Product::query()->where('code', 'STK-001')->firstOrFail();
        $this->assertTrue($product->is_stockable);
        $this->assertSame('950.0000', $product->purchase_price);
        $this->assertSame('2.000', (string) $product->stock_minimum);
        $this->assertSame('25.000', (string) $product->stock_maximum);
        $this->assertSame('5.000', (string) $product->reorder_level);
    }

    public function test_product_can_be_updated_viewed_activated_deactivated_and_archived(): void
    {
        $company = Company::factory()->create();
        $product = Product::factory()->for($company)->create(['is_active' => true]);
        $this->actingAs($this->createUserWithPermissions($company, ['products.manage']));

        $this->put(route('products.update', $product), [
            'code' => 'UPDATED-001',
            'name' => 'Produit modifié',
            'type' => 'product',
            'sale_price' => 2500,
            'is_active' => '1',
        ])->assertRedirect(route('products.show', $product));
        $this->get(route('products.show', $product))->assertOk()->assertSee('Produit modifié');

        $this->patch(route('products.deactivate', $product))->assertRedirect();
        $this->assertFalse($product->refresh()->is_active);
        $this->patch(route('products.activate', $product))->assertRedirect();
        $this->assertTrue($product->refresh()->is_active);
        $this->delete(route('products.destroy', $product))->assertRedirect(route('products.index'));
        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_stock_viewer_can_view_own_product_stock_without_catalog_actions(): void
    {
        $company = Company::factory()->create();
        $product = Product::factory()->for($company)->create([
            'name' => 'Produit stock opérateur',
            'is_stockable' => true,
            'sale_price' => 987654,
            'purchase_price' => 12345,
        ]);
        $viewer = $this->createUserWithPermissions($company, ['stock.view']);

        $this->actingAs($viewer)->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Gestion du stock')
            ->assertSee('Stock non initialisé')
            ->assertDontSee('Prix de vente HT')
            ->assertDontSee("Prix d'achat")
            ->assertDontSee('Modifier')
            ->assertDontSee('Désactiver');

        $this->actingAs($viewer)->patch(route('products.deactivate', $product))->assertForbidden();
        $this->assertTrue($product->fresh()->is_active);
    }

    public function test_stock_viewer_sees_non_stockable_status_on_product_detail(): void
    {
        $company = Company::factory()->create();
        $product = Product::factory()->for($company)->create([
            'type' => 'service',
            'is_stockable' => false,
        ]);
        $viewer = $this->createUserWithPermissions($company, ['stock.view']);

        $this->actingAs($viewer)->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Non stockable')
            ->assertSee('NON STOCKABLE');
    }

    public function test_product_detail_is_forbidden_without_catalog_or_stock_read_permission(): void
    {
        $company = Company::factory()->create();
        $product = Product::factory()->for($company)->create();
        $user = $this->createUserWithPermissions($company, ['invoice.create']);

        $this->actingAs($user)->get(route('products.show', $product))->assertForbidden();
    }

    public function test_stock_viewer_cannot_view_a_product_from_another_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $product = Product::factory()->for($otherCompany)->create();
        $viewer = $this->createUserWithPermissions($company, ['stock.view']);

        $this->actingAs($viewer)->get(route('products.show', $product))->assertNotFound();
    }

    public function test_stock_valuation_permission_controls_purchase_cost_visibility(): void
    {
        $company = Company::factory()->create();
        $product = Product::factory()->for($company)->create([
            'is_stockable' => true,
            'purchase_price' => 12345,
        ]);
        $stockViewer = $this->createUserWithPermissions($company, ['stock.view']);
        $valuationViewer = $this->createUserWithPermissions($company, ['stock.view', 'stock.valuation.view']);

        $this->actingAs($stockViewer)->get(route('products.show', $product))
            ->assertOk()
            ->assertDontSee("Prix d'achat", false);

        $this->actingAs($valuationViewer)->get(route('products.show', $product))
            ->assertOk()
            ->assertSee("Prix d'achat", false);
    }

    public function test_stock_thresholds_are_displayed_without_artificial_conversion(): void
    {
        $this->assertSame('10', Product::make()->formatStockMetric(10.000));
        $this->assertSame('0,9', Product::make()->formatStockMetric(0.900));
        $this->assertSame('1,5', Product::make()->formatStockMetric(1.500));
        $this->assertSame('2,25', Product::make()->formatStockMetric(2.250));
    }

    public function test_invalid_tax_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->actingAs($this->createUserWithPermissions($company, ['products.manage']));

        $this->from(route('products.create'))->post(route('products.store'), [
            'code' => 'BAD-TAX',
            'name' => 'Produit invalide',
            'type' => 'product',
            'sale_price' => 1000,
            'tax_id' => 999999,
        ])->assertRedirect(route('products.create'))->assertSessionHasErrors('tax_id');
    }

    public function test_user_without_product_management_permission_cannot_change_stockability(): void
    {
        $company = Company::factory()->create();
        $product = Product::factory()->for($company)->create(['is_stockable' => true]);
        $operator = $this->createUserWithPermissions($company, ['invoice.create']);

        $this->actingAs($operator)->put(route('products.update', $product), [
            'code' => $product->code,
            'name' => $product->name,
            'type' => 'product',
            'sale_price' => $product->sale_price,
            'is_stockable' => false,
        ])->assertForbidden();

        $this->assertTrue($product->fresh()->is_stockable);
    }
}
