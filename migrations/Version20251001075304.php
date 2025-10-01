<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251001075304 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user ADD prenom VARCHAR(100) NOT NULL, ADD nom VARCHAR(100) NOT NULL, ADD telephone VARCHAR(20) NOT NULL, ADD rue VARCHAR(255) NOT NULL, ADD code_postal VARCHAR(10) NOT NULL, ADD ville VARCHAR(100) NOT NULL, ADD pays VARCHAR(100) NOT NULL, CHANGE password password VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user DROP prenom, DROP nom, DROP telephone, DROP rue, DROP code_postal, DROP ville, DROP pays, CHANGE password password VARCHAR(255) NOT NULL');
    }
}
