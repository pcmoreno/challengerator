<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260508194705 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE car (id VARCHAR(36) NOT NULL, challenge_id INT NOT NULL, name VARCHAR(100) NOT NULL, image_url_a VARCHAR(255) NOT NULL, image_url_b VARCHAR(255) NOT NULL, rating INT DEFAULT 1500 NOT NULL, added_on DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_on DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_773DE69D98A21AC6 (challenge_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE challenge (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(100) NOT NULL, admin_password VARCHAR(255) NOT NULL, is_active TINYINT(1) DEFAULT 0 NOT NULL, allow_self_registration TINYINT(1) DEFAULT 0 NOT NULL, self_registration_code VARCHAR(255) DEFAULT NULL, UNIQUE INDEX UNIQ_D70989515E237E06 (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE discord_profile (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, discord_id VARCHAR(255) NOT NULL, discord_username VARCHAR(255) NOT NULL, email VARCHAR(254) DEFAULT NULL, avatar_url VARCHAR(255) DEFAULT NULL, UNIQUE INDEX UNIQ_C5CA8E5443349DE (discord_id), UNIQUE INDEX UNIQ_C5CA8E54A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE email_verification (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, token VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', type VARCHAR(20) NOT NULL, challenge_id VARCHAR(255) DEFAULT NULL, UNIQUE INDEX UNIQ_FE223585F37A13B (token), INDEX IDX_FE22358A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE invite_code (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(64) NOT NULL, used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_6F21F11277153098 (code), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(180) NOT NULL, password VARCHAR(255) DEFAULT NULL, email VARCHAR(254) DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, verified_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_8D93D649F85E0677 (username), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE challenge_user (user_id INT NOT NULL, challenge_id INT NOT NULL, INDEX IDX_843CD1CFA76ED395 (user_id), INDEX IDX_843CD1CF98A21AC6 (challenge_id), PRIMARY KEY(user_id, challenge_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE voter_car_queue (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, challenge_id INT NOT NULL, car_id VARCHAR(36) NOT NULL, status VARCHAR(10) DEFAULT \'pending\' NOT NULL, INDEX IDX_4B8EE381A76ED395 (user_id), INDEX IDX_4B8EE38198A21AC6 (challenge_id), INDEX IDX_4B8EE381C3C6F69F (car_id), UNIQUE INDEX uq_voter_challenge_car (user_id, challenge_id, car_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE car ADD CONSTRAINT FK_773DE69D98A21AC6 FOREIGN KEY (challenge_id) REFERENCES challenge (id)');
        $this->addSql('ALTER TABLE discord_profile ADD CONSTRAINT FK_C5CA8E54A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE email_verification ADD CONSTRAINT FK_FE22358A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE challenge_user ADD CONSTRAINT FK_843CD1CFA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE challenge_user ADD CONSTRAINT FK_843CD1CF98A21AC6 FOREIGN KEY (challenge_id) REFERENCES challenge (id)');
        $this->addSql('ALTER TABLE voter_car_queue ADD CONSTRAINT FK_4B8EE381A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE voter_car_queue ADD CONSTRAINT FK_4B8EE38198A21AC6 FOREIGN KEY (challenge_id) REFERENCES challenge (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE voter_car_queue ADD CONSTRAINT FK_4B8EE381C3C6F69F FOREIGN KEY (car_id) REFERENCES car (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE car DROP FOREIGN KEY FK_773DE69D98A21AC6');
        $this->addSql('ALTER TABLE discord_profile DROP FOREIGN KEY FK_C5CA8E54A76ED395');
        $this->addSql('ALTER TABLE email_verification DROP FOREIGN KEY FK_FE22358A76ED395');
        $this->addSql('ALTER TABLE challenge_user DROP FOREIGN KEY FK_843CD1CFA76ED395');
        $this->addSql('ALTER TABLE challenge_user DROP FOREIGN KEY FK_843CD1CF98A21AC6');
        $this->addSql('ALTER TABLE voter_car_queue DROP FOREIGN KEY FK_4B8EE381A76ED395');
        $this->addSql('ALTER TABLE voter_car_queue DROP FOREIGN KEY FK_4B8EE38198A21AC6');
        $this->addSql('ALTER TABLE voter_car_queue DROP FOREIGN KEY FK_4B8EE381C3C6F69F');
        $this->addSql('DROP TABLE car');
        $this->addSql('DROP TABLE challenge');
        $this->addSql('DROP TABLE discord_profile');
        $this->addSql('DROP TABLE email_verification');
        $this->addSql('DROP TABLE invite_code');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE challenge_user');
        $this->addSql('DROP TABLE voter_car_queue');
    }
}
