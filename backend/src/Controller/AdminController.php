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

    #[Route('/settings/tariff', name: 'api_admin_tariff', methods: ['POST'])]
    #[IsGranted('ROLE_DIRECTOR')]
    public function tariff(Request $request, Connection $connection, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        if (!$csrf->isTokenValid(new CsrfToken('api', $request->headers->get('X-CSRF-Token', '')))) {
            return $this->respond(['message' => 'Votre formulaire a expiré. Réessayez.'], 403);
        }
        if ($request->getContentTypeFormat() !== 'json') {
            return $this->respond(['message' => 'Le format JSON est attendu.'], 415);
        }
        try {
            $data = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->respond(['message' => 'Le contenu JSON est invalide.'], 400);
        }
        if (!$data instanceof \stdClass || array_keys((array) $data) !== ['price'] || !is_string($data->price)) {
            return $this->respond(['message' => 'Saisissez un montant supérieur à 0 avec au maximum deux décimales.'], 422);
        }
        $price = trim($data->price);
        if (!preg_match('/^([0-9]{1,8})(?:[.,]([0-9]{1,2}))?$/D', $price, $parts)) {
            return $this->respond(['message' => 'Saisissez un montant supérieur à 0 avec au maximum deux décimales.'], 422);
        }
        // Les euros saisis sont convertis en centimes sans calcul à virgule flottante.
        $cents = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($cents <= 0 || $cents > 2147483647) {
            return $this->respond(['message' => 'Ce montant est nul ou trop élevé.'], 422);
        }
        $connection->executeStatement(
            'INSERT INTO cinema_settings (id, ticket_price_cents) VALUES (1, :price) ON CONFLICT (id) DO UPDATE SET ticket_price_cents = EXCLUDED.ticket_price_cents',
            ['price' => $cents]
        );

        return $this->respond(['ticketPriceCents' => $cents, 'message' => 'Le tarif est modifié pour les nouvelles réservations.']);
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
