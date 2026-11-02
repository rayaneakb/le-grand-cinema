<?php

namespace App\Controller;

use App\Service\ReservationService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin')]
#[IsGranted('ROLE_EMPLOYEE')]
class AdminController extends AbstractController
{
    #[Route('/settings', name: 'api_admin_settings', methods: ['GET'])]
    public function settings(Connection $connection): JsonResponse
    {
        return $this->respond([
            'ticketPriceCents' => (int) $connection->fetchOne('SELECT ticket_price_cents FROM cinema_settings WHERE id = 1'),
            'canChangePrice' => $this->isGranted('ROLE_DIRECTOR'),
        ]);
    }

    #[Route('/reservations', name: 'api_admin_reservations', methods: ['GET'])]
    public function reservations(Request $request, Connection $connection, ReservationService $reservations): JsonResponse
    {
        $query = trim($request->query->get('q', ''));
        $page = $request->query->get('page', '1');
        if ($query === '' || mb_strlen($query) > 180 || !preg_match('/^[1-9][0-9]{0,5}$/D', $page)) {
            return $this->respond(['message' => 'Saisissez un numéro de réservation ou une adresse mail.'], 422);
        }
        $rows = $connection->fetchAllAssociative(
            'SELECT id, status FROM reservation WHERE reference = :reference OR email = :email ORDER BY created_at DESC, id DESC LIMIT 21 OFFSET :offset',
            ['reference' => strtoupper($query), 'email' => strtolower($query), 'offset' => ((int) $page - 1) * 20],
            ['offset' => \Doctrine\DBAL\ParameterType::INTEGER]
        );
        $bookings = array_map(static fn (array $row): array => [
            ...$reservations->summary((int) $row['id']), 'status' => $row['status'],
        ], array_slice($rows, 0, 20));

        return $this->respond(['reservations' => $bookings, 'hasMore' => count($rows) > 20, 'page' => (int) $page]);
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }
}
