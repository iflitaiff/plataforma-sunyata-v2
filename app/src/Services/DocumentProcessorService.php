<?php
/**
 * Document Processor Service
 *
 * Extracts text from PDF and DOCX files using external libraries
 *
 * @package Sunyata\Services
 */

namespace Sunyata\Services;

use Sunyata\Core\Database;
use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;
use Exception;

class DocumentProcessorService {
    private static $instance = null;
    private $db;

    // Maximum extracted text length (to limit context sent to Claude)
    private const MAX_TEXT_LENGTH = 100000; // 100k characters

    private function __construct() {
        $this->db = Database::getInstance();
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Extract text from a file (PDF or DOCX)
     *
     * @param int $fileId File ID from user_files table
     * @param int $userId User ID (ownership check)
     * @return array ['success' => bool, 'text' => string, 'message' => string]
     */
    public function extractText(int $fileId, int $userId): array {
        try {
            // Bug #3 Fix: Get file data with ownership check
            $file = $this->db->fetchOne(
                "SELECT * FROM user_files WHERE id = :file_id AND user_id = :user_id",
                [
                    'file_id' => $fileId,
                    'user_id' => $userId
                ]
            );

            if (!$file) {
                return [
                    'success' => false,
                    'text' => '',
                    'message' => 'Arquivo não encontrado ou você não tem permissão para acessá-lo'
                ];
            }

            // Check if file exists on disk
            if (!file_exists($file['filepath'])) {
                return [
                    'success' => false,
                    'text' => '',
                    'message' => 'Arquivo físico não encontrado'
                ];
            }

            // Extract text based on MIME type
            $text = '';
            switch ($file['mime_type']) {
                case 'application/pdf':
                    $result = $this->extractFromPdf($file['filepath']);
                    break;

                case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                    $result = $this->extractFromDocx($file['filepath']);
                    break;

                default:
                    return [
                        'success' => false,
                        'text' => '',
                        'message' => 'Tipo de arquivo não suportado para extração'
                    ];
            }

            return $result;

        } catch (Exception $e) {
            error_log('DocumentProcessorService::extractText error: ' . $e->getMessage());
            return [
                'success' => false,
                'text' => '',
                'message' => 'Erro ao extrair texto: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Process a file: extract text and save to database
     *
     * @param int $fileId File ID
     * @param int $userId User ID (ownership check)
     * @return bool True if processed successfully
     */
    public function processFile(int $fileId, int $userId): bool {
        try {
            $result = $this->extractText($fileId, $userId);

            if (!$result['success']) {
                error_log("Failed to extract text from file {$fileId}: {$result['message']}");
                return false;
            }

            // Update database with extracted text
            $updated = $this->db->update(
                'user_files',
                ['extracted_text' => $result['text']],
                'id = :file_id',
                ['file_id' => $fileId]
            );

            return $updated > 0;

        } catch (Exception $e) {
            error_log('DocumentProcessorService::processFile error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Extract text from PDF file using smalot/pdfparser
     *
     * @param string $filePath Path to PDF file
     * @return array ['success' => bool, 'text' => string, 'message' => string]
     */
    private function extractFromPdf(string $filePath): array {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseFile($filePath);

            $text = $pdf->getText();

            // Clean up text (remove excessive whitespace)
            $text = preg_replace('/\s+/', ' ', $text);
            $text = trim($text);

            // Check if text was extracted
            if (empty($text)) {
                return [
                    'success' => false,
                    'text' => '',
                    'message' => 'Não foi possível extrair texto do PDF (pode ser imagem ou protegido)'
                ];
            }

            // Truncate if too long
            if (strlen($text) > self::MAX_TEXT_LENGTH) {
                $text = substr($text, 0, self::MAX_TEXT_LENGTH);
                $text .= "\n\n[NOTA: Texto truncado. Documento original é maior que o limite de "
                    . number_format(self::MAX_TEXT_LENGTH) . " caracteres]";
            }

            return [
                'success' => true,
                'text' => $text,
                'message' => 'Texto extraído com sucesso'
            ];

        } catch (Exception $e) {
            $errorMsg = $e->getMessage();

            // Handle common PDF errors
            if (strpos($errorMsg, 'password') !== false || strpos($errorMsg, 'encrypted') !== false) {
                return [
                    'success' => false,
                    'text' => '',
                    'message' => 'PDF protegido por senha. Por favor, remova a proteção e tente novamente'
                ];
            }

            if (strpos($errorMsg, 'corrupted') !== false || strpos($errorMsg, 'invalid') !== false) {
                return [
                    'success' => false,
                    'text' => '',
                    'message' => 'PDF corrompido ou inválido'
                ];
            }

            error_log('PDF extraction error: ' . $errorMsg);
            return [
                'success' => false,
                'text' => '',
                'message' => 'Erro ao processar PDF: ' . $errorMsg
            ];
        }
    }

    /**
     * Extract text from DOCX file using phpoffice/phpword
     *
     * @param string $filePath Path to DOCX file
     * @return array ['success' => bool, 'text' => string, 'message' => string]
     */
    private function extractFromDocx(string $filePath): array {
        try {
            $phpWord = PhpWordIOFactory::load($filePath);

            $text = '';

            // Extract text from all sections
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    // Handle different element types
                    if (method_exists($element, 'getText')) {
                        $text .= $element->getText() . "\n";
                    } elseif (method_exists($element, 'getElements')) {
                        // Handle containers (like tables)
                        $text .= $this->extractFromContainer($element) . "\n";
                    }
                }
            }

            // Clean up text
            $text = preg_replace('/\n{3,}/', "\n\n", $text); // Remove excessive newlines
            $text = trim($text);

            // Check if text was extracted
            if (empty($text)) {
                return [
                    'success' => false,
                    'text' => '',
                    'message' => 'Não foi possível extrair texto do DOCX (documento vazio ou formato inválido)'
                ];
            }

            // Truncate if too long
            if (strlen($text) > self::MAX_TEXT_LENGTH) {
                $text = substr($text, 0, self::MAX_TEXT_LENGTH);
                $text .= "\n\n[NOTA: Texto truncado. Documento original é maior que o limite de "
                    . number_format(self::MAX_TEXT_LENGTH) . " caracteres]";
            }

            return [
                'success' => true,
                'text' => $text,
                'message' => 'Texto extraído com sucesso'
            ];

        } catch (Exception $e) {
            $errorMsg = $e->getMessage();

            error_log('DOCX extraction error: ' . $errorMsg);
            return [
                'success' => false,
                'text' => '',
                'message' => 'Erro ao processar DOCX: ' . $errorMsg
            ];
        }
    }

    /**
     * Recursively extract text from container elements (like tables, text runs)
     *
     * @param mixed $container Container element
     * @return string Extracted text
     */
    private function extractFromContainer($container): string {
        $text = '';

        if (method_exists($container, 'getElements')) {
            foreach ($container->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText() . ' ';
                } elseif (method_exists($element, 'getElements')) {
                    // Recursive call for nested containers
                    $text .= $this->extractFromContainer($element);
                }
            }
        }

        return $text;
    }
}
