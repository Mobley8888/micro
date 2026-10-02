<?php

namespace Tests\Feature\Commercial;

use App\Models\Company;
use App\Models\Customer;
use App\Models\InventoryCount;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceiptItem;
use App\Models\Role;
use App\Models\StockLot;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Commercial\InventoryCountService;
use App\Services\Commercial\InvoiceService;
use App\Services\Commercial\PaymentService;
use App\Services\Commercial\PurchaseOrderService;
use App\Services\Commercial\ReceivableService;
use App\Services\RoleCatalogService;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_page_is_accessible_for_authorized_user(): void
    {
        $company = Company::factory()->create();
        $user = $this->userWithRole($company, User::ROLE_OPERATOR);

        $this->actingAs($user)->get(route('stock.index'))->assertOk()->assertSee('Gestion du stock');
    }

    public function test_stock_page_is_accessible_to_company_admin_and_super_admin_roles(): void
    {
        $company = Company::factory()->create();
        $product = Product::factory()->for($company)->create(['name' => 'Produit rôles stock']);
        $roles = app(RoleCatalogService::class)->rolesForCompany($company);

        foreach ([User::ROLE_COMPANY_ADMIN, User::ROLE_OPERATOR] as $roleSlug) {
            $user = User::factory()->for($company)->create();
            $user->roles()->attach($roles->firstWhere('slug', $roleSlug));

            $this->actingAs($user)->get(route('stock.index'))
                ->assertOk()
                ->assertSee($product->name);
        }

        $superAdmin = $this->createUserWithPermissions($company, ['stock.view']);
        $superAdminRole = Role::query()->create([
            'company_id' => $company->id,
            'name' => 'Super administrateur stock',
            'slug' => 'super-administrator',
        ]);
        $superAdmin->roles()->attach($superAdminRole);

        $this->actingAs($superAdmin)->get(route('stock.index'))
            ->assertOk()
            ->assertSee($product->name);
    }

    public function test_stock_page_is_forbidden_without_stock_view_permission(): void
    {
        $company = Company::factory()->create();
        $user = $this->createUserWithPermissions($company, ['invoice.create']);

        $this->actingAs($user)->get(route('stock.index'))->assertForbidden();
    }

    public function test_stockable_products_are_displayed(): void
    {
        $company = Company::factory()->create();
        $user = $this->userWithRole($company, User::ROLE_OPERATOR);
        Product::factory()->for($company)->create([
            'name' => 'Produit stockable',
            'code' => 'STK-100',
            'is_stockable' => true,
        ]);

        $this->actingAs($user)->get(route('stock.index'))->assertOk()->assertSee('Produit stockable');
    }

    public function test_stock_page_and_product_detail_display_the_current_stock_quantity(): void
    {
        $company = Company::factory()->create();
        $user = $this->createUserWithPermissions($company, ['stock.view', 'stock.create']);
        $warehouse = $this->warehouse($company, $user);
        $product = Product::factory()->for($company)->create([
            'name' => 'Produit avec quantité',
            'type' => 'product',
            'is_stockable' => true,
        ]);
        app(StockService::class)->recordEntry($product, '5.250', $user, $warehouse);

        $this->actingAs($user)->get(route('stock.index'))
            ->assertOk()
            ->assertSee('5,25 unités')
            ->assertSee('NORMAL');

        $this->actingAs($user)->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('5,25 unités')
            ->assertSee('NORMAL');
    }

    public function test_non_stockable_products_display_non_stockable_label(): void
    {
        $company = Company::factory()->create();
        $user = $this->userWithRole($company, User::ROLE_OPERATOR);
        Product::factory()->for($company)->create([
            'name' => 'Service non stockable',
            'code' => 'SRV-100',
            'type' => 'service',
            'is_stockable' => false,
        ]);

        $this->actingAs($user)->get(route('stock.index'))->assertOk()->assertSee('Non stockable');
    }

    public function test_stock_threshold_fields_are_visible(): void
    {
        $company = Company::factory()->create();
        $user = $this->userWithRole($company, User::ROLE_OPERATOR);
        Product::factory()->for($company)->create([
            'name' => 'Produit seuil',
            'code' => 'STK-200',
            'is_stockable' => true,
            'stock_minimum' => 12,
            'stock_maximum' => 80,
            'reorder_level' => 25,
        ]);

        $this->actingAs($user)->get(route('stock.index'))
            ->assertOk()
            ->assertSee('Minimum')
            ->assertSee('Maximum')
            ->assertSee('Seuil réappro.')
            ->assertSee('12')
            ->assertSee('80')
            ->assertSee('25');
    }

    public function test_uninitialized_stock_displays_non_initialized_label_instead_of_zero(): void
    {
        $company = Company::factory()->create();
        $user = $this->userWithRole($company, User::ROLE_OPERATOR);
        Product::factory()->for($company)->create([
            'name' => 'Produit sans stock',
            'code' => 'STK-300',
            'is_stockable' => true,
        ]);

        $this->actingAs($user)->get(route('stock.index'))
            ->assertOk()
            ->assertSee('Stock non initialisé')
            ->assertDontSee('0 unités');
    }

    public function test_other_company_products_are_hidden(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $user = $this->userWithRole($companyA, User::ROLE_OPERATOR);

        Product::factory()->for($companyA)->create(['name' => 'Produit A', 'code' => 'A-001', 'is_stockable' => true]);
        Product::factory()->for($companyB)->create(['name' => 'Produit B', 'code' => 'B-001', 'is_stockable' => true]);

        $this->actingAs($user)->get(route('stock.index'))
            ->assertOk()
            ->assertSee('Produit A')
            ->assertDontSee('Produit B');
    }

    public function test_existing_product_pages_still_work(): void
    {
        $company = Company::factory()->create();
        $user = $this->createUserWithPermissions($company, ['products.manage']);
        $product = Product::factory()->for($company)->create(['name' => 'Produit de catalogue', 'code' => 'CAT-001']);

        $this->actingAs($user)->get(route('products.index'))->assertOk();
        $this->actingAs($user)->get(route('products.show', $product))->assertOk();
    }

    public function test_stock_movement_forms_render_only_company_stockables_and_warehouses(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $user = $this->createUserWithPermissions($company, ['stock.view', 'stock.create', 'stock.adjust']);
        $product = Product::factory()->for($company)->create(['name' => 'Produit de mon entreprise', 'type' => 'product', 'is_stockable' => true]);
        $otherProduct = Product::factory()->for($otherCompany)->create(['name' => 'Produit autre entreprise', 'type' => 'product', 'is_stockable' => true]);
        $warehouse = $this->warehouse($company, $user);
        $otherWarehouse = $this->warehouse($otherCompany, User::factory()->for($otherCompany)->create(), 'OTHER');

        $this->actingAs($user);
        foreach (['entry.create', 'exit.create', 'adjustment.create'] as $route) {
            $this->get(route('stock.movements.'.$route))
                ->assertOk()
                ->assertSee($product->name)
                ->assertSee($warehouse->name)
                ->assertDontSee($otherProduct->name)
                ->assertDontSee($otherWarehouse->name);
        }
        $this->get(route('stock.movements.adjustment.create'))->assertSee('name="reason"', false);
    }

    public function test_stock_viewer_sees_only_movement_actions_they_can_access(): void
    {
        $company = Company::factory()->create();
        $viewer = $this->userWithRole($company, User::ROLE_OPERATOR);
        $this->actingAs($viewer);

        $this->get(route('stock.movements.index'))
            ->assertOk()
            ->assertDontSee('href="'.route('stock.movements.entry.create').'"', false)
            ->assertDontSee('href="'.route('stock.movements.exit.create').'"', false)
            ->assertDontSee('href="'.route('stock.movements.adjustment.create').'"', false);
        $this->get(route('stock.movements.entry.create'))->assertForbidden();
        $this->get(route('stock.movements.exit.create'))->assertForbidden();
        $this->get(route('stock.movements.adjustment.create'))->assertForbidden();
    }

    public function test_new_company_role_catalog_grants_the_existing_stock_permissions(): void
    {
        $company = Company::factory()->create();

        app(RoleCatalogService::class)->ensureCompanyRoles($company);
        $companyAdmin = Role::query()->where('company_id', $company->id)->where('slug', User::ROLE_COMPANY_ADMIN)->firstOrFail();
        $operator = Role::query()->where('company_id', $company->id)->where('slug', User::ROLE_OPERATOR)->firstOrFail();

        $this->assertTrue($companyAdmin->permissions()->where('slug', 'stock.view')->exists());
        $this->assertTrue($companyAdmin->permissions()->where('slug', 'stock.create')->exists());
        $this->assertTrue($companyAdmin->permissions()->where('slug', 'stock.adjust')->exists());
        $this->assertTrue($operator->permissions()->where('slug', 'stock.view')->exists());
        $this->assertFalse($operator->permissions()->where('slug', 'stock.create')->exists());
        $this->assertFalse($operator->permissions()->where('slug', 'stock.adjust')->exists());
    }

    public function test_manual_entry_post_creates_a_stock_lot_and_movement(): void
    {
        $company = Company::factory()->create();
        $user = $this->createUserWithPermissions($company, ['stock.view', 'stock.create']);
        $warehouse = $this->warehouse($company, $user);
        $product = Product::factory()->for($company)->create(['type' => 'product', 'is_stockable' => true]);

        $this->actingAs($user)->from(route('stock.movements.entry.create'))
            ->post(route('stock.movements.entry.store'), [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => '5.250',
                'unit_cost' => '2.5000',
                'reference' => 'REF-ENTRY-001',
                'occurred_at' => today()->toDateString(),
            ])
            ->assertRedirect(route('stock.movements.index'))
            ->assertSessionHas('success', 'Entrée de stock enregistrée.');

        $movement = StockMovement::query()->where('reference', 'REF-ENTRY-001')->firstOrFail();
        $this->assertSame('manual_entry', $movement->type);
        $this->assertSame('in', $movement->direction);
        $this->assertSame('5.250', $movement->quantity);
        $this->assertSame($user->id, $movement->created_by);
        $this->assertSame(1, StockLot::query()->where('product_id', $product->id)->count());
        $this->assertSame(5.25, app(StockService::class)->getCurrentStock($product, $warehouse->id));
    }

    public function test_manual_exit_post_reduces_stock_using_the_stock_engine(): void
    {
        $company = Company::factory()->create();
        $user = $this->createUserWithPermissions($company, ['stock.view', 'stock.create']);
        $warehouse = $this->warehouse($company, $user);
        $product = Product::factory()->for($company)->create(['type' => 'product', 'is_stockable' => true]);
        $stock = app(StockService::class);
        $stock->recordEntry($product, '10', $user, $warehouse);

        $this->actingAs($user)->from(route('stock.movements.exit.create'))
            ->post(route('stock.movements.exit.store'), [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => '2.500',
                'reference' => 'REF-EXIT-001',
                'occurred_at' => today()->toDateString(),
            ])
            ->assertRedirect(route('stock.movements.index'))
            ->assertSessionHas('success', 'Sortie de stock enregistrée.');

        $movement = StockMovement::query()->where('reference', 'REF-EXIT-001')->firstOrFail();
        $this->assertSame('manual_exit', $movement->type);
        $this->assertSame('out', $movement->direction);
        $this->assertSame('2.500', $movement->quantity);
        $this->assertSame(7.5, $stock->getCurrentStock($product, $warehouse->id));
    }

    public function test_insufficient_manual_exit_is_rejected_without_partial_stock_changes(): void
    {
        $company = Company::factory()->create(['allow_negative_stock' => false]);
        $user = $this->createUserWithPermissions($company, ['stock.view', 'stock.create']);
        $warehouse = $this->warehouse($company, $user);
        $product = Product::factory()->for($company)->create(['type' => 'product', 'is_stockable' => true]);
        $stock = app(StockService::class);
        $stock->recordEntry($product, '2', $user, $warehouse);

        $this->actingAs($user)->from(route('stock.movements.exit.create'))
            ->post(route('stock.movements.exit.store'), [
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => '3',
            ])
            ->assertRedirect(route('stock.movements.exit.create'))
            ->assertSessionHasErrors([
                'quantity' => 'Le stock disponible est insuffisant pour cette sortie.',
            ]);

        $this->assertSame(2.0, $stock->getCurrentStock($product, $warehouse->id));
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(1, StockLot::query()->where('product_id', $product->id)->count());
    }

    public function test_adjustment_post_applies_both_directions_and_persists_the_reason(): void
    {
        $company = Company::factory()->create();
        $user = $this->createUserWithPermissions($company, ['stock.view', 'stock.adjust']);
        $warehouse = $this->warehouse($company, $user);
        $product = Product::factory()->for($company)->create(['type' => 'product', 'is_stockable' => true]);
        $stock = app(StockService::class);
        $stock->recordEntry($product, '5', $user, $warehouse);
        $this->actingAs($user);

        foreach ([
            ['delta' => '2', 'reference' => 'ADJ-POS', 'reason' => 'Correction après réception', 'expected_stock' => 7.0],
            ['delta' => '-3', 'reference' => 'ADJ-NEG', 'reason' => 'Correction après inventaire', 'expected_stock' => 4.0],
        ] as $adjustment) {
            $this->from(route('stock.movements.adjustment.create'))
                ->post(route('stock.movements.adjustment.store'), [
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'delta' => $adjustment['delta'],
                    'reference' => $adjustment['reference'],
                    'reason' => $adjustment['reason'],
                ])
                ->assertRedirect(route('stock.movements.index'))
                ->assertSessionHas('success', 'Ajustement de stock enregistré.');

            $this->assertSame($adjustment['expected_stock'], $stock->getCurrentStock($product, $warehouse->id));
            $movement = StockMovement::query()->where('reference', $adjustment['reference'])->firstOrFail();
            $this->assertSame('adjustment', $movement->type);
            $this->assertSame($adjustment['reason'], $movement->metadata['reason']);
        }
    }

    public function test_manual_exit_allows_negative_stock_only_when_company_configuration_allows_it(): void
    {
        $company = Company::factory()->create(['allow_negative_stock' => true]);
        $user = $this->createUserWithPermissions($company, ['stock.view', 'stock.create']);
        $warehouse = $this->warehouse($company, $user);
        $product = Product::factory()->for($company)->create(['type' => 'product', 'is_stockable' => true]);

        $this->actingAs($user)->post(route('stock.movements.exit.store'), [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '2',
        ])->assertRedirect(route('stock.movements.index'));

        $this->assertSame(-2.0, app(StockService::class)->getCurrentStock($product, $warehouse->id));
        $this->assertDatabaseHas('stock_movements', ['product_id' => $product->id, 'direction' => 'out', 'quantity' => '2.000']);
    }

    public function test_non_stockable_products_are_rejected_by_each_manual_movement(): void
    {
        $company = Company::factory()->create();
        $user = $this->createUserWithPermissions($company, ['stock.view', 'stock.create', 'stock.adjust']);
        $warehouse = $this->warehouse($company, $user);
        $service = Product::factory()->for($company)->create(['type' => 'service', 'is_stockable' => false]);
        $this->actingAs($user);

        $this->post(route('stock.movements.entry.store'), [
            'product_id' => $service->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '1',
            'unit_cost' => '0',
        ])->assertStatus(422);
        $this->post(route('stock.movements.exit.store'), [
            'product_id' => $service->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '1',
        ])->assertStatus(422);
        $this->post(route('stock.movements.adjustment.store'), [
            'product_id' => $service->id,
            'warehouse_id' => $warehouse->id,
            'delta' => '1',
            'reason' => 'Test de refus',
        ])->assertStatus(422);

        $stock = app(StockService::class);
        try {
            $stock->recordEntry($service, '1', $user, $warehouse);
            $this->fail('Le moteur de stock ne doit pas accepter un service marqué stockable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('product', $exception->errors());
        }
        try {
            $stock->recordExit($service, '1', $user, $warehouse);
            $this->fail('Le moteur de stock ne doit pas sortir le stock d’un service.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('product', $exception->errors());
        }

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_lots', 0);
    }

    public function test_service_type_is_not_offered_or_accepted_even_when_marked_stockable(): void
    {
        $company = Company::factory()->create();
        $user = $this->createUserWithPermissions($company, ['stock.view', 'stock.create', 'stock.adjust']);
        $warehouse = $this->warehouse($company, $user);
        $service = Product::factory()->for($company)->create([
            'name' => 'Service mal configuré',
            'type' => 'service',
            'is_stockable' => true,
        ]);
        $this->actingAs($user);

        $this->get(route('stock.movements.entry.create'))->assertDontSee($service->name);
        $this->get(route('stock.movements.exit.create'))->assertDontSee($service->name);
        $this->get(route('stock.movements.adjustment.create'))->assertDontSee($service->name);
        $this->post(route('stock.movements.entry.store'), [
            'product_id' => $service->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '1',
            'unit_cost' => '1',
        ])->assertStatus(422);
        $this->post(route('stock.movements.exit.store'), [
            'product_id' => $service->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '1',
        ])->assertStatus(422);
        $this->post(route('stock.movements.adjustment.store'), [
            'product_id' => $service->id,
            'warehouse_id' => $warehouse->id,
            'delta' => '1',
            'reason' => 'Test de refus',
        ])->assertStatus(422);

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_lots', 0);
    }

    public function test_manual_movement_posts_reject_foreign_company_products_and_warehouses(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $user = $this->createUserWithPermissions($company, ['stock.view', 'stock.create', 'stock.adjust']);
        $otherUser = User::factory()->for($otherCompany)->create();
        $warehouse = $this->warehouse($company, $user);
        $otherWarehouse = $this->warehouse($otherCompany, $otherUser, 'FOREIGN');
        $product = Product::factory()->for($company)->create(['type' => 'product', 'is_stockable' => true]);
        $otherProduct = Product::factory()->for($otherCompany)->create(['type' => 'product', 'is_stockable' => true]);
        $this->actingAs($user);

        $this->post(route('stock.movements.entry.store'), [
            'product_id' => $otherProduct->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '1',
            'unit_cost' => '1',
        ])->assertNotFound();
        $this->post(route('stock.movements.exit.store'), [
            'product_id' => $product->id,
            'warehouse_id' => $otherWarehouse->id,
            'quantity' => '1',
        ])->assertNotFound();
        $this->post(route('stock.movements.adjustment.store'), [
            'product_id' => $product->id,
            'warehouse_id' => $otherWarehouse->id,
            'delta' => '1',
            'reason' => 'Test isolation',
        ])->assertNotFound();

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_lots', 0);
    }

    public function test_manual_movement_posts_validate_positive_quantities_and_adjustment_reason(): void
    {
        $company = Company::factory()->create();
        $user = $this->createUserWithPermissions($company, ['stock.view', 'stock.create', 'stock.adjust']);
        $warehouse = $this->warehouse($company, $user);
        $product = Product::factory()->for($company)->create(['type' => 'product', 'is_stockable' => true]);
        $this->actingAs($user);

        $this->from(route('stock.movements.entry.create'))->post(route('stock.movements.entry.store'), [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '0',
            'unit_cost' => '1',
        ])->assertSessionHasErrors('quantity');
        $this->from(route('stock.movements.exit.create'))->post(route('stock.movements.exit.store'), [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '-1',
        ])->assertSessionHasErrors('quantity');
        $this->from(route('stock.movements.adjustment.create'))->post(route('stock.movements.adjustment.store'), [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'delta' => '1',
        ])->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseCount('stock_lots', 0);
    }

    public function test_stock_service_tracks_entry_exit_and_stock_total(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $warehouse = Warehouse::query()->create([
            'company_id' => $company->id,
            'code' => 'MAIN',
            'name' => 'Dépôt principal',
            'is_default' => true,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $product = Product::factory()->for($company)->create([
            'name' => 'Produit stocké',
            'code' => 'STK-ENGINE-001',
            'type' => 'product',
            'is_stockable' => true,
        ]);

        $service = app(StockService::class);

        $this->assertNull($service->getCurrentStock($product, $warehouse->id));

        $service->recordEntry($product, 10, $user, $warehouse, 'ENTREE-10');
        $this->assertSame(10.0, $service->getCurrentStock($product, $warehouse->id));

        $service->recordEntry($product, 5, $user, $warehouse, 'ENTREE-5');
        $this->assertSame(15.0, $service->getCurrentStock($product, $warehouse->id));

        $service->recordExit($product, 4, $user, $warehouse, 'SORTIE-4');
        $this->assertSame(11.0, $service->getCurrentStock($product, $warehouse->id));

        $this->assertSame(3, StockMovement::query()->count());
    }

    public function test_stock_service_rejects_excessive_exit_when_negative_stock_is_disabled(): void
    {
        $company = Company::factory()->create(['allow_negative_stock' => false]);
        $user = User::factory()->for($company)->create();
        $warehouse = Warehouse::query()->create([
            'company_id' => $company->id,
            'code' => 'MAIN',
            'name' => 'Dépôt principal',
            'is_default' => true,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
        $product = Product::factory()->for($company)->create([
            'name' => 'Produit dont le stock doit rester positif',
            'code' => 'STK-ENGINE-002',
            'type' => 'product',
            'is_stockable' => true,
        ]);

        $service = app(StockService::class);
        $service->recordEntry($product, 11, $user, $warehouse, 'ENTREE-11');

        $this->expectException(ValidationException::class);
        $service->recordExit($product, 20, $user, $warehouse, 'SORTIE-20');
    }

    public function test_stock_service_is_scoped_to_company_and_warehouse(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userA = User::factory()->for($companyA)->create();
        $productA = Product::factory()->for($companyA)->create([
            'name' => 'Produit A',
            'code' => 'A-100',
            'type' => 'product',
            'is_stockable' => true,
        ]);
        $productB = Product::factory()->for($companyB)->create([
            'name' => 'Produit B',
            'code' => 'B-100',
            'type' => 'product',
            'is_stockable' => true,
        ]);
        $warehouseA = Warehouse::query()->create([
            'company_id' => $companyA->id,
            'code' => 'WH-A',
            'name' => 'Entrepôt A',
            'is_default' => true,
            'is_active' => true,
            'created_by' => $userA->id,
        ]);

        $service = app(StockService::class);
        $service->recordEntry($productA, 8, $userA, $warehouseA, 'ENTREE-A');

        $this->assertSame(8.0, $service->getCurrentStock($productA, $warehouseA->id));
        $this->assertNull($service->getCurrentStock($productB, $warehouseA->id));
        $this->assertSame([], $service->getStockByWarehouse($productB));
    }

    public function test_inventory_creation_captures_theoretical_stock_and_recorded_count_difference(): void
    {
        $company = Company::factory()->create();
        $user = $this->userWithPermissions($company, ['stock.view', 'stock.inventory', 'stock.validate']);
        $warehouse = $this->warehouse($company, $user);
        $product = Product::factory()->for($company)->create([
            'name' => 'Produit compté',
            'code' => 'INV-001',
            'type' => 'product',
            'is_stockable' => true,
        ]);
        app(StockService::class)->recordEntry($product, '10', $user, $warehouse, 'ENTREE-INV', '2.5000');

        $this->actingAs($user)->get(route('stock.inventories.create'))
            ->assertOk()
            ->assertSee($warehouse->name)
            ->assertSee('Capturer le stock théorique');
        $createResponse = $this->actingAs($user)->post(route('stock.inventories.store'), [
            'warehouse_id' => $warehouse->id,
            'notes' => 'Comptage du matin',
        ]);
        $inventoryCount = InventoryCount::query()->firstOrFail();

        $createResponse->assertRedirect(route('stock.inventories.show', $inventoryCount));
        $this->assertDatabaseHas('inventory_count_items', [
            'inventory_count_id' => $inventoryCount->id,
            'product_id' => $product->id,
            'expected_quantity' => '10.000',
            'counted_quantity' => null,
        ]);

        $this->actingAs($user)->get(route('stock.inventories.index'))
            ->assertOk()
            ->assertSee($inventoryCount->number);
        $this->actingAs($user)->get(route('stock.inventories.show', $inventoryCount))
            ->assertOk()
            ->assertSee('Produit compté')
            ->assertSee('10.000');

        $item = $inventoryCount->items()->firstOrFail();
        $this->actingAs($user)->patch(route('stock.inventories.counts', $inventoryCount), [
            'counts' => [$item->id => '13.000'],
            'item_notes' => [$item->id => 'Trois unités retrouvées'],
            'notes' => 'Comptage terminé',
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_count_items', [
            'id' => $item->id,
            'counted_quantity' => '13.000',
            'difference_quantity' => '3.000',
            'difference_value' => '7.50',
            'notes' => 'Trois unités retrouvées',
        ]);
        $this->assertDatabaseHas('inventory_counts', ['id' => $inventoryCount->id, 'notes' => 'Comptage terminé']);
    }

    public function test_inventory_validation_applies_positive_and_negative_differences_and_skips_zero_difference(): void
    {
        $company = Company::factory()->create(['allow_negative_stock' => false]);
        $user = $this->userWithPermissions($company, ['stock.view', 'stock.inventory', 'stock.validate']);
        $warehouse = $this->warehouse($company, $user);
        $products = collect([
            Product::factory()->for($company)->create(['code' => 'INV-POS', 'type' => 'product', 'is_stockable' => true]),
            Product::factory()->for($company)->create(['code' => 'INV-NEG', 'type' => 'product', 'is_stockable' => true]),
            Product::factory()->for($company)->create(['code' => 'INV-ZERO', 'type' => 'product', 'is_stockable' => true]),
        ]);
        $stock = app(StockService::class);
        $stock->recordEntry($products[0], '5', $user, $warehouse, 'ENTRY-POS');
        $stock->recordEntry($products[1], '8', $user, $warehouse, 'ENTRY-NEG');
        $stock->recordEntry($products[2], '4', $user, $warehouse, 'ENTRY-ZERO');
        $inventoryCount = app(InventoryCountService::class)->create($user, $warehouse->id);
        $items = $inventoryCount->items()->get()->keyBy('product_id');

        $this->actingAs($user)->patch(route('stock.inventories.counts', $inventoryCount), [
            'counts' => [
                $items[$products[0]->id]->id => '7',
                $items[$products[1]->id]->id => '6',
                $items[$products[2]->id]->id => '4',
            ],
        ])->assertRedirect();
        $this->actingAs($user)->post(route('stock.inventories.validate', $inventoryCount))
            ->assertRedirect(route('stock.inventories.show', $inventoryCount));

        $this->assertSame(7.0, $stock->getCurrentStock($products[0], $warehouse->id));
        $this->assertSame(6.0, $stock->getCurrentStock($products[1], $warehouse->id));
        $this->assertSame(4.0, $stock->getCurrentStock($products[2], $warehouse->id));
        $this->assertSame(5, StockMovement::query()->count());
        $this->assertDatabaseHas('stock_movements', [
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'type' => 'inventory_adjustment',
            'reference' => $inventoryCount->number,
            'created_by' => $user->id,
        ]);
        $this->assertSame('validated', $inventoryCount->fresh()->status);
        $this->assertSame($user->id, $inventoryCount->fresh()->validated_by);
    }

    public function test_inventory_without_differences_creates_no_adjustment_and_cannot_be_validated_twice(): void
    {
        $company = Company::factory()->create();
        $user = $this->userWithPermissions($company, ['stock.view', 'stock.inventory', 'stock.validate']);
        $warehouse = $this->warehouse($company, $user);
        $product = Product::factory()->for($company)->create(['code' => 'INV-SAME', 'type' => 'product', 'is_stockable' => true]);
        $stock = app(StockService::class);
        $stock->recordEntry($product, '2', $user, $warehouse);
        $inventoryCount = app(InventoryCountService::class)->create($user, $warehouse->id);
        $item = $inventoryCount->items()->firstOrFail();

        $this->actingAs($user)->patch(route('stock.inventories.counts', $inventoryCount), [
            'counts' => [$item->id => '2'],
        ])->assertRedirect();
        $this->actingAs($user)->post(route('stock.inventories.validate', $inventoryCount))->assertRedirect();
        $this->assertSame(1, StockMovement::query()->count());

        $this->actingAs($user)->post(route('stock.inventories.validate', $inventoryCount))
            ->assertRedirect()
            ->assertSessionHasErrors('inventory');
        $this->actingAs($user)->patch(route('stock.inventories.counts', $inventoryCount), [
            'counts' => [$item->id => '5'],
        ])->assertRedirect()->assertSessionHasErrors('inventory');

        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(2.0, $stock->getCurrentStock($product, $warehouse->id));
    }

    public function test_inventory_rejects_other_company_and_other_warehouse_access(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userA = $this->userWithPermissions($companyA, ['stock.view', 'stock.inventory', 'stock.validate']);
        $warehouseA = $this->warehouse($companyA, $userA, 'A');
        $warehouseB = $this->warehouse($companyB, User::factory()->for($companyB)->create(), 'B');
        Product::factory()->for($companyA)->create(['code' => 'INV-A', 'type' => 'product', 'is_stockable' => true]);
        $inventoryCount = app(InventoryCountService::class)->create($userA, $warehouseA->id);
        $otherCompanyInventory = InventoryCount::query()->create([
            'company_id' => $companyB->id,
            'warehouse_id' => $warehouseB->id,
            'number' => 'INV-OTHER-COMPANY',
            'status' => 'draft',
            'started_at' => now(),
            'created_by' => $warehouseB->created_by,
        ]);

        $this->actingAs($userA)->get(route('stock.inventories.show', $inventoryCount))->assertOk();
        $this->actingAs($userA)->get(route('stock.inventories.show', $otherCompanyInventory))->assertNotFound();
        $this->actingAs($userA)->post(route('stock.inventories.store'), ['warehouse_id' => $warehouseB->id])
            ->assertNotFound();
    }

    public function test_inventory_validation_rolls_back_prior_adjustments_when_a_later_exit_fails(): void
    {
        $company = Company::factory()->create(['allow_negative_stock' => false]);
        $user = $this->userWithPermissions($company, ['stock.view', 'stock.inventory', 'stock.validate']);
        $warehouse = $this->warehouse($company, $user);
        $firstProduct = Product::factory()->for($company)->create([
            'id' => '00000000-0000-7000-8000-000000000001',
            'code' => 'INV-ROLLBACK-A',
            'type' => 'product',
            'is_stockable' => true,
        ]);
        $secondProduct = Product::factory()->for($company)->create([
            'id' => '00000000-0000-7000-8000-000000000002',
            'code' => 'INV-ROLLBACK-B',
            'type' => 'product',
            'is_stockable' => true,
        ]);
        $stock = app(StockService::class);
        $stock->recordEntry($firstProduct, '1', $user, $warehouse);
        $stock->recordEntry($secondProduct, '1', $user, $warehouse);
        $inventoryCount = app(InventoryCountService::class)->create($user, $warehouse->id);
        $items = $inventoryCount->items()->get()->keyBy('product_id');

        $this->actingAs($user)->patch(route('stock.inventories.counts', $inventoryCount), [
            'counts' => [
                $items[$firstProduct->id]->id => '2',
                $items[$secondProduct->id]->id => '0',
            ],
        ])->assertRedirect();
        $stock->recordExit($secondProduct, '1', $user, $warehouse);
        $movementCount = StockMovement::query()->count();

        try {
            app(InventoryCountService::class)->validateCount($user, $inventoryCount);
            $this->fail('La sortie excédant le stock résiduel aurait dû annuler toute la validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertSame(1.0, $stock->getCurrentStock($firstProduct, $warehouse->id));
        $this->assertSame(0.0, $stock->getCurrentStock($secondProduct, $warehouse->id));
        $this->assertSame('draft', $inventoryCount->fresh()->status);
    }

    public function test_purchase_receipt_uses_stock_service_and_is_idempotent(): void
    {
        $company = Company::factory()->create();
        $user = $this->userWithPermissions($company, ['purchases.receive', 'stock.view']);
        $warehouse = $this->warehouse($company, $user);
        $supplier = Supplier::query()->create([
            'company_id' => $company->id,
            'code' => 'SUP-STOCK-001',
            'name' => 'Fournisseur stock',
            'currency' => 'XAF',
            'created_by' => $user->id,
        ]);
        $product = Product::factory()->for($company)->create([
            'type' => 'product',
            'is_stockable' => true,
            'purchase_price' => '2.5000',
        ]);
        $purchase = PurchaseOrder::query()->create([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'number' => 'PUR-STOCK-001',
            'ordered_at' => today(),
            'status' => 'confirmed',
            'currency' => 'XAF',
            'subtotal' => '12.50',
            'total' => '12.50',
            'created_by' => $user->id,
        ]);
        $purchaseItem = $purchase->items()->create([
            'product_id' => $product->id,
            'product_code' => $product->code,
            'description' => $product->name,
            'unit' => 'unité',
            'quantity' => '5.000',
            'received_quantity' => '0.000',
            'unit_cost' => '2.5000',
            'discount_amount' => '0.00',
            'tax_rate' => '0.00',
            'tax_amount' => '0.00',
            'line_total' => '12.50',
        ]);

        $receipt = app(PurchaseOrderService::class)->receive($user, $purchase, [$purchaseItem->id => '5']);
        $receiptItem = $receipt->items()->firstOrFail();
        $movement = StockMovement::query()->where('source_type', PurchaseReceiptItem::class)
            ->where('source_id', $receiptItem->id)->firstOrFail();
        $stock = app(StockService::class);
        $duplicate = $stock->recordEntry(
            product: $product,
            quantity: (string) $receiptItem->quantity,
            actor: $user,
            warehouse: $warehouse,
            reference: $receipt->number,
            unitCost: (string) $receiptItem->unit_cost,
            idempotencyKey: $receiptItem->id,
            occurredAt: $receipt->received_at,
            type: 'purchase_receipt',
            sourceType: PurchaseReceiptItem::class,
            sourceId: $receiptItem->id,
            supplierId: $supplier->id,
            receiptItem: $receiptItem,
            lotNumber: $receiptItem->lot_number,
        );

        $this->assertSame($movement->id, $duplicate->id);
        $this->assertSame(5.0, $stock->getCurrentStock($product, $warehouse->id));
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(1, StockLot::query()->count());
        $this->assertSame($user->id, $movement->created_by);
        $this->assertSame('2.5000', $movement->unit_cost);
        $this->assertSame($warehouse->id, $movement->warehouse_id);

        try {
            app(PurchaseOrderService::class)->receive($user, $purchase, [$purchaseItem->id => '1']);
            $this->fail('Une commande entièrement réceptionnée ne doit pas créer une seconde réception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('purchase', $exception->errors());
        }

        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(1, $receipt->items()->count());
    }

    public function test_purchase_receipt_rolls_back_all_lines_when_a_later_quantity_is_invalid(): void
    {
        $company = Company::factory()->create();
        $user = $this->userWithPermissions($company, ['purchases.receive']);
        $warehouse = $this->warehouse($company, $user);
        $supplier = Supplier::query()->create([
            'company_id' => $company->id,
            'code' => 'SUP-STOCK-ROLLBACK',
            'name' => 'Fournisseur rollback',
            'currency' => 'XAF',
            'created_by' => $user->id,
        ]);
        $products = Product::factory()->for($company)->count(2)->sequence(
            ['type' => 'product', 'is_stockable' => true],
            ['type' => 'product', 'is_stockable' => true],
        )->create();
        $purchase = PurchaseOrder::query()->create([
            'company_id' => $company->id,
            'supplier_id' => $supplier->id,
            'number' => 'PUR-STOCK-ROLLBACK',
            'ordered_at' => today(),
            'status' => 'confirmed',
            'currency' => 'XAF',
            'subtotal' => '2.00',
            'total' => '2.00',
            'created_by' => $user->id,
        ]);
        $firstItem = $purchase->items()->create([
            'product_id' => $products[0]->id, 'product_code' => $products[0]->code, 'description' => $products[0]->name,
            'unit' => 'unité', 'quantity' => '1.000', 'received_quantity' => '0.000', 'unit_cost' => '1.0000',
            'discount_amount' => '0.00', 'tax_rate' => '0.00', 'tax_amount' => '0.00', 'line_total' => '1.00',
        ]);
        $secondItem = $purchase->items()->create([
            'product_id' => $products[1]->id, 'product_code' => $products[1]->code, 'description' => $products[1]->name,
            'unit' => 'unité', 'quantity' => '1.000', 'received_quantity' => '0.000', 'unit_cost' => '1.0000',
            'discount_amount' => '0.00', 'tax_rate' => '0.00', 'tax_amount' => '0.00', 'line_total' => '1.00',
        ]);

        try {
            app(PurchaseOrderService::class)->receive($user, $purchase, [
                $firstItem->id => '1',
                $secondItem->id => '2',
            ]);
            $this->fail('Une réception supérieure à la commande doit être refusée.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantities.'.$secondItem->id, $exception->errors());
        }

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, StockLot::query()->count());
        $this->assertSame(0, $purchase->receipts()->count());
        $this->assertSame('confirmed', $purchase->fresh()->status);
        $this->assertSame('0.000', $firstItem->fresh()->received_quantity);
    }

    public function test_purchase_ui_creates_confirms_and_receives_into_stock(): void
    {
        $company = Company::factory()->create();
        $user = $this->userWithPermissions($company, [
            'purchases.view',
            'purchases.create',
            'purchases.confirm',
            'purchases.receive',
            'suppliers.view',
            'suppliers.create',
            'stock.view',
        ]);
        $warehouse = $this->warehouse($company, $user);
        $product = Product::factory()->for($company)->create([
            'type' => 'product',
            'is_stockable' => true,
            'purchase_price' => '4.0000',
        ]);

        $this->actingAs($user)->get(route('suppliers.index'))->assertOk()->assertSee('Nouveau fournisseur');
        $this->actingAs($user)->get(route('purchases.index'))->assertOk()->assertSee('Commandes fournisseurs');
        $this->actingAs($user)->get(route('purchases.create'))->assertOk()->assertSee($product->name);
        $this->actingAs($user)->post(route('suppliers.store'), [
            'code' => 'SUP-UI-001',
            'name' => 'Fournisseur interface',
            'currency' => 'XAF',
        ])->assertRedirect(route('suppliers.index'));
        $supplier = Supplier::query()->where('code', 'SUP-UI-001')->firstOrFail();

        $this->actingAs($user)->post(route('purchases.store'), [
            'supplier_id' => $supplier->id,
            'ordered_at' => today()->toDateString(),
            'items' => [
                ['product_id' => $product->id, 'quantity' => '2.5', 'unit_cost' => '4.0000', 'unit' => 'unité'],
            ],
        ])->assertRedirect();
        $purchase = PurchaseOrder::query()->firstOrFail();

        $this->actingAs($user)->get(route('purchases.show', $purchase))->assertOk()->assertSee('Confirmer la commande');
        $this->actingAs($user)->post(route('purchases.confirm', $purchase))->assertRedirect();
        $purchaseItem = $purchase->items()->firstOrFail();
        $this->actingAs($user)->get(route('purchases.show', $purchase->fresh()))->assertOk()->assertSee('Réceptionner les articles');
        $this->actingAs($user)->post(route('purchases.receive', $purchase), [
            'quantities' => [$purchaseItem->id => '2.5'],
        ])->assertRedirect();

        $this->assertSame('received', $purchase->fresh()->status);
        $this->assertSame('2.500', $purchaseItem->fresh()->received_quantity);
        $this->assertSame(2.5, app(StockService::class)->getCurrentStock($product, $warehouse->id));
        $this->assertDatabaseHas('stock_movements', [
            'company_id' => $company->id,
            'product_id' => $product->id,
            'type' => 'purchase_receipt',
            'direction' => 'in',
            'created_by' => $user->id,
        ]);
    }

    public function test_sent_invoice_records_stock_exit_and_service_line_does_not(): void
    {
        $company = Company::factory()->create();
        $user = $this->userWithPermissions($company, ['invoice.create', 'invoice.view']);
        $warehouse = $this->warehouse($company, $user);
        $stockableProduct = Product::factory()->for($company)->create([
            'type' => 'product',
            'is_stockable' => true,
            'purchase_price' => '3.0000',
        ]);
        $serviceProduct = Product::factory()->for($company)->create(['type' => 'service', 'is_stockable' => false]);
        $stock = app(StockService::class);
        $stock->recordEntry($stockableProduct, '10', $user, $warehouse, 'PURCHASE', '3.0000');
        $customer = Customer::factory()->for($company)->create();
        $invoice = app(InvoiceService::class)->createForOperator($user, [
            'customer_id' => $customer->id,
            'issue_date' => today()->toDateString(),
            'lines' => [
                ['product_id' => $stockableProduct->id, 'quantity' => '4.5'],
                ['product_id' => $serviceProduct->id, 'quantity' => '1'],
            ],
        ]);
        $saleItem = $invoice->items()->where('product_id', $stockableProduct->id)->firstOrFail();
        $saleMovement = StockMovement::query()->where('source_type', InvoiceItem::class)->where('source_id', $saleItem->id)->firstOrFail();

        $this->assertSame('sent', $invoice->status);
        $this->assertSame(5.5, $stock->getCurrentStock($stockableProduct, $warehouse->id));
        $this->assertSame('sale', $saleMovement->type);
        $this->assertSame('out', $saleMovement->direction);
        $this->assertSame('4.500', $saleMovement->quantity);
        $this->assertSame($user->id, $saleMovement->created_by);
        $this->assertSame('13.50', $saleItem->fresh()->cost_of_goods_sold);
        $this->assertSame(2, StockMovement::query()->count());
    }

    public function test_invoice_stock_issue_rejects_insufficient_stock_and_rolls_back_invoice(): void
    {
        $company = Company::factory()->create(['allow_negative_stock' => false]);
        $user = $this->userWithPermissions($company, ['invoice.create', 'payment.create']);
        $warehouse = $this->warehouse($company, $user);
        $product = Product::factory()->for($company)->create(['type' => 'product', 'is_stockable' => true]);
        $stock = app(StockService::class);
        $stock->recordEntry($product, '2', $user, $warehouse);
        $customer = Customer::factory()->for($company)->create();

        try {
            app(InvoiceService::class)->createForOperator($user, [
                'customer_id' => $customer->id,
                'issue_date' => today()->toDateString(),
                'lines' => [['product_id' => $product->id, 'quantity' => '3']],
            ]);
            $this->fail('La facture aurait dû être refusée faute de stock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(1, StockMovement::query()->count());
        $this->assertSame(2.0, $stock->getCurrentStock($product, $warehouse->id));
    }

    public function test_invoice_sale_can_use_negative_stock_when_company_allows_it(): void
    {
        $company = Company::factory()->create(['allow_negative_stock' => true]);
        $user = $this->userWithPermissions($company, ['invoice.create']);
        $warehouse = $this->warehouse($company, $user);
        $product = Product::factory()->for($company)->create(['type' => 'product', 'is_stockable' => true]);
        $stock = app(StockService::class);
        $stock->recordEntry($product, '2', $user, $warehouse);
        $customer = Customer::factory()->for($company)->create();

        $invoice = app(InvoiceService::class)->createForOperator($user, [
            'customer_id' => $customer->id,
            'issue_date' => today()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => '3']],
        ]);

        $this->assertSame('sent', $invoice->status);
        $this->assertSame(-1.0, $stock->getCurrentStock($product, $warehouse->id));
        $this->assertDatabaseHas('stock_movements', [
            'company_id' => $company->id,
            'type' => 'sale',
            'direction' => 'out',
            'quantity' => '3.000',
        ]);
    }

    public function test_draft_invoice_is_not_reserved_until_issued_and_cancellation_reverses_the_sale(): void
    {
        $company = Company::factory()->create(['allow_negative_stock' => false]);
        $user = $this->userWithPermissions($company, ['invoice.create', 'invoice.view']);
        $warehouse = $this->warehouse($company, $user);
        $product = Product::factory()->for($company)->create(['type' => 'product', 'is_stockable' => true]);
        $stock = app(StockService::class);
        $stock->recordEntry($product, '10', $user, $warehouse);
        $customer = Customer::factory()->for($company)->create();
        $invoice = Invoice::factory()->for($company)->for($customer)->create([
            'status' => 'draft',
            'subtotal' => '20.00',
            'total' => '20.00',
            'balance_due' => '20.00',
        ]);
        $items = collect(['10.00', '10.00'])->map(fn (string $lineTotal): InvoiceItem => InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'description' => $product->name,
            'quantity' => '2.000',
            'unit_price' => '5.00',
            'discount_amount' => '0.00',
            'tax_rate' => '0.00',
            'tax_amount' => '0.00',
            'total' => $lineTotal,
        ]));
        app(ReceivableService::class)->synchronize($invoice);

        $this->assertSame(10.0, $stock->getCurrentStock($product, $warehouse->id));
        $this->actingAs($user)->post(route('invoices.issue', $invoice))->assertRedirect(route('invoices.show', $invoice));
        $this->actingAs($user)->get(route('invoices.show', $invoice))->assertOk()->assertSee('Annuler la facture');
        $this->assertSame(6.0, $stock->getCurrentStock($product, $warehouse->id));
        $this->assertSame(2, StockMovement::query()->where('source_type', InvoiceItem::class)->whereIn('source_id', $items->pluck('id'))->where('type', 'sale')->count());
        $this->actingAs($user)->post(route('invoices.issue', $invoice))
            ->assertRedirect()
            ->assertSessionHasErrors('invoice');
        $this->assertSame(2, StockMovement::query()->where('type', 'sale')->count());
        $product->delete();

        $this->actingAs($user)->post(route('invoices.cancel', $invoice))->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame(10.0, $stock->getCurrentStock($product, $warehouse->id));
        $this->assertSame('cancelled', $invoice->fresh()->status);
        $this->assertSame('cancelled', $invoice->fresh()->financial_status);
        $this->assertSame('0.00', $invoice->fresh()->balance_due);
        $this->assertDatabaseHas('stock_movements', [
            'company_id' => $company->id,
            'product_id' => $product->id,
            'type' => 'sale_reversal',
            'direction' => 'in',
            'source_type' => InvoiceItem::class,
            'created_by' => $user->id,
        ]);
        $this->assertSame(2, StockMovement::query()->where('type', 'sale_reversal')->count());
        $this->assertSame('cancelled', $invoice->fresh()->receivable?->status);
    }

    public function test_invoice_exit_is_limited_to_its_default_warehouse(): void
    {
        $company = Company::factory()->create(['allow_negative_stock' => false]);
        $user = $this->userWithPermissions($company, ['invoice.create']);
        $primaryWarehouse = $this->warehouse($company, $user);
        $secondaryWarehouse = $this->warehouse($company, $user, 'SECONDARY', false);
        $product = Product::factory()->for($company)->create(['type' => 'product', 'is_stockable' => true]);
        $stock = app(StockService::class);
        $stock->recordEntry($product, '2', $user, $primaryWarehouse);
        $stock->recordEntry($product, '100', $user, $secondaryWarehouse);
        $customer = Customer::factory()->for($company)->create();

        try {
            app(InvoiceService::class)->createForOperator($user, [
                'customer_id' => $customer->id,
                'issue_date' => today()->toDateString(),
                'lines' => [['product_id' => $product->id, 'quantity' => '3']],
            ]);
            $this->fail('Le stock de l’entrepôt secondaire ne doit pas couvrir une vente imputée au principal.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        $this->assertSame(2.0, $stock->getCurrentStock($product, $primaryWarehouse->id));
        $this->assertSame(100.0, $stock->getCurrentStock($product, $secondaryWarehouse->id));
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_invoice_lines_and_stock_movements_rollback_together_on_later_shortage(): void
    {
        $company = Company::factory()->create(['allow_negative_stock' => false]);
        $user = $this->userWithPermissions($company, ['invoice.create']);
        $warehouse = $this->warehouse($company, $user);
        $firstProduct = Product::factory()->for($company)->create([
            'type' => 'product',
            'is_stockable' => true,
            'purchase_price' => '1.0000',
        ]);
        $secondProduct = Product::factory()->for($company)->create(['type' => 'product', 'is_stockable' => true]);
        $stock = app(StockService::class);
        $stock->recordEntry($firstProduct, '2', $user, $warehouse);
        $stock->recordEntry($secondProduct, '0.5', $user, $warehouse);
        $customer = Customer::factory()->for($company)->create();

        try {
            app(InvoiceService::class)->createForOperator($user, [
                'customer_id' => $customer->id,
                'issue_date' => today()->toDateString(),
                'lines' => [
                    ['product_id' => $firstProduct->id, 'quantity' => '1'],
                    ['product_id' => $secondProduct->id, 'quantity' => '1'],
                ],
            ]);
            $this->fail('La facture complète doit être annulée si une seule ligne manque de stock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }

        $this->assertSame(2, StockMovement::query()->count());
        $this->assertSame(2.0, $stock->getCurrentStock($firstProduct, $warehouse->id));
        $this->assertSame(0.5, $stock->getCurrentStock($secondProduct, $warehouse->id));
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_invoice_creation_rejects_products_from_another_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userA = $this->userWithPermissions($companyA, ['invoice.create']);
        $customerA = Customer::factory()->for($companyA)->create();
        $productB = Product::factory()->for($companyB)->create(['type' => 'product', 'is_stockable' => true]);

        try {
            app(InvoiceService::class)->createForOperator($userA, [
                'customer_id' => $customerA->id,
                'issue_date' => today()->toDateString(),
                'lines' => [['product_id' => $productB->id, 'quantity' => '1']],
            ]);
            $this->fail('Une facture ne doit pas contenir un produit d’une autre entreprise.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }

        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_cancelled_invoice_cannot_receive_a_payment(): void
    {
        $company = Company::factory()->create();
        $user = $this->userWithPermissions($company, ['invoice.create', 'payment.create']);
        $customer = Customer::factory()->for($company)->create();
        $invoice = Invoice::factory()->for($company)->for($customer)->create([
            'status' => 'cancelled',
            'total' => '100.00',
            'balance_due' => '0.00',
            'financial_status' => 'cancelled',
        ]);
        $this->actingAs($user);

        try {
            app(PaymentService::class)->recordPayment($invoice, [
                'amount' => '10.00',
                'payment_date' => today()->toDateString(),
                'payment_method_id' => (string) Str::uuid(),
            ]);
            $this->fail('Une facture annulée ne doit pas accepter de paiement.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invoice', $exception->errors());
        }

        $this->assertSame(0, Payment::query()->count());
    }

    private function userWithRole(Company $company, string $slug): User
    {
        $user = User::factory()->for($company)->create();
        $role = Role::query()->firstOrCreate(
            ['company_id' => $company->id, 'slug' => $slug],
            ['name' => $slug],
        );
        $permission = Permission::query()->where('slug', 'stock.view')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    /**
     * @param  list<string>  $permissionSlugs
     */
    private function userWithPermissions(Company $company, array $permissionSlugs): User
    {
        $user = User::factory()->for($company)->create();
        $role = Role::query()->create([
            'company_id' => $company->id,
            'slug' => 'stock-test-'.fake()->unique()->lexify('??????'),
            'name' => 'Stock test',
        ]);
        $permissionIds = Permission::query()->whereIn('slug', $permissionSlugs)->pluck('id');
        $role->permissions()->sync($permissionIds);
        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function warehouse(Company $company, User $user, string $suffix = 'MAIN', bool $isDefault = true): Warehouse
    {
        return Warehouse::query()->create([
            'company_id' => $company->id,
            'code' => 'WH-'.$suffix,
            'name' => 'Entrepôt '.$suffix,
            'is_default' => $isDefault,
            'is_active' => true,
            'created_by' => $user->id,
        ]);
    }
}
