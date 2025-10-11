# High-Load Optimization Deployment Verification Script
# Run this script to verify the deployment was successful

Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "Order Response High-Load Optimization" -ForegroundColor Cyan
Write-Host "Deployment Verification Script" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

$allPassed = $true

# Test 1: Check .env for Redis queue
Write-Host "[Test 1] Checking QUEUE_CONNECTION in .env..." -ForegroundColor Yellow
$queueConnection = Select-String -Path ".env" -Pattern "QUEUE_CONNECTION=" | Select-Object -First 1
if ($queueConnection -match "QUEUE_CONNECTION=redis") {
    Write-Host "  ✅ PASS: QUEUE_CONNECTION is set to redis" -ForegroundColor Green
} else {
    Write-Host "  ❌ FAIL: QUEUE_CONNECTION is not set to redis" -ForegroundColor Red
    Write-Host "     Current value: $queueConnection" -ForegroundColor Red
    Write-Host "     Fix: Run: (Get-Content .env) -replace 'QUEUE_CONNECTION=sync', 'QUEUE_CONNECTION=redis' | Set-Content .env" -ForegroundColor Yellow
    $allPassed = $false
}

# Test 2: Check if Redis is accessible
Write-Host "`n[Test 2] Checking Redis connectivity..." -ForegroundColor Yellow
try {
    $redisTest = & redis-cli ping 2>&1
    if ($redisTest -match "PONG") {
        Write-Host "  ✅ PASS: Redis is running and accessible" -ForegroundColor Green
    } else {
        Write-Host "  ⚠️  WARNING: Redis returned unexpected response: $redisTest" -ForegroundColor Yellow
    }
} catch {
    Write-Host "  ❌ FAIL: Cannot connect to Redis" -ForegroundColor Red
    Write-Host "     Error: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "     Fix: Ensure Redis is installed and running" -ForegroundColor Yellow
    $allPassed = $false
}

# Test 3: Check node_scripts/mqtt_handler.cjs batch sizes
Write-Host "`n[Test 3] Checking Node.js batch configuration..." -ForegroundColor Yellow
$mqttHandler = Get-Content "node_scripts\mqtt_handler.cjs" -Raw
if ($mqttHandler -match "ORDER_RES_BATCH_SIZE.*?parseInt.*?'(\d+)'") {
    $batchSize = $matches[1]
    if ([int]$batchSize -ge 200) {
        Write-Host "  ✅ PASS: ORDER_RES_BATCH_SIZE is $batchSize (>= 200)" -ForegroundColor Green
    } else {
        Write-Host "  ⚠️  WARNING: ORDER_RES_BATCH_SIZE is $batchSize (recommended >= 200)" -ForegroundColor Yellow
    }
} else {
    Write-Host "  ⚠️  WARNING: Could not parse ORDER_RES_BATCH_SIZE" -ForegroundColor Yellow
}

# Test 4: Check ProcessOrderResponseBatchJob chunk size
Write-Host "`n[Test 4] Checking Job chunk configuration..." -ForegroundColor Yellow
$jobFile = Get-Content "app\Jobs\ProcessOrderResponseBatchJob.php" -Raw
if ($jobFile -match "ORDER_RES_BATCH_CHUNK_SIZE.*?(\d+)") {
    $chunkSize = $matches[1]
    if ([int]$chunkSize -ge 100) {
        Write-Host "  ✅ PASS: Chunk size is $chunkSize (>= 100)" -ForegroundColor Green
    } else {
        Write-Host "  ⚠️  WARNING: Chunk size is $chunkSize (recommended >= 100)" -ForegroundColor Yellow
    }
} else {
    Write-Host "  ⚠️  WARNING: Could not parse chunk size" -ForegroundColor Yellow
}

# Test 5: Check if deduplication method exists
Write-Host "`n[Test 5] Checking Redis deduplication is implemented..." -ForegroundColor Yellow
if ($jobFile -match "deduplicateResponses") {
    Write-Host "  ✅ PASS: Deduplication method found" -ForegroundColor Green
} else {
    Write-Host "  ❌ FAIL: Deduplication method not found" -ForegroundColor Red
    $allPassed = $false
}

# Test 6: Check if sub-batching is implemented in controller
Write-Host "`n[Test 6] Checking sub-batching is implemented..." -ForegroundColor Yellow
$controller = Get-Content "app\Http\Controllers\Api\MqttResponseController.php" -Raw
if ($controller -match "ORDER_RES_SUB_BATCH_SIZE" -and $controller -match "sub-batch") {
    Write-Host "  ✅ PASS: Sub-batching logic found in controller" -ForegroundColor Green
} else {
    Write-Host "  ⚠️  WARNING: Sub-batching logic may not be implemented" -ForegroundColor Yellow
}

# Test 7: Verify Laravel can connect to Redis
Write-Host "`n[Test 7] Testing Laravel Redis connection..." -ForegroundColor Yellow
try {
    $tinkerTest = "Redis::ping();" | php artisan tinker 2>&1
    if ($tinkerTest -match "PONG") {
        Write-Host "  ✅ PASS: Laravel can connect to Redis" -ForegroundColor Green
    } else {
        Write-Host "  ⚠️  WARNING: Laravel Redis connection test inconclusive" -ForegroundColor Yellow
        Write-Host "     Response: $tinkerTest" -ForegroundColor Gray
    }
} catch {
    Write-Host "  ⚠️  WARNING: Could not test Laravel Redis connection" -ForegroundColor Yellow
}

