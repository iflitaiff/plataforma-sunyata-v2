<?php
/**
 * LGPD Consent Manager
 *
 * @package Sunyata\Compliance
 */

namespace Sunyata\Compliance;

use Sunyata\Core\Database;

class ConsentManager {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Record user consent
     */
    public function recordConsent($userId, $consentType, $consentGiven, $consentText) {
        $consentData = [
            'user_id' => $userId,
            'consent_type' => $consentType,
            'consent_given' => $consentGiven ? 1 : 0,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'consent_text' => $consentText,
            'consent_version' => CONSENT_VERSION
        ];

        return $this->db->insert('consents', $consentData);
    }

    /**
     * Check if user has given consent
     */
    public function hasConsent($userId, $consentType) {
        $sql = "SELECT * FROM consents
                WHERE user_id = :user_id
                AND consent_type = :consent_type
                AND consent_given = 1
                AND revoked_at IS NULL
                ORDER BY created_at DESC
                LIMIT 1";

        $consent = $this->db->fetchOne($sql, [
            'user_id' => $userId,
            'consent_type' => $consentType
        ]);

        return $consent !== false;
    }

    /**
     * Get user's consent history
     */
    public function getConsentHistory($userId) {
        $sql = "SELECT * FROM consents
                WHERE user_id = :user_id
                ORDER BY created_at DESC";

        return $this->db->fetchAll($sql, ['user_id' => $userId]);
    }

    /**
     * Revoke consent
     */
    public function revokeConsent($userId, $consentType) {
        $sql = "UPDATE consents
                SET revoked_at = NOW()
                WHERE user_id = :user_id
                AND consent_type = :consent_type
                AND revoked_at IS NULL";

        $stmt = $this->db->query($sql, [
            'user_id' => $userId,
            'consent_type' => $consentType
        ]);

        // Log the revocation
        $this->logAudit($userId, 'consent_revoked', 'consents', null, [
            'consent_type' => $consentType
        ]);

        return $stmt->rowCount();
    }

    /**
     * Get consent text templates
     */
    public function getConsentText($type, $vertical = null) {
        $texts = [
            'terms_of_use' => 'Ao utilizar a Plataforma Sunyata, você concorda com nossos Termos de Uso, incluindo a utilização de inteligência artificial generativa para fins educacionais e de consultoria.',

            'privacy_policy' => 'Li e concordo com a Política de Privacidade da Sunyata Consulting, que descreve como meus dados pessoais serão coletados, armazenados e processados de acordo com a LGPD.',

            'data_processing' => 'Autorizo o processamento dos meus dados pessoais (nome, email, histórico de uso) para personalização da experiência na plataforma e análise de desempenho.',

            'marketing' => 'Concordo em receber comunicações por email sobre novidades, cursos e conteúdos da Sunyata Consulting. Posso cancelar a qualquer momento.'
        ];

        // Add vertical-specific disclaimers
        $verticalDisclaimers = [
            'sales' => "\n\nAVISO IMPORTANTE: Os conteúdos de IA generativa fornecidos são sugestões educacionais. A responsabilidade final por comunicações comerciais e cumprimento de regulamentações (Código de Defesa do Consumidor, Lei de Proteção de Dados) é exclusivamente sua.",

            'marketing' => "\n\nAVISO IMPORTANTE: Os conteúdos gerados por IA são ferramentas de apoio criativo. Você é responsável por garantir que todo material publicado esteja em conformidade com legislação de publicidade, direitos autorais e LGPD.",

            'customer_service' => "\n\nAVISO IMPORTANTE: Recomendações de IA para atendimento são orientações gerais. Decisões finais sobre resolução de casos, especialmente questões legais ou críticas, devem ser tomadas por profissionais qualificados.",

            'hr' => "\n\nAVISO IMPORTANTE: Sugestões de IA para processos de RH são educacionais. Processos seletivos, avaliações e decisões trabalhistas devem seguir legislação vigente (CLT, Lei Antidiscriminação) e não devem basear-se exclusivamente em sistemas automatizados."
        ];

        $text = $texts[$type] ?? '';

        if ($vertical && isset($verticalDisclaimers[$vertical])) {
            $text .= $verticalDisclaimers[$vertical];
        }

        return $text;
    }

    /**
     * Check if user needs to consent (version changed)
     */
    public function needsConsent($userId, $consentType) {
        $sql = "SELECT * FROM consents
                WHERE user_id = :user_id
                AND consent_type = :consent_type
                AND consent_version = :version
                AND consent_given = 1
                AND revoked_at IS NULL
                LIMIT 1";

        $consent = $this->db->fetchOne($sql, [
            'user_id' => $userId,
            'consent_type' => $consentType,
            'version' => CONSENT_VERSION
        ]);

        return $consent === false;
    }

    /**
     * Get all consents for user (for data export - LGPD Article 18)
     */
    public function exportUserConsents($userId) {
        return $this->getConsentHistory($userId);
    }

    /**
     * Log audit event
     */
    private function logAudit($userId, $action, $entityType, $entityId, $details = []) {
        $auditData = [
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'details' => json_encode($details)
        ];

        $this->db->insert('audit_logs', $auditData);
    }
}
