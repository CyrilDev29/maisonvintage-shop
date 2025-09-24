<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250924081537 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add slug on categorie: nullable -> backfill -> unique -> not null';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categorie' AND COLUMN_NAME = 'slug')");
        $this->addSql('SET @q := IF(@col = 0, "ALTER TABLE categorie ADD slug VARCHAR(255) DEFAULT NULL", "SELECT 1")');
        $this->addSql('PREPARE stmt FROM @q'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');

        $this->addSql("UPDATE categorie SET slug = LOWER(REPLACE(nom, ' ', '-')) WHERE slug IS NULL OR slug = ''");

        $this->addSql("SET @has := (SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categorie' AND INDEX_NAME = 'UNIQ_497DD634989D9B62')");
        $this->addSql('SET @q := IF(@has > 0, "DROP INDEX UNIQ_497DD634989D9B62 ON categorie", "SELECT 1")');
        $this->addSql('PREPARE stmt FROM @q'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');

        $this->addSql('CREATE UNIQUE INDEX UNIQ_497DD634989D9B62 ON categorie (slug)');

        $this->addSql('ALTER TABLE categorie MODIFY slug VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("SET @has := (SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categorie' AND INDEX_NAME = 'UNIQ_497DD634989D9B62')");
        $this->addSql('SET @q := IF(@has > 0, "DROP INDEX UNIQ_497DD634989D9B62 ON categorie", "SELECT 1")');
        $this->addSql('PREPARE stmt FROM @q'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');

        $this->addSql("SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categorie' AND COLUMN_NAME = 'slug')");
        $this->addSql('SET @q := IF(@col = 1, "ALTER TABLE categorie DROP COLUMN slug", "SELECT 1")');
        $this->addSql('PREPARE stmt FROM @q'); $this->addSql('EXECUTE stmt'); $this->addSql('DEALLOCATE PREPARE stmt');
    }
}
