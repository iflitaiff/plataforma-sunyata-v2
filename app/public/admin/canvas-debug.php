<?php
/**
 * DEBUG TEMPORÁRIO - Canvas Templates
 * Ativa error_reporting máximo para identificar causa do HTTP 500
 */

// Ativar TODOS os erros
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

echo "<!-- DEBUG: Starting script -->\n";

try {
    echo "<!-- DEBUG: Step 1 - Autoload -->\n";
    require_once __DIR__ . '/../../vendor/autoload.php';
    echo "✓ Autoload OK<br>";

    echo "<!-- DEBUG: Step 2 - Config -->\n";
    require_once __DIR__ . '/../../config/config.php';
    echo "✓ Config OK<br>";

    echo "<!-- DEBUG: Step 3 - Session -->\n";
    session_name(SESSION_NAME);
    session_start();
    echo "✓ Session started<br>";

    echo "<!-- DEBUG: Step 4 - Database class -->\n";
    use Sunyata\Core\Database;
    echo "✓ Database class imported<br>";

    echo "<!-- DEBUG: Step 5 - Check login -->\n";
    require_login();
    echo "✓ User logged in<br>";

    echo "<!-- DEBUG: Step 6 - Check admin access -->\n";
    if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
        throw new Exception("Not admin: " . ($_SESSION['user']['access_level'] ?? 'null'));
    }
    echo "✓ Admin access verified<br>";

    echo "<!-- DEBUG: Step 7 - Database getInstance -->\n";
    $db = Database::getInstance();
    echo "✓ Database instance created<br>";

    echo "<!-- DEBUG: Step 8 - Query canvas templates -->\n";
    $canvasTemplates = $db->fetchAll("
        SELECT
            id,
            slug,
            name,
            vertical,
            max_questions,
            is_active,
            created_at,
            updated_at
        FROM canvas_templates
        ORDER BY vertical ASC, name ASC
    ");
    echo "✓ Canvas templates fetched: " . count($canvasTemplates) . " found<br>";

    echo "<!-- DEBUG: Step 9 - Count conversations -->\n";
    $canvasStats = [];
    foreach ($canvasTemplates as $canvas) {
        echo "<!-- DEBUG: Counting for canvas ID " . $canvas['id'] . " -->\n";
        $stats = $db->fetchOne("
            SELECT COUNT(*) as total_conversations
            FROM conversations
            WHERE canvas_id = :canvas_id
        ", ['canvas_id' => $canvas['id']]);

        $canvasStats[$canvas['id']] = $stats['total_conversations'] ?? 0;
    }
    echo "✓ Conversation stats calculated<br>";

    echo "<!-- DEBUG: Step 10 - Set pageTitle -->\n";
    $pageTitle = 'Canvas Templates';
    echo "✓ Page title set<br>";

    echo "<!-- DEBUG: Step 11 - Include header -->\n";
    include __DIR__ . '/../../src/views/admin-header.php';
    echo "<!-- DEBUG: Header included -->\n";

} catch (Exception $e) {
    echo "<div style='background: #f44336; color: white; padding: 20px; margin: 20px;'>";
    echo "<h2>ERRO CAPTURADO:</h2>";
    echo "<p><strong>Mensagem:</strong> " . $e->getMessage() . "</p>";
    echo "<p><strong>Arquivo:</strong> " . $e->getFile() . "</p>";
    echo "<p><strong>Linha:</strong> " . $e->getLine() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
    echo "</div>";
    exit;
}

echo "<!-- DEBUG: Step 12 - Main content -->\n";
?>

<h1 class="mb-4">Canvas Templates [DEBUG MODE]</h1>

<div class="alert alert-warning">
    <strong>DEBUG MODE ATIVO</strong> - Este arquivo exibe todos os erros PHP para diagnóstico.
</div>

<!-- Cards de Canvas -->
<div class="row g-4">
    <?php foreach ($canvasTemplates as $canvas): ?>
        <?php echo "<!-- DEBUG: Rendering canvas ID " . $canvas['id'] . " -->\n"; ?>
        <div class="col-12">
            <div class="card <?= $canvas['is_active'] ? 'border-success' : 'border-secondary' ?>">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h5 class="card-title mb-2">
                                <?= sanitize_output($canvas['name']) ?>
                                <?php if ($canvas['is_active']): ?>
                                    <span class="badge bg-success">ATIVO</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">INATIVO</span>
                                <?php endif; ?>
                            </h5>

                            <p class="mb-2">
                                <strong>Slug:</strong> <code><?= sanitize_output($canvas['slug']) ?></code> |
                                <strong>Vertical:</strong> <span class="badge bg-primary"><?= ucfirst($canvas['vertical']) ?></span> |
                                <strong>Máx. Perguntas:</strong> <?= $canvas['max_questions'] ?>
                            </p>

                            <p class="mb-0 text-muted small">
                                <i class="bi bi-chat-dots"></i>
                                <strong><?= number_format($canvasStats[$canvas['id']] ?? 0) ?></strong> conversas realizadas
                            </p>
                        </div>

                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            <a href="canvas-edit.php?id=<?= $canvas['id'] ?>" class="btn btn-primary">
                                <i class="bi bi-pencil"></i> Editar
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php echo "<!-- DEBUG: Footer include -->\n"; ?>
<?php include __DIR__ . '/../../src/views/admin-footer.php'; ?>
<?php echo "<!-- DEBUG: Script complete -->\n"; ?>
