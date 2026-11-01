#!/usr/bin/env php
<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');
umask(0077);

$params = (new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))
    ->parse($_SERVER['DATABASE_URL']);
if (!in_array($params['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('Cette vérification est réservée à une base locale.');
}
$source = DriverManager::getConnection($params);
$name = 'cinema_restore_' . bin2hex(random_bytes(6));
$directory = dirname(__DIR__) . '/var/backup-check';
if (!is_dir($directory)) mkdir($directory, 0700, true);
$dump = $directory . '/' . $name . '.dump';
$pgDirectory = rtrim(getenv('PG_BIN_DIRECTORY') ?: '', '/');
$env = [
    'PGHOST' => $params['host'], 'PGPORT' => (string) ($params['port'] ?? 5432),
    'PGUSER' => $params['user'], 'PGPASSWORD' => $params['password'] ?? '',
    'PGDATABASE' => $params['dbname'], 'PGCONNECT_TIMEOUT' => '10',
];
$run = static function (string $tool, array $arguments) use ($pgDirectory, $env): void {
    $executable = $pgDirectory === '' ? $tool : $pgDirectory . '/' . $tool;
    $process = new Process([$executable, ...$arguments], env: $env);
    $process->setTimeout(120);
    $process->run();
    if (!$process->isSuccessful()) {
        throw new RuntimeException($tool . ' a échoué. Vérifiez la version des outils PostgreSQL et les accès locaux.');
    }
};
$adminParams = $params;
$adminParams['dbname'] = 'postgres';
if (getenv('PG_ADMIN_USER')) {
    $adminParams['user'] = getenv('PG_ADMIN_USER');
    unset($adminParams['password']);
}
$admin = DriverManager::getConnection($adminParams);
$target = null;
$created = false;
$success = false;
try {
    // Le même instantané sert à l’export et à la comparaison, même si la base évolue ensuite.
    $source->beginTransaction();
    $source->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
    $snapshot = $source->fetchOne('SELECT pg_export_snapshot()');
    $run('pg_dump', ['--format=custom', '--no-owner', '--no-privileges', '--snapshot=' . $snapshot, '--file=' . $dump]);
    $admin->executeStatement('CREATE DATABASE ' . $admin->quoteIdentifier($name) . ' OWNER ' . $admin->quoteIdentifier($params['user']));
    $created = true;
    $run('pg_restore', ['--no-owner', '--no-privileges', '--exit-on-error', '--dbname=' . $name, $dump]);
    $targetParams = $params;
    $targetParams['dbname'] = $name;
    $target = DriverManager::getConnection($targetParams);
    $tables = $source->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
    $restoredTables = $target->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
    if ($tables !== $restoredTables) throw new RuntimeException('Les tables restaurées ne correspondent pas.');
    $counts = [];
    foreach ($tables as $table) {
        $sql = 'SELECT row_to_json(t)::text AS row FROM public.' . $source->quoteIdentifier($table) . ' t ORDER BY row';
        $expected = $source->fetchFirstColumn($sql);
        $actual = $target->fetchFirstColumn($sql);
        if ($expected !== $actual) throw new RuntimeException('Données différentes dans la table ' . $table . '.');
        $counts[$table] = count($actual);
    }
    $sequencesSql = "SELECT sequencename, last_value FROM pg_sequences WHERE schemaname = 'public' ORDER BY sequencename";
    if ($source->fetchAllAssociative($sequencesSql) !== $target->fetchAllAssociative($sequencesSql)) {
        throw new RuntimeException('Les séquences restaurées ne correspondent pas.');
    }
    $source->commit();
    $success = true;
    $report = [
        'checkedAt' => (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format(DateTimeInterface::ATOM),
        'source' => $params['dbname'], 'restoredDatabase' => $name,
        'tables' => $counts, 'identicalRows' => true, 'identicalSequences' => true,
        'dumpSha256' => hash_file('sha256', $dump),
        'keptForApplicationCheck' => in_array('--keep', $argv, true),
    ];
    file_put_contents($directory . '/latest.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} finally {
    if ($source->isTransactionActive()) $source->rollBack();
    $target?->close();
    if ($created && (!$success || !in_array('--keep', $argv, true))) {
        $admin->executeStatement('DROP DATABASE ' . $admin->quoteIdentifier($name));
    }
}
