<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Origine des annulations de réservation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation ADD cancellation_origin VARCHAR(20) DEFAULT NULL');
        // Une notification de séance annulée identifie les annulations déjà faites par le cinéma.
        $this->addSql(<<<'SQL'
            UPDATE reservation r SET cancellation_origin = 'cinema'
            WHERE r.status = 'cancelled'
              AND EXISTS (
                  SELECT 1 FROM screening_notice n
                  WHERE n.reservation_id = r.id
                    AND n.subject = 'Annulation de votre séance au Grand Cinéma'
              )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation DROP cancellation_origin');
    }
}
