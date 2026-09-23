<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Department;
use App\Models\System;
use App\Models\User;
use App\Models\SystemUserRole;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $department = Department::pluck('id', 'name');  // ['総務部' => 1, '営業部' => 2, ...]
        $inventory = System::where('key', 'inventory')->firstOrFail();

        $rows = [
            [
                'name'            => '基盤 管理者',
                'email'           => 'admin@example.com',
                'department'      => '総務部',
                'is_system_admin' => true,
                'role'            => null,
            ],
            [
                'name'            => '在庫 管理者',
                'email'           => 'inventory-admin@example.com',
                'department'      => '総務部',
                'is_system_admin' => false,
                'role'            => 'admin',
            ],
            [
                'name'            => '在庫 担当',
                'email'           => 'staff@example.com',
                'department'      => '営業部',
                'is_system_admin' => false,
                'role'            => 'staff',
            ],
            [
                'name'            => '一般 社員',
                'email'           => 'member@example.com',
                'department'      => '開発部',
                'is_system_admin' => false,
                'role'            => 'member',
            ],
            [
                'name'            => '権限なし',
                'email'           => 'noaccess@example.com',
                'department'      => '開発部',
                'is_system_admin' => false,
                'role'            => null,
            ],
        ];

        foreach ($rows as $row) {
            $user = User::firstOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'password' => 'password',
                    'department_id' => $department[$row['department']],
                ]
            );

            $user->is_system_admin = $row['is_system_admin'];
            $user->save();

            if ($row['role'] !== null) {
                SystemUserRole::firstOrCreate(
                    ['user_id' => $user->id, 'system_id' => $inventory->id],
                    ['role' => $row['role']]
                );
            }
        }

    }
}
