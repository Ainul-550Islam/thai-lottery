<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Spatie Permission (top-level keys required by the package)
    |--------------------------------------------------------------------------
    */

    'models' => [
        'permission' => Spatie\Permission\Models\Permission::class,
        'role' => Spatie\Permission\Models\Role::class,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => null,
        'permission_pivot_key' => null,
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
    ],

    'register_permission_check_method' => true,
    'register_octane_reset_listener' => false,
    'events_enabled' => false,
    'teams' => false,
    'team_resolver' => \Spatie\Permission\DefaultTeamResolver::class,
    'use_passport_client_credentials' => false,
    'display_permission_in_exception' => env('PERMISSION_DISPLAY_IN_EXCEPTION', false),
    'display_role_in_exception' => env('ROLE_DISPLAY_IN_EXCEPTION', false),
    'enable_wildcard_permission' => env('PERMISSION_ENABLE_WILDCARD', false),

    'cache' => [
        'expiration_time' => \DateInterval::createFromDateString(
            env('PERMISSION_CACHE_EXPIRATION', '24 hours')
        ),
        'key' => env('PERMISSION_CACHE_KEY', 'spatie.permission.cache'),
        'store' => env('PERMISSION_CACHE_STORE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Application role & permission catalogue (project specific)
    |--------------------------------------------------------------------------
    |
    | Consumed by the role/permission seeder. Extra keys are ignored by the
    | Spatie package itself.
    |
    */

    'roles' => [
        'super_admin' => 'super-admin',
        'admin' => 'admin',
        'agent' => 'agent',
        'player' => 'player',
        'auditor' => 'auditor',
    ],

    'permissions' => [
        'view dashboard',
        'place bets',
        'view draws',
        'view results',
        'manage wallet',
        'deposit funds',
        'withdraw funds',
        'view transaction history',
        'manage agents',
        'view commissions',
        'process settlements',
        'manage draws',
        'process draws',
        'manage payouts',
        'view risk alerts',
        'manage risk limits',
        'view audit logs',
        'manage system settings',
        'manage users',
        'view financial reports',
        'reconcile ledger',
    ],

];
