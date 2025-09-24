<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20250924065709 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create categorie table (if missing), normalize/backfill article.categorie_id, drop/re-add FK safely, enforce NOT NULL.';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('categorie')) {
            $this->addSql('CREATE TABLE categorie (
                id INT AUTO_INCREMENT NOT NULL,
                nom VARCHAR(255) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        $article = $schema->getTable('article');
        if ($article->hasColumn('categorie_id')) {
            $this->addSql('SET @fk := (SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = "article" AND CONSTRAINT_NAME = "FK_23A0E66BCF5E72D")');
            $this->addSql('SET @q := IF(@fk IS NOT NULL, "ALTER TABLE article DROP FOREIGN KEY FK_23A0E66BCF5E72D", "SELECT 1")');
            $this->addSql('PREPARE stmt FROM @q');
            $this->addSql('EXECUTE stmt');
            $this->addSql('DEALLOCATE PREPARE stmt');

            $this->addSql('ALTER TABLE article MODIFY categorie_id INT DEFAULT NULL');
        } else {
            $this->addSql('ALTER TABLE article ADD categorie_id INT DEFAULT NULL');
        }

        $this->addSql("INSERT INTO categorie (nom, description)
            SELECT * FROM (SELECT 'Sans catégorie' AS nom, 'Catégorie technique par défaut' AS description) AS tmp
            WHERE NOT EXISTS (SELECT 1 FROM categorie WHERE nom = 'Sans catégorie')");

        $this->addSql("UPDATE article a
            LEFT JOIN categorie c ON a.categorie_id = c.id
            SET a.categorie_id = NULL
            WHERE a.categorie_id IS NOT NULL AND c.id IS NULL");

        $this->addSql("UPDATE article
            SET categorie_id = (SELECT id FROM categorie WHERE nom = 'Sans catégorie' LIMIT 1)
            WHERE categorie_id IS NULL");

        $this->addSql('SET @has_idx := (SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "article" AND INDEX_NAME = "IDX_23A0E66BCF5E72D")');
        $this->addSql('SET @q := IF(@has_idx = 0, "CREATE INDEX IDX_23A0E66BCF5E72D ON article (categorie_id)", "SELECT 1")');
        $this->addSql('PREPARE stmt FROM @q');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');

        $this->addSql('ALTER TABLE article MODIFY categorie_id INT NOT NULL');

        $this->addSql('SET @has_fk := (SELECT COUNT(1) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = "FK_23A0E66BCF5E72D")');
        $this->addSql('SET @q := IF(@has_fk = 0, "ALTER TABLE article ADD CONSTRAINT FK_23A0E66BCF5E72D FOREIGN KEY (categorie_id) REFERENCES categorie (id)", "SELECT 1")');
        $this->addSql('PREPARE stmt FROM @q');
        $this->addSql('EXECUTE stmt');
        $this->addSql('DEALLOCATE PREPARE stmt');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article DROP FOREIGN KEY FK_23A0E66BCF5E72D');
        $this->addSql('DROP INDEX IDX_23A0E66BCF5E72D ON article');
        $this->addSql('ALTER TABLE article DROP categorie_id');
        $this->addSql('DROP TABLE categorie');
    }
}
