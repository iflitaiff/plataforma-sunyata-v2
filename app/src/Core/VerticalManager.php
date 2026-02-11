<?php
/**
 * VerticalManager - Gerenciador Centralizado de Verticais
 *
 * Responsável por toda a lógica relacionada a verticais:
 * - Carregar definições
 * - Validar disponibilidade
 * - Verificar requisitos (aprovação, info extra)
 * - Fornecer metadados
 *
 * @package Sunyata\Core
 * @since 2025-10-20
 */

namespace Sunyata\Core;

class VerticalManager
{
    /**
     * @var array Configurações de verticais
     */
    private array $verticals = [];

    /**
     * @var Settings Instância do Settings
     */
    private Settings $settings;

    /**
     * @var self Instância única (Singleton)
     */
    private static ?self $instance = null;

    /**
     * Constructor privado (Singleton)
     */
    private function __construct()
    {
        $this->settings = Settings::getInstance();
        $this->loadVerticals();
    }

    /**
     * Obter instância única
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Carregar verticais do arquivo de configuração
     */
    private function loadVerticals(): void
    {
        $configPath = __DIR__ . '/../../config/verticals.php';
        if (!file_exists($configPath)) {
            throw new \RuntimeException('Arquivo de configuração de verticais não encontrado');
        }

        $this->verticals = require $configPath;

        // Processar aprovações dinâmicas
        foreach ($this->verticals as $slug => &$vertical) {
            if (isset($vertical['requer_aprovacao_setting'])) {
                $settingKey = $vertical['requer_aprovacao_setting'];
                $vertical['requer_aprovacao'] = (bool) $this->settings->get($settingKey, true);
            }
        }

        // Ordenar por ordem definida
        uasort($this->verticals, function ($a, $b) {
            return ($a['ordem'] ?? 999) <=> ($b['ordem'] ?? 999);
        });
    }

    /**
     * Obter todas as verticais
     *
     * @param bool $onlyAvailable Se true, retorna apenas verticais disponíveis
     * @return array
     */
    public function getAll(bool $onlyAvailable = false): array
    {
        if ($onlyAvailable) {
            return array_filter($this->verticals, function ($vertical) {
                return $vertical['disponivel'] === true;
            });
        }

        return $this->verticals;
    }

    /**
     * Obter vertical específica
     *
     * @param string $slug
     * @return array|null
     */
    public function get(string $slug): ?array
    {
        return $this->verticals[$slug] ?? null;
    }

    /**
     * Verificar se vertical existe
     *
     * @param string $slug
     * @return bool
     */
    public function exists(string $slug): bool
    {
        return isset($this->verticals[$slug]);
    }

    /**
     * Verificar se vertical está disponível
     *
     * @param string $slug
     * @return bool
     */
    public function isAvailable(string $slug): bool
    {
        $vertical = $this->get($slug);
        return $vertical !== null && $vertical['disponivel'] === true;
    }

    /**
     * Verificar se vertical requer aprovação
     *
     * @param string $slug
     * @return bool
     */
    public function requiresApproval(string $slug): bool
    {
        $vertical = $this->get($slug);
        if ($vertical === null) {
            return false;
        }

        // Se tem setting dinâmico, consulta
        if (isset($vertical['requer_aprovacao_setting'])) {
            $settingKey = $vertical['requer_aprovacao_setting'];
            return (bool) $this->settings->get($settingKey, true);
        }

        return $vertical['requer_aprovacao'] ?? false;
    }

    /**
     * Verificar se vertical requer informações extras
     *
     * @param string $slug
     * @return bool
     */
    public function requiresExtraInfo(string $slug): bool
    {
        $vertical = $this->get($slug);
        return $vertical !== null && ($vertical['requer_info_extra'] ?? false);
    }

    /**
     * Obter URL do formulário extra (IFRJ, etc)
     *
     * @param string $slug
     * @return string|null
     */
    public function getExtraForm(string $slug): ?string
    {
        $vertical = $this->get($slug);
        return $vertical['form_extra'] ?? null;
    }

    /**
     * Obter URL do formulário de aprovação (Jurídico, etc)
     *
     * @param string $slug
     * @return string|null
     */
    public function getApprovalForm(string $slug): ?string
    {
        $vertical = $this->get($slug);
        return $vertical['form_aprovacao'] ?? null;
    }

