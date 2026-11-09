<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_a_named_backup(): void
    {
        Storage::fake('local');
        $filename = 'qpos-backup-20261109-123000-AbCd1234.sqlite';
        Storage::disk('local')->put("database-backups/{$filename}", 'backup');
        $user = $this->adminUser();

        $this->actingAs($user)
            ->delete(route('backend.admin.settings.database.destroy', $filename))
            ->assertRedirect(route('backend.admin.settings.database'))
            ->assertSessionHas('success', "Backup deleted: {$filename}");

        Storage::disk('local')->assertMissing("database-backups/{$filename}");
    }

    public function test_backup_delete_rejects_files_outside_the_backup_name_format(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('database-backups/other.sqlite', 'not a QPOS backup');
        $user = $this->adminUser();

        $this->actingAs($user)
            ->delete(route('backend.admin.settings.database.destroy', 'other.sqlite'))
            ->assertNotFound();

        Storage::disk('local')->assertExists('database-backups/other.sqlite');
    }

    public function test_admin_role_without_database_backup_permission_is_denied(): void
    {
        $user = User::factory()->create(['username' => 'backup-no-permission']);
        $user->assignRole(Role::create(['name' => 'Admin', 'guard_name' => 'web']));

        $this->actingAs($user)
            ->get(route('backend.admin.settings.database'))
            ->assertForbidden();
    }

    private function adminUser(): User
    {
        $user = User::factory()->create(['username' => 'backup-admin']);
        $user->assignRole(Role::create(['name' => 'Admin', 'guard_name' => 'web']));
        $user->givePermissionTo(Permission::firstOrCreate([
            'name' => 'database_backup',
            'guard_name' => 'web',
        ]));

        return $user;
    }
}
