<?php

namespace App\Service;

use Stripe\Stripe;
use Stripe\Subscription;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class StripeService
{
    private $params;

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
        Stripe::setApiKey($this->params->get('stripe_secret_key'));
    }

    public function createSubscription(string $customerId): Subscription
    {
        return Subscription::create([
            'customer' => $customerId,
            'items' => [[
                'price' => 'price_1RMYtHBtUGEFOuHvNzY998ue',
                'quantity' => 1
            ]],
            'automatic_tax' => ['enabled' => true],
            'currency' => 'eur',
            'off_session' => true,
            'payment_behavior' => 'error_if_incomplete',
            'proration_behavior' => 'none'
        ]);
    }
}