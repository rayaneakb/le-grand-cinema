<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remplacement des anciens liens d’activation après un renvoi';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD verification_version VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP verification_version');
    }
}
