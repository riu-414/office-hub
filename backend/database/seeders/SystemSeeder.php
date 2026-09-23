<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\System;

class SystemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        System::firstOrCreate(
            ['key' => 'inventory'],          // これで探す
            ['name' => '備品在庫管理',
             'description' => '社内備品の在庫・貸出・棚卸しを管理します',
             'icon' => 'package',
             'display_order' => 1,
             'is_active' => true]       // 無ければこれも入れて作る
        );
    }
}
