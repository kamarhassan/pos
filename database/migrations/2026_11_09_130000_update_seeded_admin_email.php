<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $oldEmail = 'demo@qtecsolution.net';
        $newEmail = 'kamareyecare@gmail.com';

        if (!DB::table('users')->where('email', $oldEmail)->exists()) {
            return;
        }

        if (DB::table('users')->where('email', $newEmail)->exists()) {
            throw new RuntimeException("Cannot replace {$oldEmail}: a user with {$newEmail} already exists.");
        }

        DB::table('users')->where('email', $oldEmail)->update(['email' => $newEmail]);
    }

    public function down(): void
    {
        // The email may have been changed by the account owner after migration.
    }
};
