-- =====================================================
-- MIGRATION 002: Admin Improvements
-- Data: 2025-10-14
-- Descrição: Sistema de configurações dinâmicas e
--            melhorias no gerenciamento de usuários
-- =====================================================

-- 1. Criar tabela de configurações
-- =====================================================
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE COMMENT 'Chave única da configuração',
    `setting_value` TEXT NOT NULL COMMENT 'Valor da configuração',
    `data_type` ENUM('string', 'integer', 'boolean', 'json') DEFAULT 'string' COMMENT 'Tipo de dado',
    `description` VARCHAR(255) NULL COMMENT 'Descrição amigável',
    `is_public` BOOLEAN DEFAULT FALSE COMMENT 'Se pode ser lido sem autenticação',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `updated_by` INT UNSIGNED NULL COMMENT 'Admin que fez a última alteração',
    INDEX `idx_setting_key` (`setting_key`),
    INDEX `idx_is_public` (`is_public`),
    FOREIGN KEY (`updated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Configurações dinâmicas da plataforma';

-- 2. Inserir configurações padrão
-- =====================================================
INSERT INTO `settings` (`setting_key`, `setting_value`, `data_type`, `description`, `is_public`) VALUES
('juridico_requires_approval', '1', 'boolean', 'Se verdadeiro, vertical Jurídico requer aprovação admin. Se falso, acesso direto após onboarding.', FALSE),
('platform_name', 'Plataforma Sunyata', 'string', 'Nome da plataforma exibido no site', TRUE),
('max_users_per_vertical', '1000', 'integer', 'Limite de usuários por vertical (0 = ilimitado)', FALSE)
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- 3. Comentários nas colunas da tabela users (documentação)
-- =====================================================
ALTER TABLE `users`
    MODIFY COLUMN `access_level` ENUM('guest', 'student', 'client', 'admin') NOT NULL DEFAULT 'guest'
    COMMENT 'guest=visitante, student=aluno, client=cliente, admin=administrador';

-- 4. Verificação final
-- =====================================================
SELECT 'Migration 002: Admin Improvements - Concluída com sucesso' as status;
SELECT COUNT(*) as total_settings FROM settings;
