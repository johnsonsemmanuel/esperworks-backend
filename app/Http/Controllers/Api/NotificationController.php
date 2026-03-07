<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $query = \App\Models\Notification::where('user_id', $request->user()->id);

        if ($request->unread) {
            $query->whereNull('read_at');
        }

        $notifications = $query->latest()->paginate($request->per_page ?? 20);

        return response()->json($notifications);
    }

    public function unreadCount(Request $request)
    {
        $count = \App\Models\Notification::where('user_id', $request->user()->id)
            ->whereNull('read_at')->count();

        return response()->json(['count' => $count]);
    }

    public function markAsRead(Request $request, string $id)
    {
        $notification = \App\Models\Notification::where('id', $id)
            ->where('user_id', $request->user()->id)->firstOrFail();

        $notification->update(['read_at' => now()]);

        return response()->json(['message' => 'Marked as read']);
    }

    public function markAllRead(Request $request)
    {
        \App\Models\Notification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read']);
    }

    public static function create(int $userId, string $type, string $title, string $message, ?int $businessId = null, array $data = [], ?string $link = null): void
    {
        \App\Models\Notification::create([
            'user_id' => $userId,
            'business_id' => $businessId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => $data ?: null,
            'link' => $link,
        ]);
    }
}
