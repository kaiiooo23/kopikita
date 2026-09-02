<?php

namespace Database\Seeders;

use App\Models\Table;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tables = [
            ['table_number' => 'Meja 01'],
            ['table_number' => 'Meja 02'],
            ['table_number' => 'Meja 03'],
            ['table_number' => 'Meja 04'],
            ['table_number' => 'Meja 05'],
        ];

        foreach ($tables as $table) {
            Table::firstOrCreate(
                ['table_number' => $table['table_number']],
                [
                    'current_token' => (string) Str::uuid(),
                ]
            );
        }
    }
}
