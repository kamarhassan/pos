<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Patient;
use Illuminate\Database\Seeder;

class NoCustomerSeeder extends Seeder
{
    public function run(): void
    {
        $customer = Customer::firstOrCreate(['name' => 'No Customer']);

        Patient::firstOrCreate(
            ['legacy_customer_id' => $customer->id],
            ['full_name' => 'No Customer']
        );
    }
}
