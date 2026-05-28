<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Inventory\Http\Requests\InventoryNotificationSettingsRequest;

class InventoryNotificationSettingsController extends ApiController
{
    private const CACHE_KEY = 'inventory.notification_settings';

    public function show(): JsonResponse
    {
        return $this->successResponse(
            $this->settings(),
            "Paramètres d'alertes de stock récupérés."
        );
    }

    public function update(InventoryNotificationSettingsRequest $request): JsonResponse
    {
        $settings = array_merge($this->defaults(), $request->validated());

        Cache::forever(self::CACHE_KEY, $settings);

        return $this->successResponse(
            $settings,
            "Paramètres d'alertes de stock mis à jour."
        );
    }

    private function settings(): array
    {
        $settings = Cache::get(self::CACHE_KEY, []);

        return array_merge($this->defaults(), is_array($settings) ? $settings : []);
    }

    private function defaults(): array
    {
        return [
            'email_low_stock' => true,
            'email_out_of_stock' => true,
            'email_daily_summary' => false,
            'push_low_stock' => true,
            'push_out_of_stock' => true,
            'default_low_stock_threshold' => 10,
            'alert_emails' => '',
            'alert_frequency' => 'realtime',
        ];
    }
}
