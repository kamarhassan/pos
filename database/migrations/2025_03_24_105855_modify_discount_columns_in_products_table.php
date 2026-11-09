<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite keeps the original product defaults used by the application.
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->double('discount', 10, 2)->nullable()->change();
            $table->string('discount_type')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->double('discount', 10, 2)->default(0)->change();
            $table->string('discount_type')->default('fixed')->change();
        });
    }
};
