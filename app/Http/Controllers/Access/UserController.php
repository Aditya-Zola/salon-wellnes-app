<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Models\User;
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
        $users = User::query()->with('roles')->orderBy('name')->get();
        $roles = $this->availableRoles(request()->user());

        return view('access.users.index', compact('users', 'roles'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role_id' => ['required', Rule::exists(config('permission.table_names.roles'), 'id')],
        ]);

        $role = Role::findById((int) $validated['role_id']);

        if ($role->name === 'super-admin' && ! $request->user()->isSuperAdmin()) {
            abort(403);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);
        $user->syncRoles([$role]);

        return redirect()->route('access.users.index')
            ->with('success', 'Pengguna berhasil ditambahkan.');
    }

    public function edit(User $user): View
    {
        if ($user->isSuperAdmin() && ! request()->user()->isSuperAdmin()) {
            abort(403);
        }

        $user->load(['roles.permissions', 'permissions']);
        $roles = $this->availableRoles(request()->user());
        $permissionGroups = $this->availablePermissions(request()->user())->groupBy('group');

        return view('access.users.edit', compact('user', 'roles', 'permissionGroups'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'email',
                'max:150',
                Rule::unique('users', 'email')->ignore($user),
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'role_id' => ['required', Rule::exists(config('permission.table_names.roles'), 'id')],
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
            'email' => $validated['email'],
        ]);

        if ($validated['password'] ?? null) {
            $user->password = $validated['password'];
        }

        $user->save();
        $user->syncRoles([$role]);

        if ($role->name !== 'super-admin') {
            // Direct permissions are personal additions to the selected role. Role
            // permissions stay intact, so changing one user never changes colleagues.
            $user->syncPermissions(Permission::query()->whereKey($permissionIds)->get());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('access.users.index')
            ->with('success', 'Pengguna berhasil diperbarui.');
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
            ->with('success', 'Pengguna berhasil dihapus.');
    }

    private function availableRoles(User $actor): Collection
    {
        return Role::query()
            ->when(! $actor->isSuperAdmin(), fn ($query) => $query->where('name', '!=', 'super-admin'))
            ->orderBy('display_name')
            ->get();
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
