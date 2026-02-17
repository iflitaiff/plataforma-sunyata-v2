<?php
/**
 * Monitoring Dashboard - Métricas de uso do sistema AI
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

use Sunyata\Core\RateLimiter;
use Sunyata\Helpers\MetricsHelper;

session_name(SESSION_NAME);
session_start();

// Require authentication
require_login();

// Check admin access level
if (!has_access('admin')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    die('
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Access Denied</title>
        <link rel="stylesheet" href="/css/tabler.min.css">
    </head>
    <body>
        <div class="container-tight py-5">
            <div class="text-center mb-4">
                <h1 class="text-danger">Access Denied</h1>
                <p class="text-muted">This page requires admin privileges.</p>
                <a href="/dashboard.php" class="btn btn-primary mt-3">Return to Dashboard</a>
            </div>
        </div>
    </body>
    </html>
    ');
}

// Rate limiting - monitoring dashboard (30/min per user)
$limiter = new RateLimiter();
$userId = $_SESSION['user_id'] ?? 0;
$rate = $limiter->check("monitoring:view:$userId", 30, 60);
if (!$rate['allowed']) {
    http_response_code(429);
    header('Content-Type: text/plain; charset=utf-8');
    die('Too many requests. Please refresh in a minute.');
}

// Fetch metrics
$metrics = new MetricsHelper();
$overview = $metrics->getOverview();
$timeSeries = $metrics->getRequestTimeSeries(7);
$byVertical = $metrics->getByVertical();
$byModel = $metrics->getByModel();
$percentiles = $metrics->getResponseTimePercentiles();
$recentErrors = $metrics->getRecentErrors(10);
$costSeries = $metrics->getCostTimeSeries(7);

$pageTitle = 'Monitoring Dashboard';
$currentPage = 'admin-monitoring';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Sunyata Platform</title>
    <link rel="stylesheet" href="/css/tabler.min.css">
    <link rel="stylesheet" href="/css/tabler-vendors.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
    <div class="page">
        <div class="page-wrapper">
            <div class="page-header d-print-none">
                <div class="container-xl">
                    <div class="row g-2 align-items-center">
                        <div class="col">
                            <h2 class="page-title">📊 Monitoring Dashboard</h2>
                            <div class="text-secondary mt-1">
                                AI Service Metrics & Performance
                            </div>
                        </div>
                        <div class="col-auto">
                            <button class="btn btn-primary" onclick="location.reload()">
                                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
                                Atualizar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="page-body">
                <div class="container-xl">
                    <!-- Overview Cards -->
                    <div class="row row-deck row-cards mb-3">
                        <div class="col-sm-6 col-lg-3">
                            <div class="card">
                                <div class="card-body">
                                    <div class="d-flex align-items-center">
                                        <div class="subheader">Requisições (24h)</div>
                                    </div>
                                    <div class="h1 mb-0"><?= number_format($overview['last_24h']['requests']) ?></div>
                                    <div class="text-secondary">
                                        Total: <?= number_format($overview['all_time']['requests']) ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-3">
                            <div class="card">
                                <div class="card-body">
                                    <div class="subheader">Taxa de Sucesso</div>
                                    <div class="h1 mb-0 <?= $overview['last_24h']['success_rate'] >= 95 ? 'text-success' : 'text-warning' ?>">
                                        <?= $overview['last_24h']['success_rate'] ?>%
                                    </div>
                                    <div class="text-secondary">
                                        <?= $overview['last_24h']['successful'] ?> / <?= $overview['last_24h']['requests'] ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-3">
                            <div class="card">
                                <div class="card-body">
                                    <div class="subheader">Tempo Médio</div>
                                    <div class="h1 mb-0"><?= number_format($overview['last_24h']['avg_response_ms']) ?>ms</div>
                                    <div class="text-secondary">
                                        P95: <?= $percentiles['p95'] ?>ms
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-sm-6 col-lg-3">
                            <div class="card">
                                <div class="card-body">
                                    <div class="subheader">Custo (24h)</div>
                                    <div class="h1 mb-0">$<?= number_format($overview['last_24h']['total_cost_usd'], 4) ?></div>
                                    <div class="text-secondary">
                                        <?= number_format($overview['last_24h']['total_tokens']) ?> tokens
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row 1 -->
                    <div class="row row-cards mb-3">
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Requisições por Dia (Últimos 7 dias)</h3>
                                </div>
                                <div class="card-body">
                                    <canvas id="requestsChart" height="80"></canvas>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Response Time Percentiles</h3>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <div class="row align-items-center">
                                            <div class="col-auto">Mínimo</div>
                                            <div class="col"><strong><?= $percentiles['min'] ?>ms</strong></div>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <div class="row align-items-center">
                                            <div class="col-auto">P50 (Mediana)</div>
                                            <div class="col"><strong><?= $percentiles['p50'] ?>ms</strong></div>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <div class="row align-items-center">
                                            <div class="col-auto">P95</div>
                                            <div class="col"><strong><?= $percentiles['p95'] ?>ms</strong></div>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <div class="row align-items-center">
                                            <div class="col-auto">P99</div>
                                            <div class="col"><strong><?= $percentiles['p99'] ?>ms</strong></div>
                                        </div>
                                    </div>
                                    <div class="mb-0">
                                        <div class="row align-items-center">
                                            <div class="col-auto">Máximo</div>
                                            <div class="col"><strong><?= $percentiles['max'] ?>ms</strong></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Row 2 -->
                    <div class="row row-cards mb-3">
                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Requisições por Vertical (24h)</h3>
                                </div>
                                <div class="card-body">
                                    <canvas id="verticalChart" height="200"></canvas>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Uso por Modelo (24h)</h3>
                                </div>
                                <div class="card-body">
                                    <canvas id="modelChart" height="200"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Cost Chart -->
                    <div class="row row-cards mb-3">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">Custo Diário (USD)</h3>
                                </div>
                                <div class="card-body">
                                    <canvas id="costChart" height="60"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Errors -->
                    <?php if (!empty($recentErrors)): ?>
                    <div class="row row-cards">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h3 class="card-title">⚠️ Erros Recentes</h3>
                                </div>
                                <div class="card-body">
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($recentErrors as $error): ?>
                                        <div class="list-group-item">
                                            <div class="row align-items-center">
                                                <div class="col-auto">
                                                    <span class="badge bg-red"></span>
                                                </div>
                                                <div class="col">
                                                    <strong><?= htmlspecialchars($error['vertical']) ?></strong> - <?= htmlspecialchars($error['tool_name']) ?>
                                                    <div class="text-secondary small"><?= htmlspecialchars($error['error']) ?></div>
                                                </div>
                                                <div class="col-auto">
                                                    <div class="text-secondary small"><?= date('d/m H:i', strtotime($error['timestamp'])) ?></div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

    <script>
    // Requests Time Series
    new Chart(document.getElementById('requestsChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode(array_column($timeSeries, 'date'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            datasets: [{
                label: 'Total',
                data: <?= json_encode(array_column($timeSeries, 'requests'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                borderColor: 'rgb(32, 107, 196)',
                backgroundColor: 'rgba(32, 107, 196, 0.1)',
                tension: 0.3
            }, {
                label: 'Sucesso',
                data: <?= json_encode(array_column($timeSeries, 'successful'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                borderColor: 'rgb(94, 186, 125)',
                backgroundColor: 'rgba(94, 186, 125, 0.1)',
                tension: 0.3
            }, {
                label: 'Erro',
                data: <?= json_encode(array_column($timeSeries, 'failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                borderColor: 'rgb(214, 57, 57)',
                backgroundColor: 'rgba(214, 57, 57, 0.1)',
                tension: 0.3
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: true }
            }
        }
    });

    // Vertical Distribution
    new Chart(document.getElementById('verticalChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($byVertical, 'vertical'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            datasets: [{
                label: 'Requisições',
                data: <?= json_encode(array_column($byVertical, 'requests'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                backgroundColor: 'rgba(32, 107, 196, 0.8)'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            }
        }
    });

    // Model Distribution
    new Chart(document.getElementById('modelChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_column($byModel, 'model'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            datasets: [{
                data: <?= json_encode(array_column($byModel, 'requests'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                backgroundColor: [
                    'rgba(32, 107, 196, 0.8)',
                    'rgba(94, 186, 125, 0.8)',
                    'rgba(255, 193, 7, 0.8)',
                    'rgba(214, 57, 57, 0.8)',
                    'rgba(136, 84, 192, 0.8)'
                ]
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });

    // Cost Time Series
    new Chart(document.getElementById('costChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($costSeries, 'date'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            datasets: [{
                label: 'Custo (USD)',
                data: <?= json_encode(array_column($costSeries, 'cost_usd'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                backgroundColor: 'rgba(255, 193, 7, 0.8)'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toFixed(4);
                        }
                    }
                }
            }
        }
    });
    </script>
</body>
</html>
