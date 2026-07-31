<?php

declare(strict_types=1);

namespace src\Migration;

class CreateSecurityAuditEvent extends \app\Console\Migration
{
    protected array $tables = ['security_audit_event'];

    public function up(): void
    {
        $this->execute(
            "CREATE TABLE IF NOT EXISTS `security_audit_event` (
                `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NULL DEFAULT NULL,
                `event_type` VARCHAR(100) NOT NULL,
                `context_json` TEXT NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY `security_audit_event_user_created` (`user_id`, `created_at`),
                KEY `security_audit_event_type_created` (`event_type`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->log('SecurityAuditEvent created successfully.');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `security_audit_event`');
        $this->log('SecurityAuditEvent dropped successfully.');
    }
}
