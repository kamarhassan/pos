<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductSkuByBrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_sku_is_allowed_for_different_brands(): void
    {
        $user = $this->productUser();
        $category = Category::create(['name' => 'Frames']);
        $firstBrand = Brand::create(['name' => 'Front']);
        $secondBrand = Brand::create(['name' => 'Maxima']);

        $this->actingAs($user)->post(route('backend.admin.products.store'), $this->productData($category, $firstBrand))
            ->assertRedirect(route('backend.admin.products.index'));

        $this->actingAs($user)->post(route('backend.admin.products.store'), $this->productData($category, $secondBrand))
            ->assertRedirect(route('backend.admin.products.index'));

        $this->assertSame(2, Product::where('sku', '1254')->count());
    }

    public function test_same_sku_is_rejected_for_the_same_brand(): void
    {
        $user = $this->productUser();
        $category = Category::create(['name' => 'Frames']);
        $brand = Brand::create(['name' => 'Front']);

        $this->actingAs($user)->post(route('backend.admin.products.store'), $this->productData($category, $brand))
            ->assertRedirect(route('backend.admin.products.index'));

        $this->actingAs($user)
            ->from(route('backend.admin.products.create'))
            ->post(route('backend.admin.products.store'), $this->productData($category, $brand, 'Another frame'))
            ->assertSessionHasErrors('sku');

        $this->assertSame(1, Product::where('sku', '1254')->count());
    }

    private function productUser(): User
    {
        $user = User::factory()->create(['username' => 'product-sku-user']);
        $user->givePermissionTo(Permission::create([
            'name' => 'product_create',
            'guard_name' => 'web',
        ]));

        return $user;
    }

    private function productData(Category $category, Brand $brand, string $name = 'Frame'): array
    {
        return [
            'name' => $name,
            'sku' => '1254',
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'price' => 100,
            'discount' => 0,
            'discount_type' => 'fixed',
            'purchase_price' => 50,
            'quantity' => 5,
            'status' => 1,
        ];
    }
}
