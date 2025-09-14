#!/usr/bin/env node
// MQTT Handler Performance Monitor
// Monitors system health and MQTT processing metrics

const axios = require('axios');
const fs = require('fs');
const path = require('path');

const API_BASE = process.env.API_BASE || 'https://egfollow.com';
const MONITOR_INTERVAL = parseInt(process.env.MONITOR_INTERVAL || '60000', 10); // 1 minute
const LOG_FILE = path.join(__dirname, '../logs/mqtt-monitor.log');
const METRICS_FILE = path.join(__dirname, '../logs/mqtt-metrics.json');

// Alert thresholds
const ALERT_THRESHOLDS = {
    queueSize: parseInt(process.env.ALERT_THRESHOLD_QUEUE_SIZE || '500', 10),
    errorRate: parseFloat(process.env.ALERT_THRESHOLD_ERROR_RATE || '0.05'), // 5%
    dbConnectionFailureRate: 0.1, // 10%
    avgResponseTime: 5000, // 5 seconds
};

let metrics = {
    lastCheck: null,
    systemHealth: null,
    queueStats: null,
    processingStats: null,
    alerts: [],
    history: []
};

// Ensure logs directory exists
const logsDir = path.dirname(LOG_FILE);
if (!fs.existsSync(logsDir)) {
    fs.mkdirSync(logsDir, { recursive: true });
}

function log(level, message, data = {}) {
    const timestamp = new Date().toISOString();
    const logEntry = {
        timestamp,
        level,
        message,
        ...data
    };

    console.log(`[${timestamp}] ${level.toUpperCase()}: ${message}`);

    try {
        fs.appendFileSync(LOG_FILE, JSON.stringify(logEntry) + '\n');
    } catch (err) {
        console.error('Failed to write to log file:', err.message);
    }
}

async function checkSystemHealth() {
    try {
        const response = await axios.get(`${API_BASE}/api/health/system`, {
            timeout: 10000,
            headers: { 'Accept': 'application/json' }
        });

        return response.data;
    } catch (err) {
        log('error', 'System health check failed', { error: err.message });
        return null;
    }
}

async function checkQueueStats() {
    try {
        const response = await axios.get(`${API_BASE}/api/health/queue-db`, {
            timeout: 5000,
            headers: { 'Accept': 'application/json' }
        });

        return response.data;
    } catch (err) {
        log('error', 'Queue stats check failed', { error: err.message });
        return null;
    }
}

async function checkProcessingStats() {
    try {
        const response = await axios.get(`${API_BASE}/api/health/processing-stats`, {
            timeout: 5000,
            headers: { 'Accept': 'application/json' }
        });

        return response.data;
    } catch (err) {
        log('error', 'Processing stats check failed', { error: err.message });
        return null;
    }
}

function analyzeMetrics() {
    const alerts = [];

    if (metrics.systemHealth) {
        // Check circuit breaker status
        if (metrics.systemHealth.circuit_breaker?.status === 'open') {
            alerts.push({
                type: 'circuit_breaker_open',
                message: 'Circuit breaker is OPEN - system is in degraded mode',
                severity: 'critical',
                data: metrics.systemHealth.circuit_breaker
            });
        }

        // Check database health
        if (metrics.systemHealth.database?.status !== 'healthy') {
            alerts.push({
                type: 'database_unhealthy',
                message: `Database health: ${metrics.systemHealth.database?.status}`,
                severity: 'warning',
                data: metrics.systemHealth.database
            });
        }

        // Check system load
        if (metrics.systemHealth.system?.load === 'high') {
            alerts.push({
                type: 'high_system_load',
                message: 'System load is HIGH - performance may be degraded',
                severity: 'warning',
                data: metrics.systemHealth.system
            });
        }
    }

    if (metrics.queueStats) {
        // Check queue size
        if (metrics.queueStats.queued_actions > ALERT_THRESHOLDS.queueSize) {
            alerts.push({
                type: 'high_queue_size',
                message: `Queue size (${metrics.queueStats.queued_actions}) exceeds threshold (${ALERT_THRESHOLDS.queueSize})`,
                severity: 'warning',
                data: { queue_size: metrics.queueStats.queued_actions }
            });
        }

        // Check database connectivity
        if (!metrics.queueStats.db_connected) {
            alerts.push({
                type: 'database_disconnected',
                message: 'Database connection is DOWN',
                severity: 'critical',
                data: metrics.queueStats
            });
        }
    }

    if (metrics.processingStats) {
        // Check error rates
        const errorRate = metrics.processingStats.error_rate || 0;
        if (errorRate > ALERT_THRESHOLDS.errorRate) {
            alerts.push({
                type: 'high_error_rate',
                message: `Error rate (${(errorRate * 100).toFixed(1)}%) exceeds threshold (${(ALERT_THRESHOLDS.errorRate * 100)}%)`,
                severity: 'warning',
                data: { error_rate: errorRate }
            });
        }
    }

    return alerts;
}

function saveMetrics() {
    try {
        fs.writeFileSync(METRICS_FILE, JSON.stringify(metrics, null, 2));
    } catch (err) {
        log('error', 'Failed to save metrics', { error: err.message });
    }
}

async function performHealthCheck() {
    const timestamp = new Date().toISOString();
    log('info', 'Starting health check');

    try {
        // Collect all metrics
        const [systemHealth, queueStats, processingStats] = await Promise.all([
            checkSystemHealth(),
            checkQueueStats(),
            checkProcessingStats()
        ]);

        metrics.lastCheck = timestamp;
        metrics.systemHealth = systemHealth;
        metrics.queueStats = queueStats;
        metrics.processingStats = processingStats;

        // Analyze for alerts
        const alerts = analyzeMetrics();
        metrics.alerts = alerts;

        // Add to history (keep last 24 hours)
        metrics.history.push({
            timestamp,
            systemHealth,
            queueStats,
            processingStats,
            alerts
        });

        // Trim history to last 1440 entries (24 hours at 1-minute intervals)
        if (metrics.history.length > 1440) {
            metrics.history = metrics.history.slice(-1440);
        }

        // Log alerts
        for (const alert of alerts) {
            log(alert.severity, `ALERT: ${alert.message}`, alert.data);
        }

        // Log summary
        log('info', 'Health check completed', {
            system_status: systemHealth?.status || 'unknown',
            queue_size: queueStats?.queued_actions || 0,
            db_connected: queueStats?.db_connected || false,
            alerts_count: alerts.length
        });

        // Save metrics to file
        saveMetrics();

    } catch (err) {
        log('error', 'Health check failed', { error: err.message });
    }
}

// Initial health check
performHealthCheck();

// Schedule regular health checks
setInterval(performHealthCheck, MONITOR_INTERVAL);

// Graceful shutdown
process.on('SIGINT', () => {
    log('info', 'MQTT monitor shutting down...');
    saveMetrics();
    process.exit(0);
});

process.on('SIGTERM', () => {
    log('info', 'MQTT monitor terminated');
    saveMetrics();
    process.exit(0);
});

log('info', 'MQTT monitor started', {
    api_base: API_BASE,
    monitor_interval: MONITOR_INTERVAL,
    alert_thresholds: ALERT_THRESHOLDS
});
