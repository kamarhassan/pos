<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DashboardSqliteTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_loads_with_sqlite(): void
    {
        $user = User::factory()->create(['username' => 'sqlite-dashboard-user']);
        $user->givePermissionTo(Permission::create([
            'name' => 'dashboard_view',
            'guard_name' => 'web',
        ]));

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk();
    }
}
