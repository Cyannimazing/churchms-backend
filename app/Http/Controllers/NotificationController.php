<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use App\Events\NotificationRead;
use App\Events\NotificationReadAll;
use App\Events\NotificationDeleted;

class NotificationController extends Controller
{
    /**
     * Get all notifications for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $notifications]);
    }

    /**
     * Get unread notification count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Mark a notification as read
     */
    public function markAsRead(Request $request, $id): JsonResponse
    {
        $notification = Notification::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $notification->markAsRead();
        
        // Broadcast the notification read event
        broadcast(new NotificationRead($request->user()->id, $notification->id));

        return response()->json([
            'message' => 'Notification marked as read',
            'notification' => $notification
        ]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now()
            ]);
        
        // Broadcast the notification read all event
        broadcast(new NotificationReadAll($request->user()->id));

        return response()->json(['message' => 'All notifications marked as read']);
    }

    /**
     * Delete a notification
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $notification = Notification::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();
        
        $wasUnread = !$notification->is_read;
        $notification->delete();
        
        // Broadcast the notification deleted event
        broadcast(new NotificationDeleted($request->user()->id, $id));

        return response()->json([
            'message' => 'Notification deleted',
            'was_unread' => $wasUnread
        ]);
    }
}
