<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::where('email', env('ADMIN_EMAIL', 'admin@konji-shop.test'))
            ->delete();

        User::create([
            'name' => env('ADMIN_NAME', 'Admin'),
            'first_name' => env('ADMIN_FIRST_NAME', 'Admin'),
            'last_name' => env('ADMIN_LAST_NAME', 'User'),
            'email' => env('ADMIN_EMAIL', 'admin@konji-shop.test'),
            'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
            'email_verified_at' => now(),
            'is_admin' => true,

            'street' => env('ADMIN_STREET', 'Test Street'),
            'house_number' => env('ADMIN_HOUSE_NUMBER', '10'),
            'apartment_number' => env('ADMIN_APARTMENT_NUMBER', '5'),
            'city' => env('ADMIN_CITY', 'Toruń'),
            'postcode' => env('ADMIN_POSTCODE', '87-100'),
            'country' => env('ADMIN_COUNTRY', 'PL'),
            'phone_number' => env('ADMIN_PHONE_NUMBER', '+48123123123'),
        ]);
    }
}
