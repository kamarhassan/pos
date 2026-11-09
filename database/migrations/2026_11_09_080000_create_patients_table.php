<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('full_name');
            $table->string('phone', 50)->nullable();
            $table->string('email', 255)->nullable();
            $table->text('medical_history')->nullable();
            $table->text('prescription_details')->nullable();
            $table->string('frame_chosen')->nullable();
            $table->decimal('frame_price', 10, 2)->nullable();
            $table->integer('frame_quantity')->default(0);
            $table->string('lens_chosen')->nullable();
            $table->decimal('lens_price', 10, 2)->nullable();
            $table->integer('lens_quantity')->default(0);
            $table->string('others_chosen')->nullable();
            $table->text('notes')->nullable();
            $table->string('power_right_sphere', 50)->nullable();
            $table->string('power_right_cylinder', 50)->nullable();
            $table->string('power_right_addition', 50)->nullable();
            $table->string('power_left_sphere', 50)->nullable();
            $table->string('power_left_cylinder', 50)->nullable();
            $table->string('power_left_addition', 50)->nullable();
            $table->text('power_notes')->nullable();
            $table->foreignId('legacy_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('customers')->orderBy('id')->chunk(200, function ($customers) {
            foreach ($customers as $customer) {
                DB::table('patients')->insert([
                    'id' => (string) Str::uuid(),
                    'full_name' => $customer->name,
                    'phone' => $customer->phone,
                    'notes' => $customer->address,
                    'legacy_customer_id' => $customer->id,
                    'created_at' => $customer->created_at,
                    'updated_at' => $customer->updated_at,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
