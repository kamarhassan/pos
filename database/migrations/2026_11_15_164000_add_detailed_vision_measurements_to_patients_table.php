<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('power_right_axis', 50)->nullable()->after('power_right_cylinder');
            $table->string('power_right_va', 50)->nullable()->after('power_right_addition');
            $table->string('power_left_axis', 50)->nullable()->after('power_left_cylinder');
            $table->string('power_left_va', 50)->nullable()->after('power_left_addition');
            $table->string('power_pd', 50)->nullable()->after('power_left_va');
            $table->boolean('power_near')->default(false)->after('power_pd');
            $table->boolean('power_dist')->default(false)->after('power_near');
            $table->boolean('power_use')->default(false)->after('power_dist');
            $table->boolean('power_progressive')->default(false)->after('power_use');
            $table->boolean('power_bifocal')->default(false)->after('power_progressive');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn([
                'power_right_axis', 'power_right_va', 'power_left_axis', 'power_left_va',
                'power_pd', 'power_near', 'power_dist', 'power_use', 'power_progressive', 'power_bifocal',
            ]);
        });
    }
};
