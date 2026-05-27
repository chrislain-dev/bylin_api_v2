<?php

declare(strict_types=1);

namespace Modules\Shipping\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Shipping\Http\Requests\StoreShippingMethodRequest;
use Modules\Shipping\Http\Requests\UpdateShippingMethodRequest;
use Modules\Shipping\Models\ShippingMethod;

class ShippingMethodController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $methods = ShippingMethod::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search')->toString();

                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('carrier', 'like', "%{$search}%");
                });
            })
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate((int) $request->input('per_page', 15));

        return $this->successResponse($methods);
    }

    public function store(StoreShippingMethodRequest $request): JsonResponse
    {
        $data = $this->normalizePayload($request->validated());

        $method = ShippingMethod::create($data);

        return $this->createdResponse($method, 'Shipping method created');
    }

    public function show(string $id): JsonResponse
    {
        $method = ShippingMethod::withCount('shipments')->findOrFail($id);

        return $this->successResponse($method);
    }

    public function update(string $id, UpdateShippingMethodRequest $request): JsonResponse
    {
        $method = ShippingMethod::findOrFail($id);
        $data = $this->normalizePayload($request->validated(), $method);

        $method->update($data);

        return $this->successResponse($method->fresh(), 'Shipping method updated');
    }

    public function destroy(string $id): JsonResponse
    {
        $method = ShippingMethod::withCount('shipments')->findOrFail($id);

        if ($method->shipments_count > 0) {
            return $this->errorResponse('Impossible de supprimer une méthode de livraison déjà utilisée.', 422);
        }

        $method->delete();

        return $this->successResponse(null, 'Shipping method deleted');
    }

    private function normalizePayload(array $data, ?ShippingMethod $method = null): array
    {
        $baseCost = (int) round((float) ($data['base_cost'] ?? $method?->base_cost ?? 0));

        $rateCalculation = $data['rate_calculation'] ?? $method?->rate_calculation ?? [];

        foreach (['per_kg' => 'cost_per_kg', 'per_km' => 'cost_per_km', 'free_shipping_threshold' => 'free_shipping_threshold'] as $target => $source) {
            if (array_key_exists($source, $data)) {
                $rateCalculation[$target] = $data[$source] !== null ? (float) $data[$source] : null;
            }
        }

        $rateCalculation = array_filter($rateCalculation, fn ($value) => $value !== null);

        return [
            'name' => $data['name'] ?? $method?->name,
            'code' => $data['code'] ?? $method?->code ?? Str::slug((string) $data['name']),
            'description' => $data['description'] ?? $method?->description,
            'carrier' => $data['carrier'] ?? $method?->carrier,
            'rate_calculation' => $rateCalculation ?: null,
            'base_cost' => $baseCost,
            'estimated_delivery_days' => $data['estimated_delivery_days']
                ?? $data['estimated_days_max']
                ?? $data['max_delivery_days']
                ?? $method?->estimated_delivery_days,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : ($method?->is_active ?? true),
            'sort_order' => $data['sort_order'] ?? $method?->sort_order ?? 0,
        ];
    }
}
