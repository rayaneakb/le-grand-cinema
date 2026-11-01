<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261031233829 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout des clés de confirmation pour éviter les réservations en double';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reservation ADD confirmation_key VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation ADD request_fingerprint VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_42C84955704B0348 ON reservation (confirmation_key)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_42C84955704B0348');
        $this->addSql('ALTER TABLE reservation DROP confirmation_key');
        $this->addSql('ALTER TABLE reservation DROP request_fingerprint');
    }
}
