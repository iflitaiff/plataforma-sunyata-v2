<?php
/**
 * Admin - System Logs Viewer
 *
 * Visualiza logs do sistema (php_errors.log) com filtros e busca
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Core\Database;

require_login();

// Verificar se é admin
if (!isset($_SESSION['user']['access_level']) || $_SESSION['user']['access_level'] !== 'admin') {
    $_SESSION['error'] = 'Acesso negado. Área restrita a administradores.';
    redirect(BASE_URL . '/dashboard.php');
}

$db = Database::getInstance();

// Parâmetros de filtro
$filter_level = $_GET['level'] ?? 'all';
$search = $_GET['search'] ?? '';
$lines = (int)($_GET['lines'] ?? 100);
$lines = max(50, min(1000, $lines)); // Entre 50 e 1000

// Caminho do log
$log_file = BASE_PATH . '/logs/php_errors.log';

// Função para parsear uma linha de log
function parse_log_line($line) {
    // Formato: [Date Time Timezone] Level: Message
    // Exemplo: [12-Dec-2025 17:57:53 America/Sao_Paulo] ClaudeService::generate() failed: ...

    if (!preg_match('/^\[([^\]]+)\]\s+(.*)$/', $line, $matches)) {
        return null;
    }

    $timestamp = $matches[1];
    $message = $matches[2];

    // Detectar nível
    $level = 'INFO';
    if (stripos($message, 'PHP Fatal error') !== false || stripos($message, 'failed:') !== false) {
        $level = 'ERROR';
    } elseif (stripos($message, 'PHP Warning') !== false || stripos($message, 'WARNING:') !== false) {
        $level = 'WARNING';
    } elseif (stripos($message, '[INFO]') !== false) {
        $level = 'INFO';
    } elseif (stripos($message, 'ClaudeService') !== false || stripos($message, 'Claude API') !== false) {
        $level = stripos($message, 'error') !== false ? 'ERROR' : 'INFO';
    }

    return [
        'timestamp' => $timestamp,
        'level' => $level,
        'message' => $message,
        'raw' => $line
    ];
}

// Função para ler e filtrar logs
function read_logs($log_file, $lines, $filter_level, $search) {
    if (!file_exists($log_file)) {
        return [];
    }

    // Ler últimas N linhas do arquivo
    $file = new SplFileObject($log_file, 'r');
    $file->seek(PHP_INT_MAX);
    $total_lines = $file->key() + 1;

    $start = max(0, $total_lines - $lines * 3); // Ler 3x mais para filtrar
    $file->seek($start);

    $logs = [];
    $current_entry = null;

    while (!$file->eof()) {
        $line = $file->fgets();
        if (empty(trim($line))) continue;

        // Tentar parsear como nova entrada
        $parsed = parse_log_line($line);

        if ($parsed !== null) {
            // Salvar entrada anterior
            if ($current_entry !== null) {
                $logs[] = $current_entry;
            }
            $current_entry = $parsed;
        } else {
            // Linha de continuação (stack trace, etc)
            if ($current_entry !== null) {
                $current_entry['message'] .= "\n" . $line;
                $current_entry['raw'] .= $line;
            }
        }
    }

    // Adicionar última entrada
    if ($current_entry !== null) {
        $logs[] = $current_entry;
    }

    // Reverter para mostrar mais recentes primeiro
    $logs = array_reverse($logs);

    // Aplicar filtros
    $filtered = [];
    foreach ($logs as $log) {
        // Filtro por nível
        if ($filter_level !== 'all' && $log['level'] !== $filter_level) {
            continue;
        }

        // Filtro por busca
        if (!empty($search)) {
            $search_lower = strtolower($search);
            if (stripos($log['message'], $search) === false &&
                stripos($log['timestamp'], $search) === false) {
                continue;
            }
        }

        $filtered[] = $log;

        // Limitar resultados
        if (count($filtered) >= $lines) {
            break;
        }
    }

    return $filtered;
}

$logs = read_logs($log_file, $lines, $filter_level, $search);

// Estatísticas
$stats = [
    'total' => count($logs),
    'errors' => count(array_filter($logs, fn($l) => $l['level'] === 'ERROR')),
    'warnings' => count(array_filter($logs, fn($l) => $l['level'] === 'WARNING')),
    'info' => count(array_filter($logs, fn($l) => $l['level'] === 'INFO'))
];

// Stats para header
$stats['pending_requests'] = $db->fetchOne("SELECT COUNT(*) as count FROM vertical_access_requests WHERE status = 'pending'")['count'];

$pageTitle = 'System Logs - Admin';

// Include responsive header
include __DIR__ . '/../../src/views/admin-header.php';
?>

<style>
.log-entry {
    font-family: 'Courier New', monospace;
    font-size: 13px;
    margin-bottom: 15px;
    border-left: 4px solid #6c757d;
    background: #f8f9fa;
    padding: 10px 15px;
    border-radius: 4px;
}

.log-entry.error {
    border-left-color: #dc3545;
    background: #fff5f5;
}

.log-entry.warning {
    border-left-color: #ffc107;
    background: #fffef5;
}

.log-entry.info {
    border-left-color: #0dcaf0;
    background: #f0fbff;
}

.log-timestamp {
    color: #6c757d;
    font-size: 12px;
    margin-bottom: 5px;
}

.log-level {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: bold;
    margin-right: 8px;
}

.log-level.error {
    background: #dc3545;
    color: white;
}

.log-level.warning {
    background: #ffc107;
    color: #000;
}

.log-level.info {
    background: #0dcaf0;
    color: #000;
}

.log-message {
    white-space: pre-wrap;
    word-break: break-word;
    color: #212529;
    line-height: 1.6;
}

.log-message .highlight {
    background: yellow;
    padding: 2px 4px;
    border-radius: 2px;
}

.stats-card {
    border-left: 4px solid;
}

.stats-card.errors {
    border-left-color: #dc3545;
}

.stats-card.warnings {
    border-left-color: #ffc107;
}

.stats-card.info {
    border-left-color: #0dcaf0;
}

.filter-badge {
    cursor: pointer;
}

.filter-badge.active {
    font-weight: bold;
    transform: scale(1.1);
}
</style>

<h1 class="mb-4">System Logs</h1>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stats-card errors">
            <div class="card-body">
                <h6 class="card-subtitle mb-2 text-muted">Errors</h6>
                <h3 class="card-title mb-0 text-danger"><?= $stats['errors'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card warnings">
            <div class="card-body">
                <h6 class="card-subtitle mb-2 text-muted">Warnings</h6>
                <h3 class="card-title mb-0 text-warning"><?= $stats['warnings'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card info">
            <div class="card-body">
                <h6 class="card-subtitle mb-2 text-muted">Info</h6>
                <h3 class="card-title mb-0 text-info"><?= $stats['info'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body">
                <h6 class="card-subtitle mb-2 text-muted">Total Entries</h6>
                <h3 class="card-title mb-0"><?= $stats['total'] ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Log Level</label>
                <select class="form-select" name="level" onchange="this.form.submit()">
                    <option value="all" <?= $filter_level === 'all' ? 'selected' : '' ?>>All Levels</option>
                    <option value="ERROR" <?= $filter_level === 'ERROR' ? 'selected' : '' ?>>🔴 Errors Only</option>
                    <option value="WARNING" <?= $filter_level === 'WARNING' ? 'selected' : '' ?>>🟡 Warnings Only</option>
                    <option value="INFO" <?= $filter_level === 'INFO' ? 'selected' : '' ?>>🔵 Info Only</option>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Lines to Show</label>
                <select class="form-select" name="lines" onchange="this.form.submit()">
                    <option value="50" <?= $lines === 50 ? 'selected' : '' ?>>50 lines</option>
                    <option value="100" <?= $lines === 100 ? 'selected' : '' ?>>100 lines</option>
                    <option value="200" <?= $lines === 200 ? 'selected' : '' ?>>200 lines</option>
                    <option value="500" <?= $lines === 500 ? 'selected' : '' ?>>500 lines</option>
                    <option value="1000" <?= $lines === 1000 ? 'selected' : '' ?>>1000 lines</option>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="search"
                       value="<?= htmlspecialchars($search) ?>"
                       placeholder="Search in logs...">
            </div>

            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary me-2">Filter</button>
                <a href="<?= BASE_URL ?>/admin/system-logs.php" class="btn btn-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Quick Filters -->
<div class="mb-3">
    <span class="badge bg-secondary me-2">Quick Filters:</span>
    <a href="?search=Claude" class="badge bg-primary filter-badge <?= $search === 'Claude' ? 'active' : '' ?>">Claude API</a>
    <a href="?search=error" class="badge bg-danger filter-badge <?= $search === 'error' ? 'active' : '' ?>">Errors</a>
    <a href="?search=failed" class="badge bg-warning filter-badge <?= $search === 'failed' ? 'active' : '' ?>">Failed</a>
    <a href="?search=Database" class="badge bg-info filter-badge <?= $search === 'Database' ? 'active' : '' ?>">Database</a>
    <a href="?level=ERROR" class="badge bg-danger filter-badge <?= $filter_level === 'ERROR' ? 'active' : '' ?>">Show Errors Only</a>
</div>

<!-- Log Entries -->
<div class="card">
    <div class="card-header">
        <strong>Log Entries</strong>
        <span class="text-muted">(Showing <?= count($logs) ?> most recent)</span>
    </div>
    <div class="card-body">
        <?php if (empty($logs)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                No log entries found matching your filters.
            </div>
        <?php else: ?>
            <?php foreach ($logs as $log): ?>
                <div class="log-entry <?= strtolower($log['level']) ?>">
                    <div class="log-timestamp">
                        <span class="log-level <?= strtolower($log['level']) ?>">
                            <?= $log['level'] ?>
                        </span>
                        <?= htmlspecialchars($log['timestamp']) ?>
                    </div>
                    <div class="log-message">
<?php
$message = htmlspecialchars($log['message']);
if (!empty($search)) {
    $message = preg_replace(
        '/(' . preg_quote($search, '/') . ')/i',
        '<span class="highlight">$1</span>',
        $message
    );
}
echo $message;
?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Info Box -->
<div class="alert alert-info mt-4">
    <h6><i class="bi bi-info-circle"></i> About System Logs</h6>
    <p class="mb-0">
        <strong>Log Location:</strong> <code>/logs/php_errors.log</code><br>
        <strong>Includes:</strong> PHP errors, warnings, Claude API errors, database issues, and application info messages.<br>
        <strong>Rotation:</strong> Logs are automatically rotated when file exceeds 10MB.<br>
        <strong>Tip:</strong> Use filters and search to quickly find specific issues. Error levels help prioritize issues.
    </p>
</div>

<?php include __DIR__ . '/../../src/views/admin-footer.php'; ?>
