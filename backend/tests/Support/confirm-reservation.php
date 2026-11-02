<?php

use App\Exception\ReservationProblem;
use App\Service\ReservationService;
use Doctrine\DBAL\Connection;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '1';
$_SERVER['MAILER_FROM'] = $_ENV['MAILER_FROM'] = 'cinema@example.com';
$_SERVER['DEFAULT_URI'] = $_ENV['DEFAULT_URI'] = 'http://localhost';
require dirname(__DIR__) . '/bootstrap.php';

$kernel = new App\Kernel('test', true);
$kernel->boot();
$connection = $kernel->getContainer()->get('test.service_container')->get(Connection::class);
if (!str_ends_with($connection->getDatabase(), '_test')) {
    throw new RuntimeException('Une base de test dédiée est obligatoire.');
}
$data = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
try {
    $result = (new ReservationService($connection))->confirm($data, $argv[2], null);
    echo json_encode(['status' => 'confirmed', ...$result], JSON_THROW_ON_ERROR);
} catch (ReservationProblem $problem) {
    echo json_encode(['status' => 'refused', 'code' => $problem->getCode()], JSON_THROW_ON_ERROR);
}
