<?php

namespace Database\Seeders;

use App\Models\Menu;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $menus = [
            [
                'name' => 'Kopi Susu Gula Aren',
                'price' => 22000,
                'is_available' => true,
            ],
            [
                'name' => 'Espresso Single Shot',
                'price' => 18000,
                'is_available' => true,
            ],
            [
                'name' => 'Americano On The Rocks',
                'price' => 24000,
                'is_available' => true,
            ],
            [
                'name' => 'Caramel Macchiato',
                'price' => 32000,
                'is_available' => true,
            ],
            [
                'name' => 'Matcha Fusion Latte',
                'price' => 35000,
                'is_available' => true,
            ],
            [
                'name' => 'Butter Croissant',
                'price' => 25000,
                'is_available' => true,
            ],
            [
                'name' => 'Smoked Beef & Cheese Pastry',
                'price' => 38000,
                'is_available' => true,
            ],
            [
                'name' => 'Truffle French Fries',
                'price' => 28000,
                'is_available' => true,
            ],
        ];

        foreach ($menus as $menu) {
            Menu::firstOrCreate(
                ['name' => $menu['name']],
                $menu
            );
        }
    }
}
