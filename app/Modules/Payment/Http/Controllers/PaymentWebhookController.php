<?php

declare(strict_types=1);

namespace Modules\Payment\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Payment\Models\Payment;
use Modules\Payment\Services\PaymentService;

class PaymentWebhookController extends ApiController
{
    public function __construct(
        protected PaymentService $paymentService,
    ) {}

    /**
     * Handle FedaPay webhook.
     */
    public function fedapay(Request $request): JsonResponse
    {
        $event = $request->all();
        $eventType = (string) ($event['type'] ?? $event['name'] ?? 'unknown');
        $eventId = (string) ($event['id'] ?? 'unknown');

        Log::info('FedaPay webhook received', [
            'event_id' => $eventId,
            'event_type' => $eventType,
            'data' => $this->filterSensitiveData($event),
        ]);

        try {
            $this->paymentService->handlePaymentCallback(Payment::GATEWAY_FEDAPAY, $event);
        } catch (\Throwable $e) {
            Log::error('FedaPay webhook processing failed', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);

            // Return 200 to avoid infinite retries for non-recoverable payload errors.
            return response()->json(['status' => 'accepted_with_error']);
        }

        return response()->json(['status' => 'success']);
    }

    private function filterSensitiveData(array $data): array
    {
        $sensitiveKeys = ['card_number', 'cvv', 'password', 'secret', 'authorization', 'token'];

        array_walk_recursive($data, function (&$value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $value = '[FILTERED]';
            }
        });

        return $data;
    }
}
