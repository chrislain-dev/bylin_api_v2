<?php

declare(strict_types=1);

namespace Modules\Notification\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Http\Controllers\ApiController;
use Modules\Notification\Models\Notification;

class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);

        $query = Notification::forNotifiable($user::class, (string) $user->id)
            ->latest();

        if ($request->boolean('unread_only')) {
            $query->unread();
        }

        if ($request->filled('channel')) {
            $query->where('channel', 'like', '%' . $request->string('channel')->toString() . '%');
        }

        if ($request->filled('type')) {
            $query->type($request->string('type')->toString());
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->string('priority')->toString());
        }

        return $this->successResponse($query->paginate($perPage));
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $notification = Notification::forNotifiable($user::class, (string) $user->id)
            ->findOrFail($id);

        $notification->markAsRead();

        return $this->successResponse($notification, 'Notification marquée comme lue.');
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = Notification::forNotifiable($user::class, (string) $user->id)
            ->unread()
            ->update(['read_at' => now()]);

        return $this->successResponse(
            ['count' => $count],
            "{$count} notification(s) marquée(s) comme lue(s)."
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = Notification::forNotifiable($user::class, (string) $user->id)
            ->unread()
            ->count();

        return $this->successResponse(['count' => $count]);
    }
}
