<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_viewer_cannot_edit_or_archive_customer(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->for($company)->create(['legal_name' => 'Client protégé']);
        $viewer = $this->createUserWithPermissions($company, ['customer.view', 'customer.create']);
        $this->actingAs($viewer);

        $this->get(route('customers.edit', $customer))->assertForbidden();
        $this->put(route('customers.update', $customer), [
            'type' => 'company',
            'legal_name' => 'Modification interdite',
        ])->assertForbidden();
        $this->delete(route('customers.destroy', $customer))->assertForbidden();

        $this->assertSame('Client protégé', $customer->fresh()->legal_name);
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'deleted_at' => null]);
    }

    public function test_customer_creation_requires_customer_create_permission(): void
    {
        $company = Company::factory()->create();
        $viewer = $this->createUserWithPermissions($company, ['customer.view']);

        $this->actingAs($viewer)->post(route('customers.store'), [
            'type' => 'individual',
            'first_name' => 'Non autorisé',
        ])->assertForbidden();

        $this->assertDatabaseCount('customers', 0);
    }

    public function test_customer_manager_can_update_and_archive_customer(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::factory()->for($company)->create();
        $manager = $this->createUserWithPermissions($company, ['customers.manage']);
        $this->actingAs($manager);

        $this->put(route('customers.update', $customer), [
            'type' => 'company',
            'legal_name' => 'Client modifié',
        ])->assertRedirect(route('customers.show', $customer));

        $this->assertSame('Client modifié', $customer->fresh()->legal_name);
        $this->delete(route('customers.destroy', $customer))->assertRedirect(route('customers.index'));
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }
}
