<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Patient;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Faker\Factory as Faker;
class CustomerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $faker = Faker::create();
        for ($i = 0; $i < 10; $i++) {
            $name = $faker->name();
            $phone = $faker->unique()->phoneNumber();
            $customer = Customer::create([
                'name' => $name,
                'phone' => $phone,
                'address' => $faker->address(),
            ]);

            Patient::create([
                'full_name' => $name,
                'phone' => $phone,
                'legacy_customer_id' => $customer->id,
            ]);
        }
    }
}
