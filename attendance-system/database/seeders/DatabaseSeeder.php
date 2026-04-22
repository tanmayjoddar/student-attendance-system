<?php

namespace Database\Seeders;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'nic@admin'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('bgh123'),
                'role' => 'super_admin',
                'student_id' => null,
            ]
        );

        // Remove prior default/student seed data so only admin login is seeded.
        User::where('email', 'student1@school.com')->delete();
        Student::where('email', 'student1@school.com')->delete();
        User::where('email', 'admin@school.com')->delete();
    }
}
