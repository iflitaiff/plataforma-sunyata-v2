<?php
/**
 * Vertical: Jurídico
 * Página principal com ferramentas disponíveis
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

// Verificar acesso à vertical
if (!isset($_SESSION['user']['selected_vertical'])) {
    $_SESSION['error'] = 'Por favor, complete o onboarding primeiro';
    redirect(BASE_URL . '/onboarding-step1.php');
}

$user_vertical = $_SESSION['user']['selected_vertical'] ?? null;
$is_admin = ($_SESSION['user']['access_level'] ?? 'guest') === 'admin';
$is_demo = $_SESSION['user']['is_demo'] ?? false;
$is_iatr = ($user_vertical === 'iatr');

// Verificar se tem acesso (juridico, iatr, demo ou admin)
$allowed_verticals = ['juridico', 'iatr'];
if (!in_array($user_vertical, $allowed_verticals) && !$is_demo && !$is_admin) {
    $_SESSION['error'] = 'Você não tem acesso a esta vertical';
    redirect(BASE_URL . '/dashboard.php');
}

// Buscar Canvas do banco (tipo 'forms' apenas, Fase 1)
$db = Database::getInstance();
$canvas_list = $db->fetchAll("
    SELECT * FROM canvas
    WHERE vertical = 'juridico' AND type = 'forms' AND is_active = 1
    ORDER BY display_order ASC
");

// Converter Canvas para formato de ferramentas (compatibilidade)
$ferramentas = [];
foreach ($canvas_list as $canvas) {
    // Mapear slug do banco para nome de arquivo existente (backward compatibility)
    $file_map = [
        'juridico-canvas-v1' => 'canvas-juridico',
        'juridico-canvas-v2' => 'canvas-juridico-v2',
        'juridico-canvas-v3' => 'canvas-juridico-v3',
    ];

    $file_id = $file_map[$canvas['slug']] ?? $canvas['slug'];

    $ferramentas[] = [
        'id' => $file_id,
        'nome' => $canvas['name'],
        'descricao' => $canvas['description'] ?? '',
        'icone' => $canvas['icon'] ?? '📋',
        'url' => BASE_URL . "/areas/juridico/{$file_id}.php",
        'badge' => ($canvas['slug'] === 'juridico-canvas-v3') ? 'NOVO' : null,
    ];
}

// Adicionar ferramentas hardcoded (tipo 'page', Fase 2 - manter por enquanto)
$ferramentas[] = [
    'id' => 'guia-prompts-juridico',
    'nome' => 'Guia de Prompts (Jurídico)',
    'descricao' => 'Prompts especializados para Direito',
    'icone' => '📖',
    'url' => BASE_URL . '/areas/juridico/guia-prompts-juridico.php',
];

$ferramentas[] = [
    'id' => 'padroes-avancados-juridico',
    'nome' => 'Padrões Avançados (Jurídico)',
    'descricao' => 'Técnicas avançadas para área jurídica',
    'icone' => '⚡',
    'url' => BASE_URL . '/areas/juridico/padroes-avancados-juridico.php',
];

$ferramentas[] = [
    'id' => 'repositorio-prompts',
    'nome' => 'Repositório de Prompts',
    'descricao' => 'Dicionário geral de prompts',
    'icone' => '📚',
    'url' => BASE_URL . '/areas/juridico/repositorio-prompts.php',
];

// Filtrar ferramentas por vertical
if ($is_iatr) {
    // Vertical IATR: mostrar apenas Canvas V3
    $ferramentas = array_filter($ferramentas, function($f) {
        return $f['id'] === 'canvas-juridico-v3';
    });
    $ferramentas = array_values($ferramentas); // Reindexar array
}

$pageTitle = 'Vertical: Jurídico';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .vertical-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
        }
        .tool-card {
            transition: all 0.3s ease;
            height: 100%;
            border: 2px solid transparent;
            position: relative;
        }
        .tool-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            border-color: #0d6efd;
        }
        .tool-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }
        .beta-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 1rem;
            font-size: 0.75rem;
            font-weight: bold;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="vertical-header">
        <div class="container">
            <div class="row">
                <div class="col-12">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1>⚖️ Jurídico</h1>
                            <p class="lead mb-0">Ferramentas especializadas para profissionais do Direito</p>
                        </div>
                        <a href="<?= BASE_URL ?>/dashboard.php" class="btn btn-light">
                            ← Voltar ao Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container mb-5">
        <?php if ($is_demo): ?>
            <div class="alert alert-info">
                <strong>ℹ️ Modo Demo:</strong> Você está visualizando esta vertical em modo demonstração.
            </div>
        <?php endif; ?>
        <?php if ($is_admin): ?>
            <div class="alert alert-primary">
                <strong>👑 Modo Admin:</strong> Você está explorando esta vertical como administrador.
            </div>
        <?php endif; ?>

        <!-- Ferramentas Grid -->
        <div class="row g-4">
            <?php foreach ($ferramentas as $ferramenta): ?>
                <div class="col-md-6 col-lg-4">
                    <a href="<?= $ferramenta['url'] ?>" class="text-decoration-none">
                        <div class="card tool-card">
                            <?php if (isset($ferramenta['badge'])): ?>
                                <span class="beta-badge"><?= $ferramenta['badge'] ?></span>
                            <?php endif; ?>
                            <div class="card-body text-center p-4">
                                <div class="tool-icon"><?= $ferramenta['icone'] ?></div>
                                <h5 class="card-title"><?= $ferramenta['nome'] ?></h5>
                                <p class="card-text text-muted"><?= $ferramenta['descricao'] ?></p>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Help Section -->
        <div class="row mt-5">
            <div class="col-md-8 offset-md-2">
                <div class="alert alert-light">
                    <h6>💡 Dica:</h6>
                    <p class="mb-0">
                        Explore todas as ferramentas para encontrar a que melhor se adequa às suas necessidades.
                        Para suporte, entre em contato: <a href="mailto:<?= SUPPORT_EMAIL ?>"><?= SUPPORT_EMAIL ?></a>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
