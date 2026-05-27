<?php

declare(strict_types=1);

namespace Modules\Payment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Core\Services\BaseService;
use Modules\Order\Models\Order;
use Modules\Order\Services\OrderService;
use Modules\Payment\Models\Payment;

class PaymentService extends BaseService
{
    public function __construct(
        protected OrderService $orderService,
        protected FedaPayService $fedaPayService,
    ) {}

    /**
     * Initialize payment for an order without creating duplicate active payments.
     */
    public function initializePayment(Order $order, string $gateway): array
    {
        if ($gateway !== Payment::GATEWAY_FEDAPAY) {
            throw new \InvalidArgumentException("Unsupported payment gateway: {$gateway}");
        }

        return DB::transaction(function () use ($order, $gateway) {
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($lockedOrder->payment_status === Order::PAYMENT_STATUS_PAID) {
                throw new \DomainException('Cette commande est déjà payée.');
            }

            $payment = Payment::query()
                ->where('order_id', $lockedOrder->id)
                ->where('gateway', $gateway)
                ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_PROCESSING, Payment::STATUS_COMPLETED])
                ->latest()
                ->first();

            if (! $payment) {
                $payment = Payment::create([
                    'order_id' => $lockedOrder->id,
                    'gateway' => $gateway,
                    'status' => Payment::STATUS_PENDING,
                    'amount' => $lockedOrder->total,
                    'currency' => 'XOF',
                    'payment_method' => $lockedOrder->payment_method,
                ]);
            }

            if ((int) $payment->amount !== (int) $lockedOrder->total) {
                throw new \DomainException('Le montant du paiement ne correspond pas au total de la commande.');
            }

            if ($payment->status === Payment::STATUS_COMPLETED) {
                throw new \DomainException('Ce paiement est déjà complété.');
            }

            $gatewayData = $this->fedaPayService->createTransaction($payment, $lockedOrder);

            $payment->update([
                'transaction_id' => $gatewayData['transaction_reference'],
                'status' => Payment::STATUS_PROCESSING,
                'metadata' => array_merge($payment->metadata ?? [], [
                    'payment_url' => $gatewayData['payment_url'],
                    'payment_token' => $gatewayData['token'],
                    'transaction_reference' => $gatewayData['transaction_reference'],
                ]),
            ]);

            return array_merge($gatewayData, [
                'payment_id' => $payment->id,
            ]);
        });
    }

    /**
     * Handle payment webhook/callback.
     */
    public function handlePaymentCallback(string $gateway, array $data): Payment
    {
        if ($gateway === Payment::GATEWAY_FEDAPAY) {
            return $this->fedaPayService->handleCallback($data);
        }

        throw new \InvalidArgumentException("Unsupported payment gateway: {$gateway}");
    }

    /**
     * Mark payment as successful idempotently.
     */
    public function markAsSuccessful(Payment $payment, string $transactionId, array $gatewayResponse): Payment
    {
        return DB::transaction(function () use ($payment, $transactionId, $gatewayResponse) {
            $lockedPayment = Payment::query()
                ->with('order')
                ->lockForUpdate()
                ->findOrFail($payment->id);

            if ($lockedPayment->status === Payment::STATUS_COMPLETED) {
                return $lockedPayment;
            }

            if ((int) $lockedPayment->amount !== (int) $lockedPayment->order->total) {
                throw new \DomainException('Montant paiement incohérent avec la commande.');
            }

            $lockedPayment->update([
                'status' => Payment::STATUS_COMPLETED,
                'transaction_id' => $transactionId,
                'paid_at' => now(),
                'gateway_response' => $gatewayResponse,
            ]);

            $this->orderService->updatePaymentStatus($lockedPayment->order, Order::PAYMENT_STATUS_PAID);

            if ($lockedPayment->order->status === Order::STATUS_PENDING) {
                $this->orderService->updateStatus($lockedPayment->order, Order::STATUS_PROCESSING, 'Paiement reçu');
            }

            return $lockedPayment->fresh(['order']);
        });
    }

    /**
     * Mark payment as failed idempotently.
     */
    public function markAsFailed(Payment $payment, string $reason, array $gatewayResponse): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $gatewayResponse) {
            $lockedPayment = Payment::query()
                ->with('order')
                ->lockForUpdate()
                ->findOrFail($payment->id);

            if ($lockedPayment->status === Payment::STATUS_COMPLETED) {
                return $lockedPayment;
            }

            $lockedPayment->update([
                'status' => Payment::STATUS_FAILED,
                'gateway_response' => $gatewayResponse,
                'metadata' => array_merge($lockedPayment->metadata ?? [], ['failure_reason' => $reason]),
            ]);

            $this->orderService->updatePaymentStatus($lockedPayment->order, Order::PAYMENT_STATUS_FAILED);

            return $lockedPayment->fresh(['order']);
        });
    }
}
