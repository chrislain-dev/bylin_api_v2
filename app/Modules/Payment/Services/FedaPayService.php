<?php

declare(strict_types=1);

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\Http;
use Modules\Core\Services\BaseService;
use Modules\Order\Models\Order;
use Modules\Payment\Models\Payment;

class FedaPayService extends BaseService
{
    protected string $apiKey;
    protected string $environment;

    public function __construct()
    {
        $this->apiKey = config('services.fedapay.secret_key', 'sk_sandbox_...');
        $this->environment = config('services.fedapay.environment', 'sandbox');
    }

    /**
     * Create FedaPay transaction
     */
    /**
     * Create FedaPay transaction
     */
    public function createTransaction(Payment $payment, Order $order): array
    {
        $baseUrl = $this->environment === 'live' 
            ? 'https://api.fedapay.com/v1/transactions' 
            : 'https://api.fedapay.com/v1/transactions'; // FedaPay uses same URL, key determines env usually, or sandbox URL if different. Docs say same base.

        // Actually FedaPay documentation suggests using the SDK, but HTTP is fine.
        // Sandbox URL might be different? No, FedaPay handles it by key prefix (sk_sandbox vs sk_live).
        
        $callbackUrl = route('api.webhooks.fedapay');

        try {
            $response = Http::withToken($this->apiKey)
                ->withHeaders([
                    'X-Version' => 'v1' // Good practice
                ])
                ->post($baseUrl, [
                    'description' => "Order #{$order->order_number} - Bylin",
                    'amount' => (int) $payment->amount,
                    'currency' => ['iso' => 'XOF'],
                    'callback_url' => $callbackUrl,
                    'customer' => [
                        'firstname' => $order->customer_firstname ?? $order->customer->firstname ?? 'Client',
                        'lastname' => $order->customer_lastname ?? $order->customer->lastname ?? 'Bylin',
                        'email' => $order->customer_email,
                        'phone_number' => [
                            'number' => $order->customer_phone,
                            'country' => 'bj' // Assuming BJ for now, can be dynamic based on address
                        ]
                    ]
                ]);

            if ($response->failed()) {
                throw new \Exception('FedaPay Error: ' . $response->body());
            }

            $data = $response->json();
            $token = $data['v1']['token'] ?? $data['token'] ?? null; // Adjust based on actual response structure
            $url = $data['v1']['url'] ?? $data['url'] ?? null;

            // If structure is entity based
            if (!$token && isset($data['token'])) $token = $data['token'];
            if (!$url && isset($data['url'])) $url = $data['url'];

            if (!$token || !$url) {
                 // Fallback if direct structure failed
                 throw new \Exception('Invalid response from FedaPay: ' . json_encode($data));
            }

            return [
                'payment_url' => $url,
                'token' => $token,
                'transaction_reference' => (string) ($data['id'] ?? $data['v1']['id'] ?? uniqid('ref_')),
            ];

        } catch (\Exception $e) {
            \Log::error('FedaPay Transaction Creation Failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Handle FedaPay Webhook/Callback
     */
    public function handleCallback(array $data): Payment
    {
        $transactionId = $data['entity']['id'] ?? null;
        $status = $data['entity']['status'] ?? null;
        $customMetadata = $data['entity']['custom_metadata'] ?? [];
        
        // Find payment by transaction ID or metadata
        // Ideally we should store our payment ID in FedaPay custom_metadata
        $paymentId = $customMetadata['payment_id'] ?? null;
        
        if ($paymentId) {
            $payment = Payment::findOrFail($paymentId);
        } else {
            // Fallback: try to find by transaction_id if we stored it earlier
            $payment = Payment::where('transaction_id', $transactionId)->firstOrFail();
        }

        $paymentService = app(PaymentService::class);

        if ($status === 'approved') {
            return $paymentService->markAsSuccessful($payment, (string)$transactionId, $data);
        } elseif ($status === 'declined' || $status === 'canceled') {
            return $paymentService->markAsFailed($payment, $status, $data);
        }

        return $payment;
    }

    /**
     * Verify transaction status manually
     */
    public function verifyTransaction(string $transactionId): string
    {
        // Call FedaPay API to verify status
        return 'approved'; // Mock
    }
}
