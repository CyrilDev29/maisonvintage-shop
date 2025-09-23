<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250923120247 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article ADD quantity INT NOT NULL, ADD weight_kg NUMERIC(6, 2) DEFAULT NULL, ADD length_cm NUMERIC(6, 2) DEFAULT NULL, ADD width_cm NUMERIC(6, 2) DEFAULT NULL, ADD height_cm NUMERIC(6, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE article DROP quantity, DROP weight_kg, DROP length_cm, DROP width_cm, DROP height_cm');
    }
}
