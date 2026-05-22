<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522100000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE challenge ADD COLUMN display_name VARCHAR(150) NOT NULL DEFAULT ''");
        $this->addSql("UPDATE challenge SET display_name = name WHERE display_name = ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE challenge DROP COLUMN display_name');
    }
}
