<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029012124 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création du tarif unique du cinéma';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cinema_settings (id INT NOT NULL, ticket_price_cents INT NOT NULL, PRIMARY KEY (id), CONSTRAINT chk_cinema_settings_singleton CHECK (id = 1), CONSTRAINT chk_ticket_price_positive CHECK (ticket_price_cents > 0))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cinema_settings');
    }
}
