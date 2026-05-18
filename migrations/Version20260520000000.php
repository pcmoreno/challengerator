<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_verification: add email column, make user_id nullable (defer ghost-user creation to accept time)';
    }

    public function up(Schema $schema): void
    {
        // Add email column, defaulting to empty string for the backfill step
        $this->addSql("ALTER TABLE email_verification ADD email VARCHAR(254) NOT NULL DEFAULT ''");

        // Backfill from the linked user row
        $this->addSql('UPDATE email_verification ev JOIN `user` u ON u.id = ev.user_id SET ev.email = COALESCE(u.email, \'\')');

        // Remove the DEFAULT so future rows must supply the value explicitly
        $this->addSql('ALTER TABLE email_verification ALTER COLUMN email DROP DEFAULT');

        // Drop the NOT NULL constraint + FK on user_id, then re-add as nullable
        $this->addSql('ALTER TABLE email_verification DROP FOREIGN KEY FK_FE22358A76ED395');
        $this->addSql('ALTER TABLE email_verification MODIFY user_id INT NULL');
        $this->addSql('ALTER TABLE email_verification ADD CONSTRAINT FK_FE22358A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_verification DROP FOREIGN KEY FK_FE22358A76ED395');
        $this->addSql('ALTER TABLE email_verification MODIFY user_id INT NOT NULL');
        $this->addSql('ALTER TABLE email_verification ADD CONSTRAINT FK_FE22358A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE email_verification DROP COLUMN email');
    }
}
