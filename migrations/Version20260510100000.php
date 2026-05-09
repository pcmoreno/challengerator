<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Move ManyToMany ownership to DbChallenge: reorder challenge_user PK and add ON DELETE CASCADE to challenge_id FK';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE challenge_user DROP FOREIGN KEY FK_843CD1CF98A21AC6');
        $this->addSql('ALTER TABLE challenge_user DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE challenge_user ADD CONSTRAINT FK_843CD1CF98A21AC6 FOREIGN KEY (challenge_id) REFERENCES challenge (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE challenge_user ADD PRIMARY KEY (challenge_id, user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE challenge_user DROP FOREIGN KEY FK_843CD1CF98A21AC6');
        $this->addSql('ALTER TABLE challenge_user DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE challenge_user ADD CONSTRAINT FK_843CD1CF98A21AC6 FOREIGN KEY (challenge_id) REFERENCES challenge (id)');
        $this->addSql('ALTER TABLE challenge_user ADD PRIMARY KEY (user_id, challenge_id)');
    }
}
