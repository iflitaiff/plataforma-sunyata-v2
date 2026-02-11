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

    public function insert($table, $data) {
        $keys = array_keys($data);
        $fields = implode(', ', $keys);
        $placeholders = implode(', ', array_map(fn($k) => ":$k", $keys));

        $sql = "INSERT INTO $table ($fields) VALUES ($placeholders)";
        $this->query($sql, $data);

        // PostgreSQL lastInsertId needs sequence name
        return $this->pdo->lastInsertId($table . '_id_seq');
    }

    public function update($table, $data, $where, $whereParams = []) {
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($data)));
        $sql = "UPDATE $table SET $set WHERE $where";

        $params = array_merge($data, $whereParams);
        $stmt = $this->query($sql, $params);

        return $stmt->rowCount();
    }

    public function delete($table, $where, $whereParams = []) {
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
