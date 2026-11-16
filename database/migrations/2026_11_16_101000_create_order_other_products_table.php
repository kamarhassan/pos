<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('order_other_products', function (Blueprint $table) {
            $table->id(); $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('name'); $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('quantity')->default(1); $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('sub_total', 10, 2)->default(0); $table->decimal('total', 10, 2)->default(0); $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('order_other_products'); }
};
