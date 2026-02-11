<?php
/**
 * Settings Manager - Gerenciador de Configurações Dinâmicas
 *
 * Singleton pattern para gerenciar configurações da plataforma
 * armazenadas no banco de dados. Inclui cache em memória para performance.
 *
 * @package Sunyata\Core
 * @author Claude Code
 * @version 1.0.0
 */

namespace Sunyata\Core;

use PDOException;

class Settings {
    private static $instance = null;
    private $db;
    private $cache = []; // Cache em memória para evitar queries repetidas

    /**
     * Construtor privado (Singleton)
     */
    private function __construct() {
        $this->db = Database::getInstance();
    }

    /**
     * Obtém instância única (Singleton)
     *
     * @return Settings
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obtém valor de uma configuração
     *
     * @param string $key Chave da configuração
     * @param mixed $default Valor padrão se não encontrado
     * @return mixed Valor da configuração
     */
    public function get(string $key, $default = null) {
        // Verifica cache
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        try {
            $setting = $this->db->fetchOne(
                "SELECT setting_value, data_type FROM settings WHERE setting_key = :key",
                ['key' => $key]
            );

            if (!$setting) {
                return $default;
            }

            // Converte para o tipo correto
            $value = $this->castValue($setting['setting_value'], $setting['data_type']);

            // Armazena no cache
            $this->cache[$key] = $value;

            return $value;

        } catch (PDOException $e) {
            error_log("Settings::get() failed for key '{$key}': " . $e->getMessage());
            return $default;
        }
    }

    /**
     * Define valor de uma configuração
     *
     * @param string $key Chave da configuração
     * @param mixed $value Valor a ser armazenado
     * @param int|null $adminId ID do admin fazendo a alteração
     * @return bool Sucesso da operação
     */
    public function set(string $key, $value, ?int $adminId = null): bool {
        try {
            // Busca configuração existente para pegar o data_type
            $existing = $this->db->fetchOne(
                "SELECT data_type FROM settings WHERE setting_key = :key",
                ['key' => $key]
            );

            if (!$existing) {
                error_log("Settings::set() failed: Setting '{$key}' does not exist");
                return false;
            }

            // Converte valor para string apropriada
            $stringValue = $this->valueToString($value, $existing['data_type']);

            // Atualiza no banco
            $updated = $this->db->update('settings', [
                'setting_value' => $stringValue,
                'updated_by' => $adminId
            ], 'setting_key = :key', ['key' => $key]);

            if ($updated) {
                // Limpa cache
                unset($this->cache[$key]);

                // Log de auditoria
                if ($adminId) {
                    $this->db->insert('audit_logs', [
                        'user_id' => $adminId,
                        'action' => 'setting_updated',
                        'entity_type' => 'settings',
                        'entity_id' => null,
                        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                        'details' => json_encode(['key' => $key, 'new_value' => $stringValue])
                    ]);
                }

                return true;
            }

            return false;

        } catch (PDOException $e) {
            error_log("Settings::set() failed for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Alterna valor booleano (útil para toggles)
     *
     * @param string $key Chave da configuração
     * @param int|null $adminId ID do admin fazendo a alteração
     * @return bool Novo valor após toggle
     */
    public function toggle(string $key, ?int $adminId = null): bool {
        $currentValue = $this->get($key, false);
        $newValue = !$currentValue;
        $this->set($key, $newValue, $adminId);
        return $newValue;
    }

    /**
     * Obtém todas as configurações (para admin)
     *
     * @return array
     */
    public function getAll(): array {
        try {
            return $this->db->fetchAll(
                "SELECT setting_key, setting_value, data_type, description, updated_at
                 FROM settings
                 ORDER BY setting_key"
            );
        } catch (PDOException $e) {
            error_log("Settings::getAll() CRITICAL error: " . $e->getMessage());
            // Re-throw para forçar tratamento explícito no código chamador
            throw new PDOException(
                'Failed to fetch all settings from database: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Converte string do banco para tipo correto
     *
     * @param string $value Valor em string
     * @param string $type Tipo de dado
     * @return mixed Valor convertido
     */
    private function castValue(string $value, string $type) {
        switch ($type) {
            case 'boolean':
                return (bool)(int)$value;
            case 'integer':
                return (int)$value;
            case 'json':
                return json_decode($value, true);
            case 'string':
            default:
                return $value;
        }
    }

    /**
     * Converte valor para string para armazenar no banco
     *
     * @param mixed $value Valor a converter
     * @param string $type Tipo de dado
     * @return string Valor em string
     */
    private function valueToString($value, string $type): string {
        switch ($type) {
            case 'boolean':
                return $value ? '1' : '0';
            case 'integer':
                return (string)(int)$value;
            case 'json':
                return json_encode($value);
            case 'string':
            default:
                return (string)$value;
        }
    }

    /**
     * Limpa todo o cache (útil para testes)
     */
    public function clearCache(): void {
        $this->cache = [];
    }
}
