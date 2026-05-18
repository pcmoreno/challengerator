<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'user.email: deduplicate ghost invite users, add unique index';
    }

    public function up(Schema $schema): void
    {
        // Remove ghost (unverified, invite_* username) users whose email
        // already belongs to another user row (keep the non-ghost row).
        $this->addSql("
            DELETE u1 FROM `user` u1
            INNER JOIN `user` u2 ON u2.email = u1.email AND u2.id <> u1.id
            WHERE u1.verified_at IS NULL
              AND u1.username LIKE 'invite_%'
        ");

        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON `user` (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D649E7927C74 ON `user`');
    }
}
