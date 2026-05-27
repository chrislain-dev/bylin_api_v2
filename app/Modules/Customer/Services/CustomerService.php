<?php

declare(strict_types=1);

namespace Modules\Customer\Services;

use Illuminate\Support\Facades\Hash;
use Modules\Core\Exceptions\BusinessException;
use Modules\Core\Services\BaseService;
use Modules\Customer\Models\Address;
use Modules\Customer\Models\Customer;
use Modules\Customer\Repositories\CustomerRepository;

/**
 * Customer Service
 * 
 * Handles business logic for customer management
 */
class CustomerService extends BaseService
{
    public function __construct(
        private CustomerRepository $customerRepository
    ) {}

    /**
     * Register a new customer
     */
    public function register(array $data): Customer
    {
        return $this->transaction(function () use ($data) {
            $this->validateRequired($data, ['first_name', 'last_name', 'email', 'password']);

            if ($this->customerRepository->findByEmail($data['email'])) {
                throw new BusinessException('Email already exists');
            }

            $data['password'] = Hash::make($data['password']);
            $data['status'] = 'active';

            $customer = $this->customerRepository->create($data);

            $this->logInfo('Customer registered', ['customer_id' => $customer->id]);

            return $customer;
        });
    }

    /**
     * Update customer profile
     */
    public function updateProfile(string $customerId, array $data): Customer
    {
        return $this->transaction(function () use ($customerId, $data) {
            $customer = $this->customerRepository->findOrFail($customerId);

            // Check email uniqueness if being changed
            if (isset($data['email']) && $data['email'] !== $customer->email) {
                if ($this->customerRepository->findByEmail($data['email'])) {
                    throw new BusinessException('Email already exists');
                }
            }

            // Prevent password update via this method
            unset($data['password']);

            $customer->update($data);

            $this->logInfo('Customer profile updated', ['customer_id' => $customer->id]);

            return $customer->fresh();
        });
    }

    /**
     * Add address to customer
     */
    public function addAddress(string $customerId, array $data): Address
    {
        return $this->transaction(function () use ($customerId, $data) {
            $this->validateRequired($data, [
                'first_name', 'last_name', 'phone',
                'address_line_1', 'city', 'country'
            ]);

            $data['customer_id'] = $customerId;
            $data['type'] = $data['type'] ?? 'shipping';

            // If this is set as default, unset others
            if ($data['is_default'] ?? false) {
                Address::where('customer_id', $customerId)
                    ->where('type', $data['type'])
                    ->update(['is_default' => false]);
            }

            $address = Address::create($data);

            $this->logInfo('Address added', ['customer_id' => $customerId]);

            return $address;
        });
    }

    /**
     * Update address
     */
    public function updateAddress(string $addressId, array $data): Address
    {
        return $this->transaction(function () use ($addressId, $data) {
            $address = Address::findOrFail($addressId);

            $targetType = $data['type'] ?? $address->type;

            // If setting as default, unset others for the target type.
            if (isset($data['is_default']) && $data['is_default']) {
                Address::where('customer_id', $address->customer_id)
                    ->where('type', $targetType)
                    ->where('id', '!=', $addressId)
                    ->update(['is_default' => false]);
            }

            $address->update($data);

            return $address->fresh();
        });
    }

    /**
     * Delete address
     */
    public function deleteAddress(string $addressId): bool
    {
        return Address::findOrFail($addressId)->delete();
    }

    /**
     * Change customer password
     */
    public function changePassword(string $customerId, string $currentPassword, string $newPassword): bool
    {
        $customer = $this->customerRepository->findOrFail($customerId);

        if (!Hash::check($currentPassword, $customer->password)) {
            throw new BusinessException('Current password is incorrect');
        }

        return $customer->update([
            'password' => Hash::make($newPassword)
        ]);
    }
}
