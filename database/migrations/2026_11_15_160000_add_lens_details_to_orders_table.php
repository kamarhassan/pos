<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('lens_name')->nullable()->after('customer_id');
            $table->decimal('lens_price', 10, 2)->default(0)->after('lens_name');
            $table->unsignedInteger('lens_quantity')->default(0)->after('lens_price');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['lens_name', 'lens_price', 'lens_quantity']);
        });
    }
};