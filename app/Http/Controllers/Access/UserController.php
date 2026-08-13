<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller
{
    public function index(): View
    {
        $users = User::query()->with(['roles', 'employee'])->orderBy('name')->get();
        $employeesWithoutAccount = Employee::query()
            ->whereNull('user_id')
            ->orderBy('name')
            ->get();
        $roles = $this->availableRoles(request()->user());

        return view('access.users.index', compact('users', 'employeesWithoutAccount', 'roles'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'identity' => ['required', 'string', 'min:3', 'max:100'],
            'role_id' => ['required'],
            'specialty' => ['required_if:role_id,therapist', 'nullable', 'string', 'max:150'],
            'password' => ['required_unless:role_id,therapist', 'nullable', 'string', 'min:8', 'confirmed'],
        ]);

        $isTherapist = $validated['role_id'] === 'therapist';
        $role = $isTherapist ? null : Role::findById((int) $validated['role_id']);
        abort_unless($isTherapist || $role, 422, 'Peran tidak ditemukan.');

        if ($role?->name === 'super-admin' && ! $request->user()->isSuperAdmin()) {
            abort(403);
        }

        if (! $isTherapist) {
            validator(['username' => $validated['identity']], [
                'username' => ['regex:/^[A-Za-z0-9._-]+$/', 'max:40', 'unique:users,username'],
            ])->validate();
        }

        DB::transaction(function () use ($validated, $isTherapist, $role): void {
            if ($isTherapist) {
                Employee::create([
                    'code' => 'EMP-'.Str::upper(Str::random(8)),
                    'name' => $validated['identity'],
                    'position' => 'Therapist',
                    'specialty' => $validated['specialty'],
                    'is_service_provider' => true,
                    'active' => true,
                ]);

                return;
            }

            $username = Str::lower($validated['identity']);
            $user = User::create([
                'name' => $this->displayNameFromUsername($username),
                'username' => $username,
                'password' => $validated['password'],
            ]);
            $user->syncRoles([$role]);

            Employee::create([
                'user_id' => $user->id,
                'code' => 'EMP-'.Str::upper(Str::random(8)),
                'name' => $user->name,
                'position' => $role->display_name ?: $role->name,
                'specialty' => null,
                'is_service_provider' => false,
                'active' => true,
            ]);
        });

        return redirect()->route('access.users.index')
            ->with('success', $isTherapist ? 'Terapis berhasil ditambahkan tanpa akses login.' : 'Akun pengguna berhasil ditambahkan.');
    }

    public function edit(User $user): View
    {
        if ($user->isSuperAdmin() && ! request()->user()->isSuperAdmin()) {
            abort(403);
        }

        $user->load(['roles.permissions', 'permissions', 'employee']);
        $roles = $this->availableRoles(request()->user());
        $permissionGroups = $this->availablePermissions(request()->user())->groupBy('group');

        return view('access.users.edit', compact('user', 'roles', 'permissionGroups'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'username' => [
                'required',
                'string',
                'min:3',
                'max:150',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('users', 'username')->ignore($user),
            ],
            'email' => ['nullable', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role_id' => ['required', Rule::exists(config('permission.table_names.roles'), 'id')],
            'position' => ['nullable', 'string', 'max:100'],
            'specialty' => ['nullable', 'string', 'max:150'],
            'is_service_provider' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [
                'integer',
                Rule::exists(config('permission.table_names.permissions'), 'id'),
            ],
        ]);

        $role = Role::findById((int) $validated['role_id']);

        if ($role->name === 'super-admin' && ! $request->user()->isSuperAdmin()) {
            abort(403);
        }

        if ($request->user()->is($user) && ! $user->hasRole($role)) {
            return back()->withErrors([
                'role_id' => 'Anda tidak dapat mengubah peran akun sendiri.',
            ])->withInput();
        }

        if ($user->isSuperAdmin() && $role->name !== 'super-admin' && User::role('super-admin')->count() <= 1) {
            return back()->withErrors([
                'role_id' => 'Minimal satu akun Super Admin harus tetap tersedia.',
            ])->withInput();
        }

        $permissionIds = collect($validated['permissions'] ?? [])
            ->map(fn ($permissionId) => (int) $permissionId)
            ->unique()
            ->values();

        if ($role->name !== 'super-admin') {
            $allowedPermissionIds = $this->availablePermissions($request->user())->pluck('id');
            abort_unless($permissionIds->diff($allowedPermissionIds)->isEmpty(), 403);
        }

        $user->fill([
            'name' => $validated['name'],
            'username' => Str::lower($validated['username']),
            'email' => $validated['email'] ?? null,
        ]);

        if ($validated['password'] ?? null) {
            $user->password = $validated['password'];
        }

        $user->save();
        $user->syncRoles([$role]);

        Employee::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'code' => 'EMP-'.Str::upper(Str::random(8)),
                'name' => $validated['name'],
                'active' => true,
            ],
        )->update([
            'name' => $validated['name'],
            'position' => $validated['position'] ?? null,
            'specialty' => $validated['specialty'] ?? null,
            'is_service_provider' => (bool) ($validated['is_service_provider'] ?? false),
            'active' => ! array_key_exists('active', $validated) || (bool) $validated['active'],
        ]);

        if ($role->name !== 'super-admin') {
            // Direct permissions are personal additions to the selected role. Role
            // permissions stay intact, so changing one user never changes colleagues.
            $user->syncPermissions(Permission::query()->whereKey($permissionIds)->get());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('access.users.index')
            ->with('success', 'Data karyawan dan akses login berhasil diperbarui.');
    }

    public function editEmployee(Employee $employee): View
    {
        abort_if($employee->user_id !== null, 404);

        return view('access.users.edit-employee', compact('employee'));
    }

    public function updateEmployee(Request $request, Employee $employee): RedirectResponse
    {
        abort_if($employee->user_id !== null, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:100'],
            'specialty' => ['nullable', 'string', 'max:150'],
            'is_service_provider' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ]);

        $employee->update([
            'name' => $validated['name'],
            'position' => $validated['position'] ?? null,
            'specialty' => $validated['specialty'] ?? null,
            'is_service_provider' => (bool) ($validated['is_service_provider'] ?? false),
            'active' => ! array_key_exists('active', $validated) || (bool) $validated['active'],
        ]);

        return redirect()->route('access.users.index')
            ->with('success', 'Data karyawan berhasil diperbarui.');
    }

    public function destroyEmployee(Employee $employee): RedirectResponse
    {
        abort_if($employee->user_id !== null, 404);

        $hasOperationalHistory = DB::table('reservation_item_staff')
            ->where('employee_id', $employee->id)
            ->exists()
            || DB::table('payrolls')->where('employee_id', $employee->id)->exists();

        if ($hasOperationalHistory) {
            $employee->update(['active' => false]);

            return redirect()->route('access.users.index')
                ->with('success', 'Karyawan memiliki riwayat operasional, sehingga dinonaktifkan agar histori tetap aman.');
        }

        $employee->delete();

        return redirect()->route('access.users.index')
            ->with('success', 'Karyawan berhasil dihapus.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()->is($user)) {
            return back()->withErrors(['user' => 'Anda tidak dapat menghapus akun sendiri.']);
        }

        if ($user->isSuperAdmin()) {
            return back()->withErrors(['user' => 'Akun Super Admin tidak dapat dihapus.']);
        }

        $user->delete();

        return redirect()->route('access.users.index')
            ->with('success', 'Akses login berhasil dicabut. Data karyawan tetap tersimpan.');
    }

    private function availableRoles(User $actor): Collection
    {
        return Role::query()
            ->when(! $actor->isSuperAdmin(), fn ($query) => $query->where('name', '!=', 'super-admin'))
            ->orderBy('display_name')
            ->get();
    }

    private function displayNameFromUsername(string $username): string
    {
        return Str::of($username)
            ->replace(['.', '_', '-'], ' ')
            ->title()
            ->value();
    }

    private function availablePermissions(User $actor): Collection
    {
        return Permission::query()
            ->when(
                ! $actor->isSuperAdmin(),
                fn ($query) => $query->whereIn('id', $actor->getAllPermissions()->pluck('id')),
            )
            ->orderBy('sort_order')
            ->get();
    }
}
