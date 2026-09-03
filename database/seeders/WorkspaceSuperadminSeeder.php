<?php

namespace Database\Seeders;

use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WorkspaceSuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $emails = (array) config('project-management.super_admins', []);
        $password = (string) config('project-management.super_admin_default_password', 'password');

        foreach ($emails as $email) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => Str::of($email)->before('@')->headline()->toString(),
                    'password' => Hash::make($password),
                ],
            );

            Member::query()->firstOrCreate(
                ['user_id' => $user->getKey()],
                ['name' => $user->name, 'email' => $user->email],
            );
        }
    }
}
