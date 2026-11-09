<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SeededAdminEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_shows_the_kamareyecare_email(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSeeText('kamareyecare@gmail.com')
            ->assertDontSeeText('demo@qtecsolution.net');
    }

    public function test_demo_user_check_uses_the_kamareyecare_email(): void
    {
        $this->assertTrue(demoUserCheck('kamareyecare@gmail.com'));
        $this->assertFalse(demoUserCheck('demo@qtecsolution.net'));
    }

    public function test_email_migration_updates_the_existing_seeded_admin(): void
    {
        $user = User::factory()->create([
            'email' => 'demo@qtecsolution.net',
            'username' => 'legacy-seeded-admin',
        ]);
        $migration = require database_path('migrations/2026_11_09_130000_update_seeded_admin_email.php');

        $migration->up();

        $this->assertSame('kamareyecare@gmail.com', $user->fresh()->email);
    }

    public function test_email_migration_reports_a_conflicting_existing_account(): void
    {
        User::factory()->create([
            'email' => 'demo@qtecsolution.net',
            'username' => 'legacy-seeded-admin',
        ]);
        User::factory()->create([
            'email' => 'kamareyecare@gmail.com',
            'username' => 'kamareyecare-admin',
        ]);
        $migration = require database_path('migrations/2026_11_09_130000_update_seeded_admin_email.php');

        $this->expectException(RuntimeException::class);
        $migration->up();
    }
}
