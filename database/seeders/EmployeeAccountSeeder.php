<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;

class EmployeeAccountSeeder extends Seeder
{
    public function run(): void
    {
        User::where('email', 'admin@multishop.test')->update([
            'username' => 'admin',
            'role' => 'admin',
        ]);

        $seller = User::updateOrCreate(
            ['email' => 'moussa@multishop.test'],
            [
                'name' => 'Moussa Ndiaye',
                'username' => 'moussa',
                'role' => 'seller',
                'password' => 'password',
            ],
        );

        Employee::where('email', 'moussa@multishop.test')->update([
            'user_id' => $seller->id,
        ]);
    }
}
