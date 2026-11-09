<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $reservations = DB::table('patients')
            ->select('frame_product_id')
            ->selectRaw('SUM(frame_quantity) as quantity')
            ->whereNotNull('frame_product_id')
            ->groupBy('frame_product_id')
            ->get();

        foreach ($reservations as $reservation) {
            DB::table('products')
                ->where('id', $reservation->frame_product_id)
                ->increment('quantity', (int) $reservation->quantity);
        }
    }

    public function down(): void
    {
    }
};
