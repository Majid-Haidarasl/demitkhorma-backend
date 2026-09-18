<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BannerController extends Controller
{
    public function index(): JsonResponse
    {
        $banners = Banner::orderBy('sort_order')->get();

        return response()->json(['data' => $banners]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $banner = Banner::create($data);
        ActivityLogger::log('banner.created', $banner);

        return response()->json(['data' => $banner], 201);
    }

    public function update(Request $request, Banner $banner): JsonResponse
    {
        $banner->update($this->validated($request));
        ActivityLogger::log('banner.updated', $banner);

        return response()->json(['data' => $banner->fresh()]);
    }

    public function destroy(Banner $banner): JsonResponse
    {
        ActivityLogger::log('banner.deleted', $banner);
        $banner->delete();

        return response()->json(['message' => 'بنر حذف شد.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'image' => ['required', 'string', 'max:255', 'regex:/^(?!.*\\.\\.)[A-Za-z0-9_\\/-]+\\.(jpe?g|png|webp|gif)$/i'],
            'link' => ['nullable', 'string', 'max:255', 'regex:/^(\\/(?!\\/)[A-Za-z0-9._~\\-\\/?=&%#]*|https:\\/\\/[A-Za-z0-9.-]+(:\\d+)?(\\/[^\\s]*)?)$/'],
            'placement' => ['nullable', 'string', 'in:main,side'],
            'is_active' => ['nullable', 'boolean'],
            'is_draft' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
    }
}
