<?php

declare(strict_types=1);

namespace Modules\Shipping\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Shipping\Models\Shipment;

class ShipmentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $shipments = Shipment::query()
            ->with(['order.customer', 'shippingMethod'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('order_id'), fn ($query) => $query->where('order_id', $request->string('order_id')->toString()))
            ->when($request->filled('tracking_number'), fn ($query) => $query->where('tracking_number', 'like', '%' . $request->string('tracking_number')->toString() . '%'))
            ->latest()
            ->paginate((int) $request->input('per_page', 15));

        return $this->successResponse($shipments);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $shipment = Shipment::create($validated)->load(['order.customer', 'shippingMethod']);

        return $this->createdResponse($shipment, 'Shipment created');
    }

    public function show(string $id): JsonResponse
    {
        $shipment = Shipment::with(['order.customer', 'order.items.product', 'shippingMethod'])->findOrFail($id);

        return $this->successResponse($shipment);
    }

    public function update(string $id, Request $request): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        $validated = $request->validate($this->rules(true));

        $shipment->fill($validated);
        $shipment->save();

        if (isset($validated['status'])) {
            $shipment->updateStatus($validated['status'], $request->input('tracking_message'));
        }

        return $this->successResponse($shipment->fresh()->load(['order.customer', 'shippingMethod']), 'Shipment updated');
    }

    public function destroy(string $id): JsonResponse
    {
        $shipment = Shipment::findOrFail($id);

        if ($shipment->status === Shipment::STATUS_DELIVERED) {
            return $this->errorResponse('Impossible de supprimer une expédition déjà livrée.', 422);
        }

        $shipment->delete();

        return $this->successResponse(null, 'Shipment deleted');
    }

    private function rules(bool $update = false): array
    {
        $required = $update ? 'sometimes' : 'required';

        return [
            'order_id' => [$required, 'uuid', 'exists:orders,id'],
            'shipping_method_id' => [$required, 'uuid', 'exists:shipping_methods,id'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'carrier' => ['nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in([
                Shipment::STATUS_PENDING,
                Shipment::STATUS_SHIPPED,
                Shipment::STATUS_IN_TRANSIT,
                Shipment::STATUS_OUT_FOR_DELIVERY,
                Shipment::STATUS_DELIVERED,
                Shipment::STATUS_FAILED,
                Shipment::STATUS_RETURNED,
            ])],
            'tracking_events' => ['nullable', 'array'],
            'cost' => ['nullable', 'integer', 'min:0'],
            'shipped_date' => ['nullable', 'date'],
            'estimated_delivery_date' => ['nullable', 'date'],
            'delivered_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tracking_message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
