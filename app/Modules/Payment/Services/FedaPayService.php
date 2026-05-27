<?php

declare(strict_types=1);

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Core\Services\BaseService;
use Modules\Order\Models\Order;
use Modules\Payment\Models\Payment;

class FedaPayService extends BaseService
{
    protected string $apiKey;
    protected string $environment;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = (string) config('services.fedapay.secret_key');
        $this->environment = (string) config('services.fedapay.environment', 'sandbox');
        $this->baseUrl = rtrim((string) config('services.fedapay.base_url', 'https://api.fedapay.com/v1'), '/');
    }

    /**
     * Create FedaPay transaction.
     */
    public function createTransaction(Payment $payment, Order $order): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('La clé secrète FedaPay n’est pas configurée.');
        }

        $customer = $order->customer;
        $shippingAddress = $order->shipping_address ?? [];
        $country = strtolower((string) ($shippingAddress['country'] ?? 'BJ'));

        $payload = [
            'description' => "Commande {$order->order_number}",
            'amount' => (int) $payment->amount,
            'currency' => ['iso' => $payment->currency ?: 'XOF'],
            'callback_url' => route('api.webhooks.fedapay'),
            'custom_metadata' => [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ],
            'customer' => [
                'firstname' => $shippingAddress['first_name'] ?? $customer?->first_name ?? $customer?->firstname ?? 'Client',
                'lastname' => $shippingAddress['last_name'] ?? $customer?->last_name ?? $customer?->lastname ?? 'Bylin',
                'email' => $order->customer_email,
                'phone_number' => [
                    'number' => $order->customer_phone,
                    'country' => $country,
                ],
            ],
        ];

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->asJson()
                ->post("{$this->baseUrl}/transactions", $payload);

            if ($response->failed()) {
                throw new \RuntimeException('FedaPay Error: ' . $response->body());
            }

            $data = $response->json() ?? [];
            $transaction = $data['transaction'] ?? $data['data'] ?? $data;

            $transactionId = (string) ($transaction['id'] ?? $transaction['transaction_id'] ?? '');
            $token = (string) ($transaction['token'] ?? $transaction['payment_token'] ?? '');
            $url = (string) ($transaction['url'] ?? $transaction['payment_url'] ?? $transaction['checkout_url'] ?? '');

            if ($token === '' && isset($transaction['metadata']['token'])) {
                $token = (string) $transaction['metadata']['token'];
            }

            if ($url === '' && isset($transaction['metadata']['url'])) {
                $url = (string) $transaction['metadata']['url'];
            }

            if ($transactionId === '') {
                throw new \RuntimeException('Réponse FedaPay invalide : transaction id manquant.');
            }

            if ($url === '') {
                Log::warning('FedaPay transaction created without checkout URL', [
                    'payment_id' => $payment->id,
                    'transaction_id' => $transactionId,
                ]);
            }

            return [
                'payment_url' => $url ?: null,
                'token' => $token ?: null,
                'transaction_reference' => $transactionId,
            ];
        } catch (\Throwable $e) {
            Log::error('FedaPay transaction creation failed', [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle FedaPay webhook/callback.
     */
    public function handleCallback(array $data): Payment
    {
        $entity = $data['entity'] ?? $data['transaction'] ?? $data['data'] ?? [];
        $transactionId = (string) ($entity['id'] ?? $entity['transaction_id'] ?? $data['id'] ?? '');
        $status = (string) ($entity['status'] ?? $data['status'] ?? '');
        $customMetadata = $entity['custom_metadata'] ?? $entity['metadata'] ?? [];

        $payment = $this->resolvePayment($transactionId, $customMetadata);
        $paymentService = app(PaymentService::class);

        return match ($status) {
            'approved', 'completed', 'success' => $paymentService->markAsSuccessful($payment, $transactionId, $data),
            'declined', 'canceled', 'cancelled', 'failed' => $paymentService->markAsFailed($payment, $status, $data),
            default => $payment,
        };
    }

    private function resolvePayment(string $transactionId, array $metadata): Payment
    {
        $paymentId = $metadata['payment_id'] ?? null;
        $orderId = $metadata['order_id'] ?? null;

        if ($paymentId) {
            return Payment::findOrFail($paymentId);
        }

        if ($transactionId !== '') {
            $payment = Payment::where('transaction_id', $transactionId)->first();
            if ($payment) {
                return $payment;
            }
        }

        if ($orderId) {
            return Payment::where('order_id', $orderId)->latest()->firstOrFail();
        }

        throw new \RuntimeException('Paiement introuvable pour ce webhook FedaPay.');
    }

    /**
     * Verify transaction status manually.
     */
    public function verifyTransaction(string $transactionId): string
    {
        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->get("{$this->baseUrl}/transactions/{$transactionId}");

        if ($response->failed()) {
            throw new \RuntimeException('FedaPay verification error: ' . $response->body());
        }

        $data = $response->json() ?? [];
        $transaction = $data['transaction'] ?? $data['data'] ?? $data;

        return (string) ($transaction['status'] ?? 'unknown');
    }
}
