<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_verification: add pending_username for self-registration flow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_verification ADD pending_username VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_verification DROP COLUMN pending_username');
    }
}
