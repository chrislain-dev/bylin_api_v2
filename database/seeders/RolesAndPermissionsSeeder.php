<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            // Back-office access
            'admin.access',

            // User Management
            'users.view', 'users.create', 'users.update', 'users.delete',

            // Customer Management
            'customers.view', 'customers.create', 'customers.update', 'customers.delete',

            // Catalogue / Inventory
            'catalogue.view', 'catalogue.create', 'catalogue.update', 'catalogue.delete',
            'inventory.manage',

            // Orders / Shipping / Payments
            'orders.view', 'orders.update', 'orders.cancel', 'orders.delete',
            'shipping.view', 'shipping.create', 'shipping.update', 'shipping.delete',
            'payments.view', 'payments.update', 'payments.refund',

            // Marketing
            'promotions.view', 'promotions.create', 'promotions.update', 'promotions.delete',
            'reviews.manage',

            // Settings & System
            'settings.view', 'settings.update',
            'authenticity.manage',
            'reports.view',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdminRole->syncPermissions(Permission::where('guard_name', 'web')->get());

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions([
            'admin.access',
            'users.view', 'users.create', 'users.update',
            'customers.view', 'customers.create', 'customers.update',
            'catalogue.view', 'catalogue.create', 'catalogue.update', 'catalogue.delete',
            'inventory.manage',
            'orders.view', 'orders.update', 'orders.cancel',
            'shipping.view', 'shipping.create', 'shipping.update',
            'payments.view', 'payments.update',
            'promotions.view', 'promotions.create', 'promotions.update',
            'reviews.manage',
            'authenticity.manage',
            'reports.view',
        ]);

        $managerRole = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $managerRole->syncPermissions([
            'admin.access',
            'catalogue.view', 'catalogue.create', 'catalogue.update',
            'inventory.manage',
            'orders.view', 'orders.update',
            'shipping.view', 'shipping.create', 'shipping.update',
            'reviews.manage',
            'promotions.view',
            'reports.view',
        ]);

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'customer']);
    }
}
