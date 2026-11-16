<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('lens_discount', 10, 2)->default(0)->after('lens_quantity');
            $table->decimal('lens_total', 10, 2)->default(0)->after('lens_discount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['lens_discount', 'lens_total']);
        });
    }
};
