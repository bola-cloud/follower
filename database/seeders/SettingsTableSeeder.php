<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Setting;

class SettingsTableSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'key' => 'points_per_follow',
                'value' => '1',
            ],
            [
                'key' => 'points_per_like',
                'value' => '2',
            ],
            [
                'key' => 'app_version',
                'value' => '1.0.0',
            ],
            [
                'key' => 'download_link',
                'value' => 'https://example.com/download/app.apk',
            ],
            [
                'key' => 'mandatory',
                'value' => '1', // 1 for true
            ],
            [
                'key' => 'build_number',
                'value' => '1001',
            ],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                ['value' => $setting['value']]
            );
        }
    }
}
