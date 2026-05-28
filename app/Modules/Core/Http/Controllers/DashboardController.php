<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Modules\Order\Models\Order;
use Illuminate\Http\JsonResponse;
use Modules\Catalogue\Models\Brand;
use Modules\Catalogue\Models\Product;
use Modules\Reviews\Models\Review;
use Modules\Customer\Models\Customer;
use Modules\Catalogue\Models\Category;
use Modules\Promotion\Models\Promotion;
use Modules\Catalogue\Models\Attribute;
use Modules\Catalogue\Models\Collection;

class DashboardController extends ApiController
{
    public function stats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['nullable', 'in:daily,weekly,monthly'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $period = $validated['period'] ?? 'daily';
        $from = isset($validated['date_from'])
            ? Carbon::parse($validated['date_from'])->startOfDay()
            : now()->subDays(14)->startOfDay();
        $to = isset($validated['date_to'])
            ? Carbon::parse($validated['date_to'])->endOfDay()
            : now()->endOfDay();

        // Keep the dashboard query bounded so an accidental huge date range never hurts cPanel hosting.
        if ($from->diffInDays($to) > 370) {
            $from = $to->copy()->subDays(370)->startOfDay();
        }

        $paidOrders = Order::query()
            ->where('payment_status', Order::PAYMENT_STATUS_PAID)
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'total', 'created_at']);

        $stats = [
            'customers' => Customer::count(),
            'orders' => Order::whereIn('status', ['pending', 'processing'])->count(),
            'products' => Product::count(),
            'collections' => Collection::count(),
            'brands' => Brand::count(),
            'categories' => Category::count(),
            'attributes' => Attribute::count(),
            'promotions' => Promotion::count(),
            'reviews' => Review::where('status', 'pending')->count(),
            'revenue' => (int) $paidOrders->sum('total'),
            'revenue_series' => $this->buildRevenueSeries($paidOrders, $from, $to, $period),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    private function buildRevenueSeries($orders, Carbon $from, Carbon $to, string $period): array
    {
        $format = match ($period) {
            'monthly' => 'Y-m',
            default => 'Y-m-d',
        };

        $grouped = $orders->groupBy(function (Order $order) use ($period, $format): string {
            $date = $order->created_at instanceof Carbon
                ? $order->created_at->copy()
                : Carbon::parse($order->created_at);

            return match ($period) {
                'weekly' => $date->startOfWeek()->format($format),
                'monthly' => $date->startOfMonth()->format($format),
                default => $date->format($format),
            };
        })->map(fn ($items) => (int) $items->sum('total'));

        $interval = match ($period) {
            'weekly' => '1 week',
            'monthly' => '1 month',
            default => '1 day',
        };

        $cursorStart = match ($period) {
            'weekly' => $from->copy()->startOfWeek(),
            'monthly' => $from->copy()->startOfMonth(),
            default => $from->copy()->startOfDay(),
        };

        return collect(CarbonPeriod::create($cursorStart, $interval, $to))
            ->map(function (Carbon $date) use ($grouped, $format) {
                $key = $date->format($format);

                return [
                    'date' => $key,
                    'amount' => (int) ($grouped[$key] ?? 0),
                ];
            })
            ->values()
            ->all();
    }
}
