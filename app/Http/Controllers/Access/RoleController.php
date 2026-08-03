<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleController extends Controller
{
    public function index(): View
    {
        $roles = Role::query()
            ->withCount(['permissions', 'users'])
            ->orderByDesc('is_system')
            ->orderBy('display_name')
            ->get();

        return view('access.roles.index', compact('roles'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:80'],
        ]);

        $name = Str::slug($validated['display_name']);

        if ($name === '' || Role::query()->where('name', $name)->where('guard_name', 'web')->exists()) {
            return back()->withErrors([
                'display_name' => 'Nama peran sudah digunakan atau tidak valid.',
            ])->withInput();
        }

        $role = Role::create([
            'name' => $name,
            'display_name' => $validated['display_name'],
            'guard_name' => 'web',
            'is_system' => false,
        ]);

        $role->givePermissionTo('dashboard.view');

        return redirect()->route('access.roles.edit', $role)
            ->with('success', 'Peran berhasil dibuat. Silakan atur hak aksesnya.');
    }

    public function edit(Role $role): View
    {
        if ($role->name === 'super-admin' && ! request()->user()->isSuperAdmin()) {
            abort(403);
        }

        $role->load('permissions');
        $permissionGroups = Permission::query()
            ->orderBy('sort_order')
            ->get()
            ->groupBy('group');

        return view('access.roles.edit', compact('role', 'permissionGroups'));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        if ($role->name === 'super-admin' && ! $request->user()->isSuperAdmin()) {
            abort(403);
        }

        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:80'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [
                'integer',
                Rule::exists(config('permission.table_names.permissions'), 'id'),
            ],
        ]);

        if (! $role->is_system) {
            $name = Str::slug($validated['display_name']);

            if ($name === '' || Role::query()
                ->where('guard_name', 'web')
                ->where('name', $name)
                ->where('id', '!=', $role->getKey())
                ->exists()) {
                return back()->withErrors([
                    'display_name' => 'Nama peran sudah digunakan atau tidak valid.',
                ])->withInput();
            }

            $role->update([
                'name' => $name,
                'display_name' => $validated['display_name'],
            ]);
        }

        if ($role->name === 'super-admin') {
            $role->syncPermissions(Permission::all());
        } else {
            $dashboardPermission = Permission::findByName('dashboard.view');
            $permissionIds = collect($validated['permissions'] ?? [])
                ->map(fn ($permissionId) => (int) $permissionId)
                ->push($dashboardPermission->id)
                ->unique();

            $role->syncPermissions(
                Permission::query()->whereKey($permissionIds)->get()
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('access.roles.index')
            ->with('success', 'Hak akses peran berhasil diperbarui.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->withErrors(['role' => 'Peran bawaan sistem tidak dapat dihapus.']);
        }

        if ($role->users()->exists()) {
            return back()->withErrors(['role' => 'Peran masih digunakan oleh pengguna.']);
        }

        $role->delete();

        return redirect()->route('access.roles.index')
            ->with('success', 'Peran berhasil dihapus.');
    }
}
