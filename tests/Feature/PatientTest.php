<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\NoCustomerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PatientTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_customer_is_seeded_once_and_available_in_pos_customer_list(): void
    {
        $seeder = new NoCustomerSeeder();
        $seeder->run();
        $seeder->run();

        $this->assertSame(1, Customer::where('name', 'No Customer')->count());
        $this->assertSame(1, Patient::where('full_name', 'No Customer')->count());

        $user = User::factory()->create(['username' => 'no-customer-test-user']);
        $this->actingAs($user)
            ->getJson('/admin/get/customers')
            ->assertOk()
            ->assertJsonFragment(['name' => 'No Customer']);
    }

    public function test_pos_patient_creation_keeps_the_legacy_order_customer_link(): void
    {
        $user = User::factory()->create(['username' => 'patient-test-user']);
        $permission = Permission::create([
            'name' => 'customer_create',
            'guard_name' => 'web',
        ]);
        $user->givePermissionTo($permission);

        $response = $this->actingAs($user)->postJson('/admin/create/customers', [
            'name' => 'Test patient',
        ]);

        $response->assertOk()->assertJson([
            'name' => 'Test patient',
        ]);

        $patient = Patient::firstOrFail();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $patient->id
        );
        $this->assertSame($response->json('id'), $patient->legacy_customer_id);
        $this->assertSame('Test patient', Customer::findOrFail($patient->legacy_customer_id)->name);

        foreach (['First patient', 'Second patient'] as $name) {
            $this->actingAs($user)->post(route('backend.admin.patients.store'), [
                'full_name' => $name,
                'phone' => '555123',
                'frame_quantity' => 0,
                'lens_quantity' => 0,
            ])->assertRedirect(route('backend.admin.patients.index'));
        }

        $this->assertSame(3, Patient::count());
        $this->assertSame(3, Customer::count());
    }
}
