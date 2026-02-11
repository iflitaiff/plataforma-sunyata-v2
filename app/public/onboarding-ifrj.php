<?php
/**
 * Onboarding - IFRJ: Formulário específico para alunos do IFRJ
 * Coleta nível de ensino e nome do curso
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token de segurança inválido';
    } else {
        $ifrj_level = $_POST['ifrj_level'] ?? '';
        $ifrj_course = trim($_POST['ifrj_course'] ?? '');

        // Validações
        if (!in_array($ifrj_level, ['ensino_medio', 'superior'])) {
            $errors[] = 'Selecione o nível de ensino';
        }
        if (empty($ifrj_course)) {
            $errors[] = 'Informe o nome do curso';
        }

        if (empty($errors)) {
            try {
                $db = Database::getInstance();

                // Atualizar user_profiles com dados IFRJ
                $db->update('user_profiles', [
                    'ifrj_level' => $ifrj_level,
                    'ifrj_course' => $ifrj_course
                ], 'user_id = :user_id', ['user_id' => $_SESSION['user_id']]);

                // Atualizar usuário com vertical
                $db->update('users', [
                    'selected_vertical' => 'ifrj_alunos',
                    'completed_onboarding' => true
                ], 'id = :id', ['id' => $_SESSION['user_id']]);

                // Atualizar sessão (CRÍTICO)
                if (!isset($_SESSION['user'])) {
                    $_SESSION['user'] = [];
                }
                $_SESSION['user']['selected_vertical'] = 'ifrj_alunos';
                $_SESSION['user']['completed_onboarding'] = true;

                // Log
                $db->insert('audit_logs', [
                    'user_id' => $_SESSION['user_id'],
                    'action' => 'onboarding_completed',
                    'entity_type' => 'users',
                    'entity_id' => $_SESSION['user_id'],
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                    'details' => json_encode([
                        'vertical' => 'ifrj_alunos',
                        'level' => $ifrj_level,
                        'course' => $ifrj_course
                    ])
                ]);

                $_SESSION['success'] = 'Bem-vindo(a) à área de alunos do IFRJ!';
                redirect(BASE_URL . '/areas/ifrj_alunos/');

            } catch (Exception $e) {
                error_log('Erro no onboarding IFRJ: ' . $e->getMessage());
                $errors[] = 'Erro ao salvar dados. Tente novamente.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IFRJ - Alunos - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .onboarding-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
    </style>
</head>
<body>
    <div class="onboarding-container">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8 col-lg-6">
                    <div class="card shadow-lg">
                        <div class="card-body p-5">
                            <div class="text-center mb-4">
                                <div style="font-size: 4rem;">🎓</div>
                                <h2 class="mb-2">IFRJ - Alunos</h2>
                                <p class="text-muted">Complete as informações abaixo</p>
                            </div>

                            <?php if (!empty($errors)): ?>
                                <div class="alert alert-danger">
                                    <ul class="mb-0">
                                        <?php foreach ($errors as $error): ?>
                                            <li><?= sanitize_output($error) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

                                <!-- Nível de Ensino -->
                                <div class="mb-3">
                                    <label class="form-label">
                                        Nível de Ensino <span class="text-danger">*</span>
                                    </label>
                                    <select class="form-select" name="ifrj_level" required>
                                        <option value="">Selecione...</option>
                                        <option value="ensino_medio">Ensino Médio</option>
                                        <option value="superior">Ensino Superior</option>
                                    </select>
                                </div>

                                <!-- Nome do Curso -->
                                <div class="mb-4">
                                    <label for="ifrj_course" class="form-label">
                                        Nome do Curso <span class="text-danger">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        id="ifrj_course"
                                        name="ifrj_course"
                                        placeholder="Ex: Técnico em Informática, Engenharia..."
                                        value="<?= sanitize_output($_POST['ifrj_course'] ?? '') ?>"
                                        required
                                    >
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        Continuar
                                    </button>
                                    <a href="<?= BASE_URL ?>/onboarding-step2.php" class="btn btn-outline-secondary">
                                        Voltar
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
