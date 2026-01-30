<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$value = \App\Models\Setting::where('key', 'websiteUrl')->value('value');
echo "websiteUrl: " . ($value ?? 'NOT FOUND') . "\n";
