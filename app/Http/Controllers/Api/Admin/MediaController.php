<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use App\Services\ActivityLogger;
use App\Support\SafeInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $files = MediaFile::with('uploader:id,username')
            ->when($request->filled('folder'), fn ($q) => $q->where('folder', $request->folder))
            ->latest()
            ->paginate($request->safePerPage( 24));

        return response()->json($files);
    }

    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'folder' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9_-]+$/'],
        ]);

        $folder = $data['folder'] ?? 'uploads';
        $file = $data['file'];
        $ext = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };

        if (! $ext) {
            throw ValidationException::withMessages([
                'file' => ['فقط تصویرهای JPG، PNG، WEBP و GIF مجاز هستند.'],
            ]);
        }

        $name = Str::uuid()->toString().'.'.$ext;
        $path = $file->storeAs($folder, $name, 'public');

        $media = MediaFile::create([
            'path' => $path,
            'folder' => $folder,
            'original_name' => SafeInput::originalFilename($file->getClientOriginalName()),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize() ?: 0,
            'uploaded_by' => $request->user()?->id,
        ]);

        ActivityLogger::log('media.uploaded', $media, ['path' => $path]);

        return response()->json([
            'data' => [
                'id' => $media->id,
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'folder' => $folder,
                'original_name' => $media->original_name,
                'size' => $media->size,
            ],
        ], 201);
    }

    public function destroy(MediaFile $media): JsonResponse
    {
        Storage::disk('public')->delete($media->path);
        ActivityLogger::log('media.deleted', $media, ['path' => $media->path]);
        $media->delete();

        return response()->json(['message' => 'فایل حذف شد.']);
    }
}
