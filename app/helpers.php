<?php

use Illuminate\Support\Facades\DB;

if (!function_exists('setting')) {
    /**
     * Get a setting value from the settings table by key.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function setting(string $key, $default = null): mixed
    {
        static $cache = [];

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $value = DB::table('settings')->where('key', $key)->value('value');

        return $cache[$key] = $value ?? $default;
    }
}
