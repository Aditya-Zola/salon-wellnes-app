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

    public function test_reservation_navigation_is_split_into_queue_calendar_and_therapist_attendance_pages(): void
    {
        $role = Role::create([
            'name' => 'viewer-reservasi',
            'display_name' => 'Viewer Reservasi',
            'guard_name' => 'web',
        ]);
        $role->syncPermissions(['dashboard.view', 'reservations.view', 'therapist_attendance.view']);

        $user = User::factory()->create();
        $user->syncRoles($role);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('class="access-menu reservation-menu"', false)
            ->assertSee('data-page="reservasi-antrean"', false)
            ->assertSee('data-page="reservasi-kalender"', false)
            ->assertSee('data-page="kehadiran-terapis"', false)
            ->assertSee('id="reservasi-antrean"', false)
            ->assertSee('id="reservasi-kalender"', false)
            ->assertSee('id="kehadiran-terapis"', false)
            ->assertSee('id="therapist-attendance-calendar"', false)
            ->assertDontSee('data-reservation-view=', false);
    }

    public function test_therapist_attendance_uses_dedicated_view_and_manage_permissions(): void
    {
        $reservationViewer = Role::create([
            'name' => 'viewer-reservasi-tanpa-kehadiran',
            'display_name' => 'Viewer Reservasi Tanpa Kehadiran',
            'guard_name' => 'web',
        ]);
        $reservationViewer->syncPermissions(['dashboard.view', 'reservations.view']);
        $reservationUser = User::factory()->create();
        $reservationUser->syncRoles($reservationViewer);

        $this->actingAs($reservationUser)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-page="kehadiran-terapis"', false);
        $this->actingAs($reservationUser)
            ->getJson('/operasional/therapist-kehadiran?date=2026-08-28')
            ->assertForbidden();

        $attendanceViewer = Role::create([
            'name' => 'viewer-kehadiran-terapis',
            'display_name' => 'Viewer Kehadiran Terapis',
            'guard_name' => 'web',
        ]);
        $attendanceViewer->syncPermissions(['dashboard.view', 'therapist_attendance.view']);
        $viewerUser = User::factory()->create();
        $viewerUser->syncRoles($attendanceViewer);

        $this->actingAs($viewerUser)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-page="kehadiran-terapis"', false);
        $this->actingAs($viewerUser)
            ->getJson('/operasional/therapist-kehadiran?date=2026-08-28')
            ->assertOk();
        $this->actingAs($viewerUser)
            ->putJson('/operasional/therapist-kehadiran/999', ['date' => '2026-08-28', 'status' => 'off'])
            ->assertForbidden();

        $attendanceManager = Role::create([
            'name' => 'manager-kehadiran-terapis',
            'display_name' => 'Manager Kehadiran Terapis',
            'guard_name' => 'web',
        ]);
        $attendanceManager->syncPermissions(['dashboard.view', 'therapist_attendance.view', 'therapist_attendance.manage']);
        $managerUser = User::factory()->create();
        $managerUser->syncRoles($attendanceManager);
        $therapist = Employee::create([
            'code' => 'EMP-ATTENDANCE-PERMISSION',
            'name' => 'Ana Terapis',
            'is_service_provider' => true,
            'active' => true,
        ]);

        $this->actingAs($managerUser)
            ->putJson("/operasional/therapist-kehadiran/{$therapist->id}", ['date' => '2026-08-28', 'status' => 'off'])
            ->assertOk();
        $this->assertDatabaseHas('employee_attendances', [
            'employee_id' => $therapist->id,
            'attendance_date' => '2026-08-28',
            'status' => 'off',
        ]);
    }
}
