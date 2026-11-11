<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderTransaction;
use App\Models\Patient;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PatientFrameStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_can_be_edited_when_patient_frames_table_is_missing(): void
    {
        $user = User::factory()->create(['username' => 'legacy-frame-user']);
        $user->givePermissionTo(
            collect(['customer_create', 'customer_update', 'customer_delete', 'customer_sales'])
                ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']))
        );
        $frame = $this->createProduct('Legacy frame', 3);
        $patient = Patient::create([
            'full_name' => 'Legacy patient',
            'frame_product_id' => $frame->id,
            'frame_chosen' => $frame->name,
            'frame_price' => 90,
            'frame_quantity' => 1,
            'lens_quantity' => 0,
        ]);
        Schema::drop('patient_frames');

        $this->actingAs($user)
            ->get(route('backend.admin.patients.edit', $patient->id))
            ->assertOk()
            ->assertSee('Only one frame can be saved')
            ->assertSee('value="' . $frame->id . '"', false);

        $this->actingAs($user)->put(route('backend.admin.patients.update', $patient->id), [
            'full_name' => 'Updated legacy patient',
            'frames' => [['product_id' => $frame->id, 'quantity' => 2]],
            'lens_quantity' => 0,
        ])->assertRedirect(route('backend.admin.patients.index'));

        $this->assertSame('Updated legacy patient', $patient->fresh()->full_name);
        $this->assertSame($frame->id, $patient->fresh()->frame_product_id);
        $this->assertSame(2, $patient->fresh()->frame_quantity);
    }

    public function test_saving_patient_or_editing_frame_does_not_create_a_sale_or_change_stock(): void
    {
        $user = $this->userWithPatientPermissions('frame-stock-user');
        $frame = $this->createProduct('Frame', 5);

        $this->actingAs($user)
            ->get(route('backend.admin.patients.create'))
            ->assertOk()
            ->assertSee('Add another frame')
            ->assertSee('frames[0][product_id]')
            ->assertSee('frame-row-template');

        $this->actingAs($user)->post(route('backend.admin.patients.store'), [
            'full_name' => 'Frame patient',
            'frame_product_id' => $frame->id,
            'frame_quantity' => 1,
            'lens_quantity' => 0,
        ])->assertRedirect(route('backend.admin.patients.index'));

        $patient = Patient::firstOrFail();
        $this->assertSame($frame->id, $patient->frame_product_id);
        $this->assertSame($frame->name, $patient->frame_chosen);
        $this->assertEquals(90, $patient->frame_price);
        $this->assertSame(5, $frame->fresh()->quantity);
        $this->assertSame(0, Order::count());

        $this->actingAs($user)->put(route('backend.admin.patients.update', $patient->id), [
            'full_name' => $patient->full_name,
            'notes' => 'Updated prescription note',
            'frame_product_id' => $frame->id,
            'frame_quantity' => 2,
            'lens_quantity' => 0,
        ])->assertRedirect(route('backend.admin.patients.index'));

        $this->assertSame(5, $frame->fresh()->quantity);
        $this->assertSame(0, Order::count());
    }

    public function test_patient_frame_sale_creates_order_and_initial_payment_and_decreases_stock_once(): void
    {
        $user = $this->userWithPatientPermissions('frame-sale-user');
        $frame = $this->createProduct('Sale frame', 5);

        $response = $this->actingAs($user)->post(route('backend.admin.patients.store'), [
            'full_name' => 'Patient sale',
            'frame_product_id' => $frame->id,
            'frame_quantity' => 2,
            'lens_quantity' => 0,
            'paid' => 25,
            'patient_action' => 'sell_frame',
        ]);

        $patient = Patient::firstOrFail();
        $order = Order::with('products')->firstOrFail();
        $response->assertRedirect(route('backend.admin.orders.invoice', $order->id));
        $this->assertSame($patient->legacy_customer_id, $order->customer_id);
        $this->assertSame($user->id, $order->user_id);
        $this->assertEquals(180, $order->total);
        $this->assertEquals(25, $order->paid);
        $this->assertEquals(155, $order->due);
        $this->assertFalse((bool) $order->status);
        $this->assertSame(1, $order->products->count());
        $this->assertSame(2, $order->products->first()->quantity);
        $this->assertSame($frame->id, $order->products->first()->product_id);
        $this->assertEquals(25, OrderTransaction::firstOrFail()->amount);
        $this->assertSame(3, $frame->fresh()->quantity);

        $this->actingAs($user)
            ->post(route('backend.admin.due.collection', $order->id), ['amount' => 55])
            ->assertRedirect();

        $this->assertEquals(80, $order->fresh()->paid);
        $this->assertEquals(100, $order->fresh()->due);
        $this->assertSame(2, OrderTransaction::where('order_id', $order->id)->count());

        $this->actingAs($user)
            ->get(route('backend.admin.patients.orders', $patient->id))
            ->assertOk()
            ->assertSee('Collect payment');

        $this->actingAs($user)
            ->from(route('backend.admin.due.collection', $order->id))
            ->post(route('backend.admin.due.collection', $order->id), ['amount' => 101])
            ->assertSessionHasErrors('amount');

        $this->assertEquals(80, $order->fresh()->paid);
        $this->assertEquals(100, $order->fresh()->due);
        $this->assertSame(2, OrderTransaction::where('order_id', $order->id)->count());

        $this->actingAs($user)
            ->delete(route('backend.admin.patients.destroy', $patient->id))
            ->assertRedirect(route('backend.admin.patients.index'));

        $this->assertSame(3, $frame->fresh()->quantity);
    }

    public function test_patient_frame_sale_can_include_multiple_products_and_store_them_on_the_patient(): void
    {
        $user = $this->userWithPatientPermissions('multiple-frame-sale-user');
        $firstFrame = $this->createProduct('First sale frame', 5);
        $secondFrame = $this->createProduct('Second sale frame', 4);

        $response = $this->actingAs($user)->post(route('backend.admin.patients.store'), [
            'full_name' => 'Multiple frame patient',
            'frames' => [
                ['product_id' => $firstFrame->id, 'quantity' => 2],
                ['product_id' => $secondFrame->id, 'quantity' => 1],
            ],
            'lens_quantity' => 0,
            'paid' => 50,
            'patient_action' => 'sell_frame',
        ]);

        $patient = Patient::with('frames')->firstOrFail();
        $order = Order::with('products')->firstOrFail();
        $response->assertRedirect(route('backend.admin.orders.invoice', $order->id));
        $this->assertCount(2, $patient->frames);
        $this->assertSame($firstFrame->id, $patient->frame_product_id);
        $this->assertSame(2, $patient->frame_quantity);
        $this->assertSame($firstFrame->id, $patient->frames[0]->product_id);
        $this->assertSame($secondFrame->id, $patient->frames[1]->product_id);
        $this->assertCount(2, $order->products);
        $this->assertEquals(270, $order->total);
        $this->assertEquals(50, $order->paid);
        $this->assertEquals(220, $order->due);
        $this->assertSame(2, $order->products->firstWhere('product_id', $firstFrame->id)->quantity);
        $this->assertSame(1, $order->products->firstWhere('product_id', $secondFrame->id)->quantity);
        $this->assertSame(3, $firstFrame->fresh()->quantity);
        $this->assertSame(3, $secondFrame->fresh()->quantity);
    }

    public function test_patient_can_save_multiple_frame_selections_without_selling_them(): void
    {
        $user = $this->userWithPatientPermissions('multiple-frame-save-user');
        $firstFrame = $this->createProduct('Saved first frame', 5);
        $secondFrame = $this->createProduct('Saved second frame', 4);

        $this->actingAs($user)->post(route('backend.admin.patients.store'), [
            'full_name' => 'Multiple frame record',
            'frames' => [
                ['product_id' => $firstFrame->id, 'quantity' => 1],
                ['product_id' => $secondFrame->id, 'quantity' => 2],
            ],
            'lens_quantity' => 0,
            'patient_action' => 'save',
        ])->assertRedirect(route('backend.admin.patients.index'));

        $patient = Patient::with('frames')->firstOrFail();
        $this->assertCount(2, $patient->frames);
        $this->assertSame($firstFrame->id, $patient->frame_product_id);
        $this->assertSame($secondFrame->id, $patient->frames[1]->product_id);
        $this->assertSame(5, $firstFrame->fresh()->quantity);
        $this->assertSame(4, $secondFrame->fresh()->quantity);
        $this->assertSame(0, Order::count());
    }

    public function test_failed_frame_sale_rolls_back_patient_changes_and_stock(): void
    {
        $user = $this->userWithPatientPermissions('frame-sale-validation-user');
        $frame = $this->createProduct('Limited frame', 1);
        $patient = Patient::create([
            'full_name' => 'Existing patient',
            'lens_quantity' => 0,
        ]);
        $unavailableFrame = $this->createProduct('Unavailable frame', 0);

        $this->actingAs($user)
            ->from(route('backend.admin.patients.edit', $patient->id))
            ->put(route('backend.admin.patients.update', $patient->id), [
                'full_name' => 'Changed patient name',
                'frame_product_id' => $unavailableFrame->id,
                'frame_quantity' => 1,
                'lens_quantity' => 0,
                'paid' => 0,
                'patient_action' => 'sell_frame',
            ])
            ->assertSessionHasErrors('frames.0.quantity');

        $this->assertSame('Existing patient', $patient->fresh()->full_name);
        $this->assertNull($patient->fresh()->frame_product_id);
        $this->assertSame(1, $frame->fresh()->quantity);
        $this->assertSame(0, $unavailableFrame->fresh()->quantity);
        $this->assertSame(0, Order::count());
    }

    public function test_patient_can_settle_the_sale_by_discounting_the_remaining_balance(): void
    {
        $user = $this->userWithPatientPermissions('frame-sale-discount-user');
        $frame = $this->createProduct('Discounted balance frame', 2);

        $response = $this->actingAs($user)->post(route('backend.admin.patients.store'), [
            'full_name' => 'Discounted sale patient',
            'frame_product_id' => $frame->id,
            'frame_quantity' => 1,
            'lens_quantity' => 0,
            'paid' => 85,
            'waive_remaining' => 1,
            'patient_action' => 'sell_frame',
        ]);

        $order = Order::with('products')->firstOrFail();
        $response->assertRedirect(route('backend.admin.orders.invoice', $order->id));
        $saleLine = $order->products->firstOrFail();
        $this->assertEquals(85, $order->sub_total - $order->discount);
        $this->assertEquals(85, $order->total);
        $this->assertEquals(85, $order->paid);
        $this->assertSame(0.0, (float) $order->due);
        $this->assertTrue((bool) $order->status);
        $this->assertEquals(15, $order->discount);
        $this->assertEquals(85, $saleLine->total);
        $this->assertEquals(15, $saleLine->discount);
        $this->assertEquals(85, OrderTransaction::firstOrFail()->amount);
        $this->assertSame(1, $frame->fresh()->quantity);
    }

    public function test_initial_payment_cannot_exceed_sale_total_and_saving_is_rolled_back(): void
    {
        $user = $this->userWithPatientPermissions('frame-sale-payment-user');
        $frame = $this->createProduct('Payment frame', 2);

        $this->actingAs($user)
            ->from(route('backend.admin.patients.create'))
            ->post(route('backend.admin.patients.store'), [
                'full_name' => 'Invalid payment patient',
                'frame_product_id' => $frame->id,
                'frame_quantity' => 1,
                'lens_quantity' => 0,
                'paid' => 100,
                'patient_action' => 'sell_frame',
            ])
            ->assertSessionHasErrors('paid');

        $this->assertSame(0, Patient::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(2, $frame->fresh()->quantity);
    }

    private function userWithPatientPermissions(string $username): User
    {
        $user = User::factory()->create(['username' => $username]);
        $user->givePermissionTo(
            Permission::create(['name' => 'customer_create', 'guard_name' => 'web']),
            Permission::create(['name' => 'customer_update', 'guard_name' => 'web']),
            Permission::create(['name' => 'customer_delete', 'guard_name' => 'web']),
            Permission::create(['name' => 'customer_sales', 'guard_name' => 'web'])
        );

        return $user;
    }

    private function createProduct(string $name, int $quantity): Product
    {
        return Product::create([
            'name' => $name,
            'sku' => strtolower(str_replace(' ', '-', $name)),
            'price' => 100,
            'discount' => 10,
            'discount_type' => 'fixed',
            'purchase_price' => 50,
            'quantity' => $quantity,
            'status' => 1,
        ]);
    }
}
