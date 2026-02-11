<?php
/**
 * Admin: Analytics Básico
 * Visualização de acessos e estatísticas das ferramentas
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

// Verificar se é admin
if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
    $_SESSION['error'] = 'Acesso negado. Apenas administradores podem acessar esta página.';
    redirect(BASE_URL . '/dashboard.php');
}

$db = Database::getInstance();

// 1. Ferramentas mais acessadas (últimos 30 dias)
$top_tools = $db->fetchAll("
    SELECT
        tool_slug,
        COUNT(*) as total_acessos,
        COUNT(DISTINCT user_id) as usuarios_unicos
    FROM tool_access_logs
    WHERE accessed_at >= NOW() - INTERVAL '30 days'
    GROUP BY tool_slug
    ORDER BY total_acessos DESC
    LIMIT 10
");

// 2. Acessos por vertical (últimos 30 dias)
$access_by_vertical = $db->fetchAll("
    SELECT
        vertical,
        COUNT(*) as total_acessos,
        COUNT(DISTINCT user_id) as usuarios_unicos
    FROM tool_access_logs
    WHERE accessed_at >= NOW() - INTERVAL '30 days'
        AND vertical IS NOT NULL
    GROUP BY vertical
    ORDER BY total_acessos DESC
");

// 3. Usuários por vertical
$users_by_vertical = $db->fetchAll("
    SELECT
        selected_vertical as vertical,
        COUNT(*) as total_usuarios
    FROM users
    WHERE selected_vertical IS NOT NULL
    GROUP BY selected_vertical
    ORDER BY total_usuarios DESC
");

// 4. Solicitações de acesso pendentes
$pending_requests = $db->fetchAll("
    SELECT
        var.id,
        var.vertical,
        var.created_at,
        u.name as user_name,
        u.email as user_email,
        var.request_data
    FROM vertical_access_requests var
    JOIN users u ON var.user_id = u.id
    WHERE var.status = 'pending'
    ORDER BY var.created_at DESC
");

// 5. Últimos 50 acessos
$recent_access = $db->fetchAll("
    SELECT
        tal.tool_slug,
        tal.vertical,
        tal.accessed_at,
        u.name as user_name,
        u.email as user_email
    FROM tool_access_logs tal
    JOIN users u ON tal.user_id = u.id
    ORDER BY tal.accessed_at DESC
    LIMIT 50
");

// 6. Estatísticas gerais
$stats = [
    'total_users' => $db->fetchOne("SELECT COUNT(*) as count FROM users")['count'] ?? 0,
    'users_completed_onboarding' => $db->fetchOne("SELECT COUNT(*) as count FROM users WHERE completed_onboarding = TRUE")['count'] ?? 0,
    'total_tool_accesses' => $db->fetchOne("SELECT COUNT(*) as count FROM tool_access_logs")['count'] ?? 0,
    'total_accesses_30d' => $db->fetchOne("SELECT COUNT(*) as count FROM tool_access_logs WHERE accessed_at >= NOW() - INTERVAL '30 days'")['count'] ?? 0
];

// Nomes amigáveis
$vertical_names = [
    'docencia' => 'Docência',
    'pesquisa' => 'Pesquisa',
    'ifrj_alunos' => 'IFRJ - Alunos',
    'juridico' => 'Jurídico',
    'vendas' => 'Vendas',
    'marketing' => 'Marketing',
    'licitacoes' => 'Licitações',
    'rh' => 'Recursos Humanos',
    'geral' => 'Geral'
];

$tool_names = [
    'canvas-docente' => 'Canvas Docente',
    'canvas-pesquisa' => 'Canvas Pesquisa',
    'canvas-juridico' => 'Canvas Jurídico',
    'biblioteca-prompts-jogos' => 'Biblioteca de Prompts (Jogos)',
    'guia-prompts-jogos' => 'Guia de Prompts (Jogos)',
    'guia-prompts-juridico' => 'Guia de Prompts (Jurídico)',
    'padroes-avancados-juridico' => 'Padrões Avançados (Jurídico)',
    'repositorio-prompts' => 'Repositório de Prompts'
];

$pageTitle = 'Analytics - Admin';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
        }
        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?= BASE_URL ?>/dashboard.php">
                <?= APP_NAME ?>
            </a>
            <div class="ms-auto">
                <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-outline-light btn-sm">
                    ← Voltar ao Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container my-5">
        <div class="row mb-4">
            <div class="col">
                <h1>📊 Analytics</h1>
                <p class="text-muted">Estatísticas de uso da plataforma</p>
            </div>
        </div>

        <!-- Estatísticas Gerais -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($stats['total_users']) ?></div>
                    <div class="stat-label">Total de Usuários</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($stats['users_completed_onboarding']) ?></div>
                    <div class="stat-label">Onboarding Completo</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($stats['total_accesses_30d']) ?></div>
                    <div class="stat-label">Acessos (30 dias)</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value"><?= number_format($stats['total_tool_accesses']) ?></div>
                    <div class="stat-label">Total de Acessos</div>
                </div>
            </div>
        </div>

        <!-- Solicitações Pendentes -->
        <?php if (!empty($pending_requests)): ?>
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-warning">
                            <h5 class="mb-0">⚠️ Solicitações de Acesso Pendentes (<?= count($pending_requests) ?>)</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Data</th>
                                            <th>Usuário</th>
                                            <th>Email</th>
                                            <th>Vertical</th>
                                            <th>Detalhes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_requests as $request): ?>
                                            <?php $data = json_decode($request['request_data'], true); ?>
                                            <tr>
                                                <td><?= date('d/m/Y H:i', strtotime($request['created_at'])) ?></td>
                                                <td><?= sanitize_output($request['user_name']) ?></td>
                                                <td><?= sanitize_output($request['user_email']) ?></td>
                                                <td><span class="badge bg-info"><?= $vertical_names[$request['vertical']] ?? $request['vertical'] ?></span></td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#details-<?= $request['id'] ?>">
                                                        Ver Detalhes
                                                    </button>
                                                    <div id="details-<?= $request['id'] ?>" class="collapse mt-2">
                                                        <small>
                                                            <strong>Profissão:</strong> <?= sanitize_output($data['profissao'] ?? 'N/A') ?><br>
                                                            <strong>OAB:</strong> <?= sanitize_output($data['oab'] ?? 'Não informado') ?><br>
                                                            <strong>Escritório:</strong> <?= sanitize_output($data['escritorio'] ?? 'Não informado') ?><br>
                                                            <strong>Motivo:</strong> <?= sanitize_output($data['motivo'] ?? 'N/A') ?>
                                                        </small>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="row mb-4">
            <!-- Top Ferramentas -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">🔥 Ferramentas Mais Acessadas (30 dias)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($top_tools)): ?>
                            <p class="text-muted">Nenhum acesso registrado ainda</p>
                        <?php else: ?>
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Ferramenta</th>
                                        <th class="text-end">Acessos</th>
                                        <th class="text-end">Usuários</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($top_tools as $tool): ?>
                                        <tr>
                                            <td><?= $tool_names[$tool['tool_slug']] ?? $tool['tool_slug'] ?></td>
                                            <td class="text-end"><strong><?= number_format($tool['total_acessos']) ?></strong></td>
                                            <td class="text-end"><?= number_format($tool['usuarios_unicos']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Acessos por Vertical -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">📊 Acessos por Vertical (30 dias)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($access_by_vertical)): ?>
                            <p class="text-muted">Nenhum acesso registrado ainda</p>
                        <?php else: ?>
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Vertical</th>
                                        <th class="text-end">Acessos</th>
                                        <th class="text-end">Usuários</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($access_by_vertical as $vertical): ?>
                                        <tr>
                                            <td><?= $vertical_names[$vertical['vertical']] ?? $vertical['vertical'] ?></td>
                                            <td class="text-end"><strong><?= number_format($vertical['total_acessos']) ?></strong></td>
                                            <td class="text-end"><?= number_format($vertical['usuarios_unicos']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Usuários por Vertical -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">👥 Distribuição de Usuários por Vertical</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($users_by_vertical)): ?>
                            <p class="text-muted">Nenhum usuário com vertical selecionada ainda</p>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($users_by_vertical as $vertical): ?>
                                    <div class="col-md-3 mb-3">
                                        <div class="card text-center">
                                            <div class="card-body">
                                                <h3><?= number_format($vertical['total_usuarios']) ?></h3>
                                                <p class="mb-0"><?= $vertical_names[$vertical['vertical']] ?? $vertical['vertical'] ?></p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Últimos Acessos -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">🕐 Últimos 50 Acessos</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($recent_access)): ?>
                            <p class="text-muted">Nenhum acesso registrado ainda</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped">
                                    <thead>
                                        <tr>
                                            <th>Data/Hora</th>
                                            <th>Usuário</th>
                                            <th>Email</th>
                                            <th>Ferramenta</th>
                                            <th>Vertical</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_access as $access): ?>
                                            <tr>
                                                <td><?= date('d/m/Y H:i:s', strtotime($access['accessed_at'])) ?></td>
                                                <td><?= sanitize_output($access['user_name']) ?></td>
                                                <td><?= sanitize_output($access['user_email']) ?></td>
                                                <td><?= $tool_names[$access['tool_slug']] ?? $access['tool_slug'] ?></td>
                                                <td>
                                                    <?php if ($access['vertical']): ?>
                                                        <span class="badge bg-secondary">
                                                            <?= $vertical_names[$access['vertical']] ?? $access['vertical'] ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
