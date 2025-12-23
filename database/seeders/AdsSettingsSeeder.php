<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting;

class AdsSettingsSeeder extends Seeder
{
    public function run()
    {
        // Default ads per user per day
        Setting::updateOrCreate(['key' => 'ads_per_user_per_day'], ['value' => '5']);

        // Ensure points per ad default exists
        Setting::updateOrCreate(['key' => 'points_per_ads'], ['value' => '1']);
    }
}
