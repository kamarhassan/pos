<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class KamareyecareProductSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/kamareyecare-products.csv');
        $file = fopen($path, 'r');

        if ($file === false) {
            throw new RuntimeException("Unable to open product seed data: {$path}");
        }

        try {
            fgetcsv($file);
            $brands = [];
            $categories = [];

            DB::transaction(function () use ($file, &$brands, &$categories) {
                while (($row = fgetcsv($file)) !== false) {
                    if (count($row) !== 6) {
                        throw new RuntimeException('Invalid row in Kamareyecare product seed data.');
                    }

                    [$sku, $brandName, $categoryName, $quantity, $purchasePrice, $price] = $row;
                    $brands[$brandName] ??= Brand::firstOrCreate(['name' => $brandName]);
                    $category = null;

                    if ($categoryName !== '') {
                        $categoryKey = strtolower(trim($categoryName));
                        $categories[$categoryKey] ??= Category::whereRaw('LOWER(name) = ?', [$categoryKey])
                            ->first() ?? Category::create(['name' => ucfirst($categoryKey)]);
                        $category = $categories[$categoryKey];
                    }

                    Product::firstOrCreate(
                        [
                            'brand_id' => $brands[$brandName]->id,
                            'sku' => $sku,
                        ],
                        [
                            'name' => $brandName,
                            'category_id' => $category?->id,
                            'unit_id' => null,
                            'price' => (float) $price,
                            'purchase_price' => (float) $purchasePrice,
                            'discount' => 0,
                            'discount_type' => 'fixed',
                            'quantity' => (int) $quantity,
                            'status' => 1,
                        ]
                    );
                }
            });
        } finally {
            fclose($file);
        }
    }
}
