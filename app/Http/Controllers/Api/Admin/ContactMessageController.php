<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactMessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $messages = ContactMessage::query()
            ->when($request->boolean('unread'), fn ($q) => $q->where('is_read', false))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($inner) use ($s) {
                    $inner->where('name', 'like', "%{$s}%")
                        ->orWhere('phone', 'like', "%{$s}%")
                        ->orWhere('email', 'like', "%{$s}%")
                        ->orWhere('subject', 'like', "%{$s}%")
                        ->orWhere('message', 'like', "%{$s}%");
                });
            })
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($messages);
    }

    public function markRead(ContactMessage $contactMessage): JsonResponse
    {
        $contactMessage->update(['is_read' => true]);

        return response()->json(['data' => $contactMessage]);
    }

    public function markAllRead(): JsonResponse
    {
        ContactMessage::where('is_read', false)->update(['is_read' => true]);

        return response()->json(['message' => 'همه پیام‌ها خوانده شدند.']);
    }

    public function destroy(ContactMessage $contactMessage): JsonResponse
    {
        $contactMessage->delete();

        return response()->json(['message' => 'پیام حذف شد.']);
    }
}
