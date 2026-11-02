<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suivi des tentatives et de l’envoi du mail de réservation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD confirmation_email_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation ADD confirmation_email_attempted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation DROP confirmation_email_sent_at');
        $this->addSql('ALTER TABLE reservation DROP confirmation_email_attempted_at');
    }
}
