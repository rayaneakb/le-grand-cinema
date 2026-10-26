<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class StatusController
{
    #[Route('/api/status', name: 'api_status', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
