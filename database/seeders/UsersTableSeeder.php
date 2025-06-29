<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\User::create([
            'name' => 'Google User',
            'email' => 'user@example.com',
            'google_id' => '1234567890',
            'profile_link' => 'https://profiles.google.com/1234567890',
            'points' => 100,
            'password' => bcrypt('password'), // Optional if using OAuth only
        ]);
    }

}
