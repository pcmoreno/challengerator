<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create storage_resource table for per-challenge OAuth credentials';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE storage_resource (
            id           INT AUTO_INCREMENT NOT NULL,
            challenge_id INT NOT NULL,
            type         VARCHAR(50) NOT NULL,
            credentials  JSON NULL,
            label        VARCHAR(100) NULL,
            UNIQUE INDEX uq_challenge_type (challenge_id, type),
            INDEX IDX_challenge (challenge_id),
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->addSql('ALTER TABLE storage_resource
            ADD CONSTRAINT FK_storage_resource_challenge
            FOREIGN KEY (challenge_id) REFERENCES challenge (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE storage_resource DROP FOREIGN KEY FK_storage_resource_challenge');
        $this->addSql('DROP TABLE storage_resource');
    }
}
