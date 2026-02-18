<?php
/**
 * Database Connection Handler — PostgreSQL
 *
 * @package Sunyata\Core
 */

namespace Sunyata\Core;

use PDO;
use PDOException;

class Database {
    private static $instance = null;
    private $pdo;
    private $dsn;

    /**
     * Whitelist de tabelas permitidas (proteção contra SQL injection)
     */
    private const ALLOWED_TABLES = [
        'users',
        'canvas_templates',
        'prompt_history',
        'form_drafts',
        'settings',
        'verticals',
        'conversations',
        'conversation_messages',
        'audit_logs',
        'sessions',
        'user_files',
        'file_references',
    ];

    /**
     * Whitelist de colunas por tabela (proteção contra SQL injection)
     */
    private const ALLOWED_COLUMNS = [
        'users' => [
            'id', 'google_id', 'email', 'name', 'picture', 'password_hash',
            'access_level', 'selected_vertical', 'completed_onboarding',
            'is_demo', 'created_at', 'updated_at', 'last_login'
        ],
        'canvas_templates' => [
            'id', 'vertical_slug', 'slug', 'name', 'description', 'icon',
            'form_config', 'system_prompt', 'output_format', 'requires_approval',
            'approval_flow', 'api_params_override', 'created_at', 'updated_at', 'active'
        ],
        'prompt_history' => [
            'id', 'user_id', 'vertical', 'tool_name', 'input_data', 'generated_prompt',
            'claude_response', 'claude_model', 'temperature', 'max_tokens', 'top_p',
            'system_prompt_sent', 'tokens_input', 'tokens_output', 'tokens_total',
            'cost_usd', 'response_time_ms', 'status', 'error_message',
            'ip_address', 'user_agent', 'created_at'
        ],
        'form_drafts' => [
            'id', 'user_id', 'canvas_template_id', 'label', 'form_data',
            'page_no', 'created_at', 'updated_at', 'expires_at'
        ],
        'settings' => [
            'id', 'setting_key', 'setting_value', 'data_type', 'description',
            'created_at', 'updated_at'
        ],
        'verticals' => [
            'id', 'slug', 'name', 'icon', 'description', 'active',
            'config', 'created_at', 'updated_at'
        ],
        'conversations' => [
            'id', 'user_id', 'vertical', 'canvas_template_id', 'title',
            'created_at', 'updated_at'
        ],
        'conversation_messages' => [
            'id', 'conversation_id', 'role', 'content', 'created_at'
        ],
        'audit_logs' => [
            'id', 'user_id', 'action', 'entity_type', 'entity_id',
            'ip_address', 'user_agent', 'details', 'created_at'
        ],
        'sessions' => [
            'id', 'user_id', 'ip_address', 'user_agent',
            'last_activity', 'created_at'
        ],
    ];

    private function __construct() {
        $this->connect();
    }

    /**
     * Estabelece conexão com o banco de dados (PostgreSQL)
     */
    private function connect(): void {
        try {
            $this->dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                DB_HOST,
                defined('DB_PORT') ? DB_PORT : '5432',
                DB_NAME
            );

            $this->pdo = new PDO($this->dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_CASE => PDO::CASE_LOWER,
                PDO::ATTR_EMULATE_PREPARES => true, // Required for LIMIT/OFFSET with PDO+PostgreSQL
            ]);

