<?php
/**
 * API: Document Picker
 * Returns HTML for the document picker modal in SurveyJS forms.
 *
 * Query params:
 *   accept (string) — comma-separated MIME types to filter
 *   field  (string) — the SurveyJS field name requesting the document
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../config/config.php';

session_name(SESSION_NAME);
session_start();

use Sunyata\Services\DocumentLibraryService;

if (!isset($_SESSION['user_id'])) {
    echo '<div class="text-center p-3 text-danger">Nao autenticado.</div>';
    exit;
}

$accept = $_GET['accept'] ?? '';
$fieldName = htmlspecialchars($_GET['field'] ?? '');

$acceptedTypes = [];
if ($accept) {
    $acceptedTypes = array_map('trim', explode(',', $accept));
}

$service = new DocumentLibraryService();
$documents = $service->getDocumentsForPicker((int)$_SESSION['user_id'], $acceptedTypes);

if (empty($documents)) {
    echo '<div class="text-center p-4 text-secondary">';
    echo '<i class="ti ti-files-off" style="font-size:2rem;"></i>';
    echo '<p class="mt-2">Nenhum documento na biblioteca.</p>';
    echo '<p class="small"><a href="' . BASE_URL . '/meus-documentos/" target="_blank">Enviar documentos</a></p>';
    echo '</div>';
    exit;
}

echo '<div class="list-group list-group-flush" style="max-height:400px; overflow-y:auto;">';
foreach ($documents as $doc) {
    $icon = DocumentLibraryService::mimeIcon($doc['mime_type']);
    $size = DocumentLibraryService::formatFileSize((int)$doc['file_size']);
    $date = date('d/m/Y', strtotime($doc['created_at']));
    $extractionOk = $doc['extraction_status'] === 'completed';

    echo '<div class="list-group-item list-group-item-action" '
       . 'onclick="selectDocument(' . $doc['id'] . ', \'' . htmlspecialchars(addslashes($doc['filename'])) . '\', \'' . htmlspecialchars($fieldName) . '\')" '
       . 'style="cursor:pointer;">';
    echo '<div class="d-flex align-items-center">';
    echo '<i class="ti ' . $icon . ' me-2" style="font-size:1.3rem;"></i>';
    echo '<div class="flex-grow-1">';
    echo '<div class="text-truncate">' . htmlspecialchars($doc['filename']) . '</div>';
    echo '<small class="text-secondary">' . $size . ' - ' . $date;
    if ($extractionOk) {
        echo ' <i class="ti ti-check text-success" title="Texto extraido"></i>';
    }
    echo '</small>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}
echo '</div>';
