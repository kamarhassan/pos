<?php

namespace Tests\Feature;

use App\Models\Product;
use Database\Seeders\KamareyecareProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KamareyecareProductSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_kamareyecare_products_are_imported_with_brand_category_stock_and_prices(): void
    {
        app(KamareyecareProductSeeder::class)->run();

        $this->assertSame(477, Product::count());
        $this->assertSame(593, (int) Product::sum('quantity'));

        $maxima = Product::where('brand_id', function ($query) {
            $query->select('id')->from('brands')->where('name', 'maxima');
        })->where('sku', 'FD5185')->firstOrFail();
        $front = Product::where('brand_id', function ($query) {
            $query->select('id')->from('brands')->where('name', 'Front');
        })->where('sku', 'FD5185')->firstOrFail();

        $this->assertNotSame($maxima->id, $front->id);
        $this->assertSame('maxima', $maxima->name);
        $this->assertSame('maxima', $maxima->brand->name);
        $this->assertSame('Front', $front->name);
        $this->assertSame('Front', $front->brand->name);
        $this->assertSame('Frames', $maxima->category->name);
        $this->assertEquals(10, $maxima->purchase_price);
        $this->assertEquals(30, $maxima->price);

        $florento = Product::where('sku', 'CH5303')->firstOrFail();
        $this->assertNull($florento->category_id);
    }

    public function test_running_product_seeder_again_does_not_duplicate_products_or_add_stock(): void
    {
        $seeder = app(KamareyecareProductSeeder::class);
        $seeder->run();
        $seeder->run();

        $this->assertSame(477, Product::count());
        $this->assertSame(593, (int) Product::sum('quantity'));
    }
}