            // Set timezone to match PHP
            $this->pdo->exec("SET timezone = 'America/Sao_Paulo'");
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new \Exception('Database connection error');
        }
    }

    /**
     * Reconecta ao banco de dados se necessário
     */
    public function reconnect(): void {
        $this->pdo = null;
        $this->connect();
    }

    /**
     * Verifica se a conexão está ativa
     */
    public function isConnected(): bool {
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->pdo;
    }

    /**
     * Prepare a statement (used by ConversationService)
     */
    public function prepare($sql) {
        return $this->pdo->prepare($sql);
    }

    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            // PostgreSQL connection error SQLSTATE codes
            $sqlState = $e->getCode();
            if (in_array($sqlState, ['08000', '08003', '08006', '57P01'])) {
                // Connection exception / admin shutdown
                error_log('Database connection lost, attempting reconnect...');
                $this->reconnect();

                try {
                    $stmt = $this->pdo->prepare($sql);
                    $stmt->execute($params);
                    error_log('Database reconnection successful');
                    return $stmt;
                } catch (PDOException $retryException) {
                    error_log('Query failed after reconnect: ' . $retryException->getMessage() . ' SQL: ' . $sql);
                    throw new \Exception('Database query error');
                }
            }

            error_log('Query failed: ' . $e->getMessage() . ' SQL: ' . $sql);
            throw new \Exception('Database query error');
        }
    }

    public function fetchOne($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Valida nome de tabela contra whitelist
     *
     * @param string $table Nome da tabela
     * @throws \Exception Se tabela não está na whitelist
     */
    private function validateTable(string $table): void {
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            error_log("Security: Tentativa de acesso a tabela não permitida: {$table}");
            throw new \Exception("Invalid table name: {$table}");
        }
    }

    /**
     * Valida nomes de colunas contra whitelist da tabela
     *
     * @param string $table Nome da tabela
     * @param array $columns Array de nomes de colunas
     * @throws \Exception Se alguma coluna não está na whitelist
     */
    private function validateColumns(string $table, array $columns): void {
        if (!isset(self::ALLOWED_COLUMNS[$table])) {
            error_log("Security: Tabela sem whitelist de colunas definida: {$table}");
            throw new \Exception("No column whitelist defined for table: {$table}");
        }

        $allowedColumns = self::ALLOWED_COLUMNS[$table];

        foreach ($columns as $column) {
            if (!in_array($column, $allowedColumns, true)) {
                error_log("Security: Tentativa de acesso a coluna não permitida: {$table}.{$column}");
                throw new \Exception("Invalid column name for table {$table}: {$column}");
            }
        }
    }

    public function insert($table, $data) {
        // Validação de segurança contra SQL injection
        $this->validateTable($table);
        $this->validateColumns($table, array_keys($data));

        // Agora é seguro usar os valores validados
        $keys = array_keys($data);
        $fields = implode(', ', $keys);
        $placeholders = implode(', ', array_map(fn($k) => ":$k", $keys));

        $sql = "INSERT INTO $table ($fields) VALUES ($placeholders)";
        $this->query($sql, $data);

        // PostgreSQL lastInsertId needs sequence name
        return $this->pdo->lastInsertId($table . '_id_seq');
    }

    public function update($table, $data, $where, $whereParams = []) {
        // Validação de segurança contra SQL injection
        $this->validateTable($table);
        $this->validateColumns($table, array_keys($data));

        // Agora é seguro usar os valores validados
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($data)));
        $sql = "UPDATE $table SET $set WHERE $where";

        $params = array_merge($data, $whereParams);
        $stmt = $this->query($sql, $params);

        return $stmt->rowCount();
    }

    public function delete($table, $where, $whereParams = []) {
        // Validação de segurança contra SQL injection
        $this->validateTable($table);

        // Agora é seguro usar o nome da tabela validado
        $sql = "DELETE FROM $table WHERE $where";
        $stmt = $this->query($sql, $whereParams);

        return $stmt->rowCount();
    }

    /**
     * Execute a raw SQL statement (used by canvas-clone-process etc.)
     */
    public function execute($sql, $params = []) {
        return $this->query($sql, $params);
    }

    /**
     * Get last insert ID with explicit sequence name
     */
    public function lastInsertId($sequence = null) {
        return $this->pdo->lastInsertId($sequence);
    }

    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }

    public function commit() {
        return $this->pdo->commit();
    }

    public function rollback() {
        return $this->pdo->rollBack();
    }
}
