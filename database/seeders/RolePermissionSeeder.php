<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = (array) config('permission.permissions', []);

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $roles = (array) config('permission.roles', []);

        $matrix = [
            'super-admin' => $permissions,
            'admin' => array_values(array_diff($permissions, ['manage system settings'])),
            'agent' => [
                'view dashboard',
                'view draws',
                'view results',
                'manage wallet',
                'view transaction history',
                'view commissions',
            ],
            'player' => [
                'view dashboard',
                'place bets',
                'view draws',
                'view results',
                'manage wallet',
                'deposit funds',
                'withdraw funds',
                'view transaction history',
            ],
            'auditor' => [
                'view dashboard',
                'view audit logs',
                'view financial reports',
                'view transaction history',
                'reconcile ledger',
            ],
        ];

        foreach ($roles as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions($matrix[$roleName] ?? []);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
