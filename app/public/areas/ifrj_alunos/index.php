<?php
/**
 * Vertical: IFRJ - Alunos
 * Página principal com ferramentas disponíveis
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

require_login();

// Verificar acesso à vertical
if (!isset($_SESSION['user']['selected_vertical'])) {
    $_SESSION['error'] = 'Por favor, complete o onboarding primeiro';
    redirect(BASE_URL . '/onboarding-step1.php');
}

$user_vertical = $_SESSION['user']['selected_vertical'] ?? null;
$is_admin = ($_SESSION['user']['access_level'] ?? 'guest') === 'admin';
$is_demo = $_SESSION['user']['is_demo'] ?? false;

// Verificar se tem acesso (vertical correta OU usuário demo)
if ($user_vertical !== 'ifrj_alunos' && !$is_demo && !$is_admin) {
    $_SESSION['error'] = 'Você não tem acesso a esta vertical';
    redirect(BASE_URL . '/dashboard.php');
}

// Definição das ferramentas desta vertical
$ferramentas = array (
  0 => 
  array (
    'id' => 'biblioteca-prompts-jogos',
    'nome' => 'Biblioteca de Prompts (Jogos)',
    'descricao' => 'Coleção de prompts para gamificação',
    'icone' => '🎮',
  ),
  1 => 
  array (
    'id' => 'guia-prompts-jogos',
    'nome' => 'Guia de Prompts (Jogos)',
    'descricao' => 'Guia prático para prompts em jogos',
    'icone' => '📖',
  ),
  2 => 
  array (
    'id' => 'canvas-pesquisa',
    'nome' => 'Canvas Pesquisa',
    'descricao' => 'Estruturação de projetos de pesquisa',
    'icone' => '🔬',
  ),
  3 => 
  array (
    'id' => 'repositorio-prompts',
    'nome' => 'Repositório de Prompts',
    'descricao' => 'Dicionário geral de prompts',
    'icone' => '📚',
  ),
);

// Adicionar URLs às ferramentas
foreach ($ferramentas as &$ferramenta) {
    $ferramenta['url'] = BASE_URL . "/areas/ifrj_alunos/{$ferramenta['id']}.php";
}
unset($ferramenta); // Importante: liberar a referência para evitar bugs

$pageTitle = 'Vertical: IFRJ - Alunos';
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
                            <h1>🎓 IFRJ - Alunos</h1>
                            <p class="lead mb-0">Área exclusiva para alunos do IFRJ com ferramentas de apoio ao aprendizado</p>
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
