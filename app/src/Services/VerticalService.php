<?php
/**
 * VerticalService - Gerenciamento de Verticais
 *
 * Fonte de verdade híbrida: Tabela `verticals` (DB) + config/verticals.php (fallback)
 * Prioridade: DB > Config file
 *
 * @package Sunyata\Services
 * @since 2026-02-18 (Fase 3.5)
 */

namespace Sunyata\Services;

use Sunyata\Core\Database;
use Exception;

class VerticalService
{
    private static ?VerticalService $instance = null;
    private Database $db;
    private ?array $cachedVerticals = null;

    private function __construct()
    {
        $this->db = Database::getInstance();
    }

    public static function getInstance(): VerticalService
    {
        if (self::$instance === null) {
            self::$instance = new VerticalService();
        }
        return self::$instance;
    }

    /**
     * Obter todas as verticais (DB + Config file merged)
     *
     * @param bool $forceRefresh Forçar reload do cache
     * @return array Verticais no formato ['slug' => [...metadata]]
     */
    public function getAll(bool $forceRefresh = false): array
    {
        if ($this->cachedVerticals !== null && !$forceRefresh) {
            return $this->cachedVerticals;
        }

        // 1. Carregar do arquivo de config (fallback/legacy)
        $configPath = __DIR__ . '/../../config/verticals.php';
        $configVerticals = file_exists($configPath) ? require $configPath : [];

        // 2. Carregar do banco de dados (V2 PostgreSQL schema)
        try {
            $dbVerticals = $this->db->fetchAll("
                SELECT
                    slug,
                    name,
                    config,
                    is_active,
                    created_at,
                    updated_at
                FROM verticals
                WHERE is_active = true
                ORDER BY name ASC
            ");

            // Converter para formato associativo (slug => data)
            // Mapear colunas V2 (PostgreSQL) para formato esperado V1 (retrocompat)
            $dbVerticalsIndexed = [];
            foreach ($dbVerticals as $vertical) {
                $slug = $vertical['slug'];

                // Decodificar config JSONB
                $config = !empty($vertical['config'])
                    ? (is_string($vertical['config']) ? json_decode($vertical['config'], true) : $vertical['config'])
                    : [];

                // Mapear para formato V1 esperado
                $dbVerticalsIndexed[$slug] = [
                    'slug' => $vertical['slug'],
                    'nome' => $vertical['name'],  // V2: 'name' → V1: 'nome'
                    'icone' => $config['icon'] ?? '',
                    'descricao' => $config['description'] ?? '',
                    'ordem' => $config['order'] ?? 0,
                    'disponivel' => $vertical['is_active'],  // V2: 'is_active' → V1: 'disponivel'
                    'requer_aprovacao' => $config['requires_approval'] ?? false,
                    'max_users' => $config['max_users'] ?? null,
                    'api_params' => $config['api_params'] ?? [],
                    'created_at' => $vertical['created_at'],
                    'updated_at' => $vertical['updated_at'],
                ];
            }
        } catch (Exception $e) {
            // Se tabela não existe ou erro, usar apenas config
            error_log("VerticalService: Erro ao carregar do DB, usando apenas config: " . $e->getMessage());
            $dbVerticalsIndexed = [];
        }

        // 3. Merge: DB sobrescreve config (prioridade DB)
        $merged = array_merge($configVerticals, $dbVerticalsIndexed);

        // 4. Ordenar por 'ordem' field
        uasort($merged, function ($a, $b) {
            return ($a['ordem'] ?? 999) <=> ($b['ordem'] ?? 999);
        });

        $this->cachedVerticals = $merged;
        return $merged;
    }

    /**
     * Obter uma vertical específica por slug
     *
     * @param string $slug
     * @return array|null Vertical metadata ou null se não encontrada
     */
    public function get(string $slug): ?array
    {
        $all = $this->getAll();
        return $all[$slug] ?? null;
    }

    /**
     * Criar nova vertical no banco de dados
     *
     * V2: Aceita dados em formato V1 (retrocompat) e converte para V2 schema
     *
     * @param array $data Dados da vertical (slug, nome/name, icone/icon, etc)
     * @return int ID da vertical criada
     * @throws Exception
     */
    public function create(array $data): int
    {
        // Aceitar 'nome' ou 'name' (retrocompat)
        $name = $data['name'] ?? $data['nome'] ?? null;
        $slug = $data['slug'] ?? null;

        // Validar campos obrigatórios
        if (empty($slug)) {
            throw new Exception("Campo obrigatório ausente: slug");
        }
        if (empty($name)) {
            throw new Exception("Campo obrigatório ausente: name/nome");
        }

        // Validar slug único
        if ($this->slugExists($slug)) {
            throw new Exception("Slug '{$slug}' já existe");
        }

        // Construir config JSONB (V2 schema)
        $config = [];

        // Aceitar icone/icon (retrocompat)
        if (!empty($data['icone']) || !empty($data['icon'])) {
            $config['icon'] = $data['icon'] ?? $data['icone'];
        }

        // Outros campos vão para config
        if (!empty($data['descricao']) || !empty($data['description'])) {
            $config['description'] = $data['description'] ?? $data['descricao'];
        }

        $config['order'] = $data['order'] ?? $data['ordem'] ?? 999;
        $config['requires_approval'] = $data['requires_approval'] ?? $data['requer_aprovacao'] ?? false;
        $config['max_users'] = $data['max_users'] ?? null;

        // API params
        if (!empty($data['api_params'])) {
            $config['api_params'] = is_array($data['api_params'])
                ? $data['api_params']
                : json_decode($data['api_params'], true);
        }

        // Preparar dados para V2 schema
        $insertData = [
            'slug' => $slug,
            'name' => $name,  // V2: 'name' column
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE),  // V2: JSONB
            'is_active' => $data['is_active'] ?? $data['disponivel'] ?? true,  // V2: 'is_active'
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $id = $this->db->insert('verticals', $insertData);

        // Limpar cache
        $this->cachedVerticals = null;

        return $id;
    }

    /**
     * Atualizar vertical existente
     *
     * V2: Aceita dados em formato V1 (retrocompat) e converte para V2 schema
     *
     * @param int $id ID da vertical
     * @param array $data Dados a atualizar
     * @return bool Sucesso
     * @throws Exception
     */
    public function update(int $id, array $data): bool
    {
        // Verificar se vertical existe
        $existing = $this->db->fetchOne("SELECT * FROM verticals WHERE id = :id", ['id' => $id]);
        if (!$existing) {
            throw new Exception("Vertical ID $id não encontrada");
        }

        // Se slug mudou, validar unicidade
        if (isset($data['slug']) && $data['slug'] !== $existing['slug']) {
            if ($this->slugExists($data['slug'], $id)) {
                throw new Exception("Slug '{$data['slug']}' já existe");
            }
        }

        // Preparar dados para V2 schema
        $updateData = [];

        // Slug (V2: direct column)
        if (isset($data['slug'])) {
            $updateData['slug'] = $data['slug'];
        }

        // Name (V2: aceitar 'name' ou 'nome' para retrocompat)
        if (isset($data['name']) || isset($data['nome'])) {
            $updateData['name'] = $data['name'] ?? $data['nome'];
        }

        // is_active (V2: aceitar 'is_active' ou 'disponivel')
        if (isset($data['is_active']) || isset($data['disponivel'])) {
            $updateData['is_active'] = $data['is_active'] ?? $data['disponivel'];
        }

        // Config JSONB (V2: merge com config existente)
        $needsConfigUpdate = false;
        $config = !empty($existing['config'])
            ? (is_string($existing['config']) ? json_decode($existing['config'], true) : $existing['config'])
            : [];

        // Icon/Icone
        if (isset($data['icon']) || isset($data['icone'])) {
            $config['icon'] = $data['icon'] ?? $data['icone'];
            $needsConfigUpdate = true;
        }

        // Description/Descricao
        if (isset($data['description']) || isset($data['descricao'])) {
            $config['description'] = $data['description'] ?? $data['descricao'];
            $needsConfigUpdate = true;
        }

        // Order/Ordem
        if (isset($data['order']) || isset($data['ordem'])) {
            $config['order'] = $data['order'] ?? $data['ordem'];
            $needsConfigUpdate = true;
        }

        // Requires approval
        if (isset($data['requires_approval']) || isset($data['requer_aprovacao'])) {
            $config['requires_approval'] = $data['requires_approval'] ?? $data['requer_aprovacao'];
            $needsConfigUpdate = true;
        }

        // Max users
        if (isset($data['max_users'])) {
            $config['max_users'] = $data['max_users'];
            $needsConfigUpdate = true;
        }

        // API params
        if (isset($data['api_params'])) {
            $config['api_params'] = is_array($data['api_params'])
                ? $data['api_params']
                : json_decode($data['api_params'], true);
            $needsConfigUpdate = true;
        }

        // Se config mudou, atualizar
        if ($needsConfigUpdate) {
            $updateData['config'] = json_encode($config, JSON_UNESCAPED_UNICODE);
        }

        // Se não há nada para atualizar, retornar true
        if (empty($updateData)) {
            return true;
        }

        $updateData['updated_at'] = date('Y-m-d H:i:s');

        $success = $this->db->update('verticals', $updateData, 'id = :id', ['id' => $id]);

        // Limpar cache
        $this->cachedVerticals = null;

        return $success;
    }

    /**
     * Deletar vertical (soft delete - marca como indisponível)
     *
     * @param int $id ID da vertical
     * @return bool Sucesso
     * @throws Exception
     */
    public function delete(int $id): bool
    {
        // Verificar se há canvas associados (V2: usar junction table)
        $canvasCount = $this->db->fetchOne("
            SELECT COUNT(*) as count
            FROM canvas_vertical_assignments cva
            JOIN verticals v ON v.slug = cva.vertical_slug
            WHERE v.id = :id
        ", ['id' => $id]);

        if ($canvasCount['count'] > 0) {
            throw new Exception("Não é possível deletar vertical com {$canvasCount['count']} canvas associados");
        }

        // Soft delete (marca como inativa - V2: is_active ao invés de disponivel)
        $success = $this->db->update('verticals', [
            'is_active' => false,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $id]);

        // Limpar cache
        $this->cachedVerticals = null;

        return $success;
    }

    /**
     * Hard delete (remover permanentemente do banco)
     *
     * @param int $id ID da vertical
     * @return bool Sucesso
     * @throws Exception
     */
    public function hardDelete(int $id): bool
    {
        // Verificar se há canvas associados (V2: usar junction table)
        $canvasCount = $this->db->fetchOne("
            SELECT COUNT(*) as count
            FROM canvas_vertical_assignments cva
            JOIN verticals v ON v.slug = cva.vertical_slug
            WHERE v.id = :id
        ", ['id' => $id]);

        if ($canvasCount['count'] > 0) {
            throw new Exception("Não é possível deletar vertical com {$canvasCount['count']} canvas associados");
        }

        $success = $this->db->delete('verticals', 'id = :id', ['id' => $id]);

        // Limpar cache
        $this->cachedVerticals = null;

        return $success;
    }

    /**
     * Verificar se slug já existe
     *
     * @param string $slug
     * @param int|null $excludeId ID para excluir da verificação (usado em updates)
     * @return bool
     */
    private function slugExists(string $slug, ?int $excludeId = null): bool
    {
        // Verificar no banco
        $query = "SELECT COUNT(*) as count FROM verticals WHERE slug = :slug";
        $params = ['slug' => $slug];

        if ($excludeId !== null) {
            $query .= " AND id != :id";
            $params['id'] = $excludeId;
        }

        try {
            $result = $this->db->fetchOne($query, $params);
            if ($result['count'] > 0) {
                return true;
            }
        } catch (Exception $e) {
            // Tabela não existe, verificar apenas config
        }

        // Verificar no config file
        $all = $this->getAll(true); // Force refresh
        return isset($all[$slug]);
    }

    /**
     * Reordenar verticais (bulk update de ordem)
     *
     * @param array $orderedSlugs Array de slugs na ordem desejada
     * @return bool Sucesso
     */
    public function reorder(array $orderedSlugs): bool
    {
        try {
            $this->db->beginTransaction();

            foreach ($orderedSlugs as $index => $slug) {
                $ordem = $index + 1;
                $this->db->update('verticals',
                    ['ordem' => $ordem, 'updated_at' => date('Y-m-d H:i:s')],
                    'slug = :slug',
                    ['slug' => $slug]
                );
            }

            $this->db->commit();
            $this->cachedVerticals = null;
            return true;

        } catch (Exception $e) {
            $this->db->rollback();
            error_log("VerticalService::reorder error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Limpar cache (útil após modificações externas)
     */
    public function clearCache(): void
    {
        $this->cachedVerticals = null;
    }
}
