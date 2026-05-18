<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'email_verification: delete rows whose email was backfilled as empty string (linked user had no email)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM email_verification WHERE email = ''");
    }

    public function down(Schema $schema): void
    {
        // Deleted rows cannot be restored
    }
}