# Test 8: Check queue depth
Write-Host "`n[Test 8] Checking current queue depth..." -ForegroundColor Yellow
try {
    $queueDepth = & redis-cli llen "queues:high" 2>&1
    if ($queueDepth -match "^\d+$") {
        $depth = [int]$queueDepth
        if ($depth -lt 100) {
            Write-Host "  ✅ PASS: Queue depth is $depth (< 100)" -ForegroundColor Green
        } elseif ($depth -lt 500) {
            Write-Host "  ⚠️  WARNING: Queue depth is $depth (manageable but monitor)" -ForegroundColor Yellow
        } else {
            Write-Host "  ❌ FAIL: Queue depth is $depth (too high!)" -ForegroundColor Red
            Write-Host "     Workers may not be running or are overwhelmed" -ForegroundColor Yellow
            $allPassed = $false
        }
    } else {
        Write-Host "  ℹ️  INFO: Queue is empty or doesn't exist yet" -ForegroundColor Cyan
    }
} catch {
    Write-Host "  ⚠️  WARNING: Could not check queue depth" -ForegroundColor Yellow
}

# Summary
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "Verification Summary" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan

if ($allPassed) {
    Write-Host "`n✅ All critical tests PASSED!" -ForegroundColor Green
    Write-Host "`nNext steps:" -ForegroundColor Cyan
    Write-Host "  1. Deploy changes to production server" -ForegroundColor White
    Write-Host "  2. Restart queue workers: sudo supervisorctl restart laravel-queues-ultra:*" -ForegroundColor White
    Write-Host "  3. Restart MQTT handler: pm2 restart mqtt_handler" -ForegroundColor White
    Write-Host "  4. Monitor queue depth: redis-cli llen queues:high" -ForegroundColor White
    Write-Host "  5. Check logs: tail -f storage/logs/queue-high.log" -ForegroundColor White
} else {
    Write-Host "`n❌ Some tests FAILED - please fix issues before deployment" -ForegroundColor Red
    Write-Host "`nRefer to DEPLOYMENT_CHECKLIST.md for detailed steps" -ForegroundColor Yellow
}

Write-Host "`nFor detailed documentation, see:" -ForegroundColor Cyan
Write-Host "  - ORDER_RESPONSE_HIGH_LOAD_OPTIMIZATION.md (technical details)" -ForegroundColor White
Write-Host "  - DEPLOYMENT_CHECKLIST.md (step-by-step guide)" -ForegroundColor White
Write-Host "  - OPTIMIZATION_SUMMARY.md (quick overview)" -ForegroundColor White
Write-Host "  - .env.high-load-optimized (configuration reference)" -ForegroundColor White

Write-Host "`n========================================`n" -ForegroundColor Cyan

# Optional: Display current configuration
Write-Host "Current Configuration Summary:" -ForegroundColor Cyan
Write-Host "------------------------------" -ForegroundColor Cyan
Write-Host "Queue Connection    : " -NoNewline
Select-String -Path ".env" -Pattern "QUEUE_CONNECTION=" | ForEach-Object {
    if ($_ -match "redis") {
        Write-Host $_.Line -ForegroundColor Green
    } else {
        Write-Host $_.Line -ForegroundColor Red
    }
}

try {
    $redisHost = (Select-String -Path ".env" -Pattern "REDIS_HOST=" | Select-Object -First 1).Line
    Write-Host "Redis Host          : $redisHost" -ForegroundColor White
} catch {}

Write-Host "`nNode Batch Settings (from mqtt_handler.cjs):" -ForegroundColor Cyan
if ($mqttHandler -match "ORDER_RES_BATCH_SIZE.*?parseInt.*?'(\d+)'") {
    Write-Host "  Batch Size        : $($matches[1])" -ForegroundColor White
}
if ($mqttHandler -match "ORDER_RES_BATCH_TIMEOUT.*?parseInt.*?'(\d+)'") {
    Write-Host "  Batch Timeout     : $($matches[1])ms" -ForegroundColor White
}
if ($mqttHandler -match "ORDER_RES_BATCH_MAX_SIZE.*?parseInt.*?'(\d+)'") {
    Write-Host "  Max Batch Size    : $($matches[1])" -ForegroundColor White
}

Write-Host "`nJob Settings (from ProcessOrderResponseBatchJob.php):" -ForegroundColor Cyan
if ($jobFile -match "ORDER_RES_BATCH_CHUNK_SIZE.*?(\d+)") {
    Write-Host "  Chunk Size        : $($matches[1])" -ForegroundColor White
}
if ($jobFile -match "ORDER_RES_BATCH_CHUNK_DELAY_MS.*?(\d+)") {
    Write-Host "  Chunk Delay       : $($matches[1])ms" -ForegroundColor White
}

Write-Host ""
