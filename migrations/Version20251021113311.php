<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251021113311 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `order` ADD stripe_payment_intent_id VARCHAR(64) DEFAULT NULL, ADD stripe_session_id VARCHAR(64) DEFAULT NULL, ADD canceled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD refunded_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD stripe_refund_id VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `order` DROP stripe_payment_intent_id, DROP stripe_session_id, DROP canceled_at, DROP refunded_at, DROP stripe_refund_id');
    }
}
