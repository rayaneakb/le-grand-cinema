<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LogoutEvent;

class SessionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['expireInactiveSession', 16],
            LogoutEvent::class => 'logoutResponse',
        ];
    }

    public function expireInactiveSession(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getRequest()->hasPreviousSession()) {
            return;
        }

        $session = $event->getRequest()->getSession();
        $lastActivity = $session->get('last_activity');

        // Vérifier avant que le pare-feu recharge le compte depuis la session.
        if (is_int($lastActivity) && time() - $lastActivity >= 600) {
            $session->invalidate();
        }
    }

    public function logoutResponse(LogoutEvent $event): void
    {
        $response = new JsonResponse(['status' => 'logged_out']);
        $response->headers->set('Cache-Control', 'no-store');
        $event->setResponse($response);
    }
}
