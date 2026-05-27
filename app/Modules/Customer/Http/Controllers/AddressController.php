<?php

declare(strict_types=1);

namespace Modules\Customer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Customer\Http\Requests\StoreAddressRequest;
use Modules\Customer\Http\Requests\UpdateAddressRequest;
use Modules\Customer\Services\CustomerService;

class AddressController extends ApiController
{
    public function __construct(
        private CustomerService $customerService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $addresses = $request->user()
            ->addresses()
            ->orderByDesc('is_default')
            ->latest()
            ->get();

        return $this->successResponse($addresses);
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $address = $this->customerService->addAddress(
            $request->user()->id,
            $request->validated()
        );

        return $this->createdResponse($address, 'Address created');
    }

    public function show(string $id, Request $request): JsonResponse
    {
        $address = $request->user()->addresses()->findOrFail($id);

        return $this->successResponse($address);
    }

    public function update(string $id, UpdateAddressRequest $request): JsonResponse
    {
        $request->user()->addresses()->findOrFail($id);

        $address = $this->customerService->updateAddress($id, $request->validated());

        return $this->successResponse($address, 'Address updated');
    }

    public function destroy(string $id, Request $request): JsonResponse
    {
        $request->user()->addresses()->findOrFail($id);

        $this->customerService->deleteAddress($id);

        return $this->successResponse(null, 'Address deleted');
    }

    public function setDefault(string $id, Request $request): JsonResponse
    {
        $address = $request->user()->addresses()->findOrFail($id);

        $request->user()->addresses()
            ->where('type', $address->type)
            ->where('id', '!=', $address->id)
            ->update(['is_default' => false]);

        $address->update(['is_default' => true]);

        return $this->successResponse($address->fresh(), 'Default address updated');
    }
}
