<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('patient_other_products', function (Blueprint $table) {
            $table->id(); $table->uuid('patient_id');
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->string('name'); $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('quantity')->default(1); $table->decimal('discount', 10, 2)->default(0); $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('patient_other_products'); }
};
