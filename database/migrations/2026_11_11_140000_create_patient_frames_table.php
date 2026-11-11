<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_frames', function (Blueprint $table) {
            $table->id();
            $table->uuid('patient_id');
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('frame_name');
            $table->decimal('frame_price', 10, 2)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
        });

        DB::table('patients')
            ->where(function ($query) {
                $query->whereNotNull('frame_chosen')
                    ->orWhereNotNull('frame_product_id');
            })
            ->orderBy('id')
            ->chunkById(200, function ($patients) {
                foreach ($patients as $patient) {
                    DB::table('patient_frames')->insert([
                        'patient_id' => $patient->id,
                        'product_id' => $patient->frame_product_id,
                        'frame_name' => $patient->frame_chosen ?: 'Frame',
                        'frame_price' => $patient->frame_price,
                        'quantity' => max(1, (int) $patient->frame_quantity),
                        'created_at' => $patient->created_at,
                        'updated_at' => $patient->updated_at,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_frames');
    }
};
