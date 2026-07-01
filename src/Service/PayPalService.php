<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class PayPalService
{
    private const SANDBOX_BASE = 'https://api-m.sandbox.paypal.com';
    private const LIVE_BASE    = 'https://api-m.paypal.com';

    private string $base;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $clientId,
        private string $secret,
        string $environment = 'sandbox',
    ) {
        $this->base = $environment === 'live' ? self::LIVE_BASE : self::SANDBOX_BASE;
    }

    private function getAccessToken(): string
    {
        $response = $this->httpClient->request('POST', $this->base . '/v1/oauth2/token', [
            'auth_basic' => [$this->clientId, $this->secret],
            'body'       => 'grant_type=client_credentials',
            'headers'    => ['Content-Type' => 'application/x-www-form-urlencoded'],
        ]);

        return $response->toArray()['access_token'];
    }

    public function createOrder(float $amount, string $currency = 'USD'): array
    {
        $token    = $this->getAccessToken();
        $response = $this->httpClient->request('POST', $this->base . '/v2/checkout/orders', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'intent'         => 'CAPTURE',
                'purchase_units' => [[
                    'amount' => [
                        'currency_code' => strtoupper($currency),
                        'value'         => number_format($amount, 2, '.', ''),
                    ],
                ]],
                'payment_source' => [
                    'paypal' => [
                        'experience_context' => [
                            'user_action' => 'PAY_NOW',
                        ],
                    ],
                ],
            ],
        ]);

        return $response->toArray();
    }

    public function captureOrder(string $paypalOrderId): array
    {
        $token    = $this->getAccessToken();
        $response = $this->httpClient->request(
            'POST',
            $this->base . '/v2/checkout/orders/' . $paypalOrderId . '/capture',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ],
            ]
        );

        return $response->toArray();
    }
}