    /**
     * Obter verticais que podem ser salvas diretamente
     * (não requerem aprovação nem info extra)
     *
     * @return array Array de slugs
     */
    public function getDirectVerticals(): array
    {
        $direct = [];

        foreach ($this->verticals as $slug => $vertical) {
            if (!$vertical['disponivel']) {
                continue;
            }

            $requiresApproval = $this->requiresApproval($slug);
            $requiresExtraInfo = $this->requiresExtraInfo($slug);

            if (!$requiresApproval && !$requiresExtraInfo) {
                $direct[] = $slug;
            }
        }

        return $direct;
    }

    /**
     * Verificar se vertical pode ser acessada diretamente
     * (sem passar por formulários extras ou aprovação)
     *
     * @param string $slug
     * @return bool
     */
    public function canAccessDirectly(string $slug): bool
    {
        if (!$this->isAvailable($slug)) {
            return false;
        }

        return !$this->requiresApproval($slug) && !$this->requiresExtraInfo($slug);
    }

    /**
     * Obter descrição completa da vertical
     * Inclui texto de aprovação se necessário
     *
     * @param string $slug
     * @return string
     */
    public function getFullDescription(string $slug): string
    {
        $vertical = $this->get($slug);
        if ($vertical === null) {
            return '';
        }

        $description = $vertical['descricao'];

        // Adicionar texto de aprovação se necessário
        if ($this->requiresApproval($slug) && isset($vertical['descricao_aprovacao'])) {
            $description .= $vertical['descricao_aprovacao'];
        }

        return $description;
    }

    /**
     * Obter metadados formatados para exibição
     *
     * @param string $slug
     * @return array
     */
    public function getDisplayData(string $slug): array
    {
        $vertical = $this->get($slug);
        if ($vertical === null) {
            return [];
        }

        return [
            'slug' => $slug,
            'nome' => $vertical['nome'],
            'icone' => $vertical['icone'],
            'descricao' => $this->getFullDescription($slug),
            'ferramentas' => $vertical['ferramentas'] ?? [],
            'disponivel' => $vertical['disponivel'],
            'requer_aprovacao' => $this->requiresApproval($slug),
            'requer_info_extra' => $this->requiresExtraInfo($slug),
            'form_extra' => $this->getExtraForm($slug),
            'form_aprovacao' => $this->getApprovalForm($slug)
        ];
    }

    /**
     * Obter todas as verticais formatadas para exibição
     *
     * @param bool $onlyAvailable
     * @return array Array associativo [slug => display_data]
     */
    public function getAllDisplayData(bool $onlyAvailable = false): array
    {
        $verticals = $this->getAll($onlyAvailable);
        $displayData = [];

        foreach ($verticals as $slug => $vertical) {
            $displayData[$slug] = $this->getDisplayData($slug);
        }

        return $displayData;
    }

    /**
     * Validar se slug é válido para salvar diretamente
     *
     * @param string $slug
     * @return bool
     */
    public function isValidForDirectSave(string $slug): bool
    {
        return in_array($slug, $this->getDirectVerticals(), true);
    }

    /**
     * Recarregar verticais (útil quando settings mudam)
     */
    public function reload(): void
    {
        $this->loadVerticals();
    }

    /**
     * Obter limite máximo de usuários para uma vertical
     *
     * @param string $slug
     * @return int|null NULL se não tem limite
     */
    public function getUserLimit(string $slug): ?int
    {
        $vertical = $this->get($slug);
        return $vertical['max_users'] ?? null;
    }

    /**
     * Obter contagem atual de usuários ativos na vertical
     *
     * @param string $slug
     * @return int
     */
    public function getCurrentUserCount(string $slug): int
    {
        $db = Database::getInstance();

        $result = $db->fetchOne(
            "SELECT COUNT(*) as count FROM users WHERE selected_vertical = :slug",
            ['slug' => $slug]
        );

        return (int) ($result['count'] ?? 0);
    }

    /**
     * Verificar se vertical atingiu limite de usuários
     *
     * @param string $slug
     * @return bool TRUE se atingiu o limite, FALSE caso contrário
     */
    public function hasReachedUserLimit(string $slug): bool
    {
        $limit = $this->getUserLimit($slug);

        // Se não tem limite configurado, nunca atinge
        if ($limit === null) {
            return false;
        }

        $current = $this->getCurrentUserCount($slug);

        return $current >= $limit;
    }
}
