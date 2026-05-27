<?php

declare(strict_types=1);

namespace Modules\Payment\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Order\Models\Order;
use Modules\Payment\Http\Requests\RefundPaymentRequest;
use Modules\Payment\Models\Payment;
use Modules\Payment\Models\Refund;

class PaymentController extends ApiController
{
    /**
     * List payments.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Payment::with(['order.customer']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('gateway')) {
            $query->where('gateway', $request->string('gateway'));
        }

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->string('order_id'));
        }

        $payments = $query->latest()->paginate((int) $request->input('per_page', 20));

        return $this->successResponse($payments);
    }

    /**
     * Show payment details.
     */
    public function show(string $id): JsonResponse
    {
        $payment = Payment::with(['order.customer', 'refunds.creator'])->findOrFail($id);

        return $this->successResponse($payment);
    }

    /**
     * Record a refund and update payment/order statuses safely.
     */
    public function refund(string $id, RefundPaymentRequest $request): JsonResponse
    {
        $refund = DB::transaction(function () use ($id, $request) {
            $payment = Payment::query()
                ->with(['order', 'refunds'])
                ->lockForUpdate()
                ->findOrFail($id);

            if (! $payment->canBeRefunded()) {
                throw new \DomainException('Ce paiement ne peut pas être remboursé.');
            }

            $amount = (int) $request->validated('amount');

            if ($amount > $payment->getRemainingRefundableAmount()) {
                throw new \DomainException('Le montant dépasse le solde remboursable.');
            }

            $refund = Refund::create([
                'payment_id' => $payment->id,
                'amount' => $amount,
                'reason' => $request->validated('reason'),
                'status' => Refund::STATUS_COMPLETED,
                'created_by' => $request->user()?->id,
            ]);

            $remaining = $payment->getRemainingRefundableAmount();

            if ($remaining <= 0) {
                $payment->update(['status' => Payment::STATUS_REFUNDED]);
                $payment->order?->update([
                    'payment_status' => Order::PAYMENT_STATUS_REFUNDED,
                    'status' => Order::STATUS_REFUNDED,
                ]);
            }

            return $refund->fresh(['payment.order']);
        });

        return $this->successResponse($refund, 'Remboursement enregistré avec succès.');
    }
}
