<?php

namespace App\Controller;

use App\Service\StripeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class SubscriptionController extends AbstractController
{
    private $stripeService;

    public function __construct(StripeService $stripeService)
    {
        $this->stripeService = $stripeService;
    }

    #[Route('/subscription', name: 'app_subscription')]
    public function index(): Response
    {
        return $this->render('subscription/index.html.twig', [
            'subscription_price' => 26.99,
            'features' => [
                'Accès illimité à Web Forge AI',
                'Génération de sites web personnalisés',
                'Support prioritaire',
                'Mises à jour en temps réel',
                'Export de code source',
                'Hébergement des projets'
            ]
        ]);
    }

    #[Route('/subscription/create', name: 'app_subscription_create', methods: ['POST'])]
    public function createSubscription(): JsonResponse
    {
        try {
            $user = $this->getUser();
            if (!$user) {
                throw new \Exception('Utilisateur non authentifié');
            }

            $subscription = $this->stripeService->createSubscription($user->getStripeCustomerId());

            return new JsonResponse([
                'success' => true,
                'subscription' => $subscription->id
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }
}