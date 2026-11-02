<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

class SessionHandlerFactory
{
    public static function create(Connection $connection): PdoSessionHandler
    {
        // Conserver les paramètres SSL de Doctrine avec une connexion distincte.
        $sessionConnection = DriverManager::getConnection($connection->getParams());
        $pdo = $sessionConnection->getNativeConnection();

        if (!$pdo instanceof \PDO) {
            throw new \LogicException('Les sessions nécessitent le pilote PDO PostgreSQL.');
        }

        return new PdoSessionHandler($pdo, [
            'db_table' => 'sessions',
            'lock_mode' => PdoSessionHandler::LOCK_ADVISORY,
        ]);
    }
}
