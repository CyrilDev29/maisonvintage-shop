<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20250924080143 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique index on categorie.nom (drop existing same-named index if any)';
    }

    public function up(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $this->addSql("SET @idx := (SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categorie' AND INDEX_NAME = 'UNIQ_497DD6346C6E55B5' LIMIT 1)");
        $this->addSql('SET @q := IF(@idx IS NOT NULL, "DROP INDEX UNIQ_497DD6346C6E55B5 ON categorie", "SELECT 1")');
        $this->addSql('PREPARE stmt FROM @q');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');

        $this->addSql('CREATE UNIQUE INDEX UNIQ_497DD6346C6E55B5 ON categorie (nom)');
    }

    public function down(\Doctrine\DBAL\Schema\Schema $schema): void
    {
        $this->addSql("SET @has := (SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categorie' AND INDEX_NAME = 'UNIQ_497DD6346C6E55B5' LIMIT 1)");
        $this->addSql('SET @q := IF(@has IS NOT NULL, "DROP INDEX UNIQ_497DD6346C6E55B5 ON categorie", "SELECT 1")');
        $this->addSql('PREPARE stmt FROM @q');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');
    }
}
