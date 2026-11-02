<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class StatusController
{
    #[Route('/api/status', name: 'api_status', methods: ['GET'])]
    public function index(Connection $connection): JsonResponse
    {
        try {
            $connection->fetchOne('SELECT 1');
        } catch (Exception) {
            $response = new JsonResponse(['status' => 'unavailable'], 503);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        }

        $response = new JsonResponse(['status' => 'ok']);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
