<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260509000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add roles JSON column to user table for super-admin support';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `user` ADD roles JSON NOT NULL DEFAULT ('[]') AFTER verified_at");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP COLUMN roles');
    }
}
