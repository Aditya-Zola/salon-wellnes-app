<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessControlSeeder::class);
    }

    public function test_super_admin_can_open_role_and_user_management(): void
    {
        $user = User::factory()->create();
        $user->syncRoles('super-admin');

        $this->actingAs($user)->get(route('access.roles.index'))
            ->assertOk()
            ->assertSee('Input peran baru')
            ->assertSee('Pengguna');

        $this->actingAs($user)->get(route('access.users.index'))
            ->assertOk()
            ->assertSee('Tambah pengguna');
    }

    public function test_marketing_cannot_open_access_control_pages(): void
    {
        $user = User::factory()->create();
        $user->syncRoles('marketing');

        $this->actingAs($user)->get(route('access.roles.index'))->assertForbidden();
        $this->actingAs($user)->get(route('access.users.index'))->assertForbidden();
    }

    public function test_role_can_be_created_and_permissions_can_be_checked(): void
    {
        $user = User::factory()->create();
        $user->syncRoles('super-admin');

        $this->actingAs($user)->post(route('access.roles.store'), [
            'display_name' => 'Terapis Senior',
        ])->assertRedirect();

        $role = Role::findByName('terapis-senior');
        $this->assertTrue($role->hasPermissionTo('dashboard.view'));

        $permissionIds = Permission::query()
            ->whereIn('name', ['dashboard.view', 'treatments.view', 'treatments.update'])
            ->pluck('id')
            ->map(fn ($permissionId) => (string) $permissionId)
            ->all();

        $this->actingAs($user)->put(route('access.roles.update', $role), [
            'display_name' => 'Terapis Senior',
            'permissions' => $permissionIds,
        ])->assertRedirect(route('access.roles.index'))
            ->assertSessionHas('success', 'Hak akses peran berhasil diperbarui.');

        $role->refresh();
        $this->assertTrue($role->hasAllPermissions(['dashboard.view', 'treatments.view', 'treatments.update']));
        $this->assertFalse($role->hasPermissionTo('cashier.view'));
    }

    public function test_new_user_is_assigned_to_selected_role(): void
    {
        $actor = User::factory()->create();
        $actor->syncRoles('super-admin');
        $marketing = Role::findByName('marketing');

        $this->actingAs($actor)->post(route('access.users.store'), [
            'identity' => 'marketing.baru',
            'role_id' => $marketing->id,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('access.users.index'));

        $created = User::query()->where('username', 'marketing.baru')->firstOrFail();
        $this->assertTrue($created->hasRole('marketing'));
        $this->assertCount(1, $created->roles);
        $this->assertDatabaseHas('employees', [
            'user_id' => $created->id,
            'name' => 'Marketing Baru',
        ]);
    }

    public function test_employee_can_be_recorded_without_a_system_login(): void
    {
        $actor = User::factory()->create();
        $actor->syncRoles('super-admin');

        $this->actingAs($actor)->post(route('access.users.store'), [
            'identity' => 'Dita Terapis',
            'role_id' => 'therapist',
            'specialty' => 'Hair therapist',
        ])->assertRedirect(route('access.users.index'));

        $employee = Employee::query()->where('name', 'Dita Terapis')->firstOrFail();
        $this->assertNull($employee->user_id);
        $this->assertTrue($employee->is_service_provider);
        $this->assertDatabaseMissing('users', ['name' => 'Dita Terapis']);
    }

    public function test_employee_without_history_can_be_deleted(): void
    {
        $actor = User::factory()->create();
        $actor->syncRoles('super-admin');
        $employee = Employee::create([
            'code' => 'EMP-DELETE',
            'name' => 'Karyawan Hapus',
            'is_service_provider' => true,
            'active' => true,
        ]);

        $this->actingAs($actor)->delete(route('access.users.employees.destroy', $employee))
            ->assertRedirect(route('access.users.index'));

        $this->assertModelMissing($employee);
    }

    public function test_super_admin_can_grant_a_personal_action_permission_to_a_user(): void
    {
        $actor = User::factory()->create();
        $actor->syncRoles('super-admin');

        $role = Role::create([
            'name' => 'viewer-produk',
            'display_name' => 'Viewer Produk',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions(['dashboard.view', 'products.view']);

        $user = User::factory()->create();
        $user->syncRoles($role);
        $productCreate = Permission::findByName('products.create');

        $this->actingAs($actor)->get(route('access.users.edit', $user))
            ->assertOk()
            ->assertSee('Akses tambahan per pengguna')
            ->assertSee('Tambah produk');

        $this->actingAs($actor)->put(route('access.users.update', $user), [
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role_id' => $role->id,
            'permissions' => [$productCreate->id],
        ])->assertRedirect(route('access.users.index'));

        $user->refresh();
        $this->assertTrue($user->hasDirectPermission('products.create'));
        $this->assertTrue($user->can('products.create'));
        $this->assertFalse($user->getPermissionsViaRoles()->contains('name', 'products.create'));
    }

    public function test_sidebar_only_shows_modules_allowed_for_the_role(): void
    {
        $role = Role::create([
            'name' => 'viewer-treatment',
            'display_name' => 'Viewer Treatment',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions(['dashboard.view', 'treatments.view']);

        $user = User::factory()->create();
        $user->syncRoles($role);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-page="treatment"', false)
            ->assertDontSee('data-page="kasir"', false)
            ->assertSee('#treatment .toolbar>.primary{display:none!important}', false)
            ->assertDontSee('Hak Akses');
    }
}
