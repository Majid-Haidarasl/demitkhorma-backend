<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::withCount('products')->orderBy('sort_order')->get();

        return response()->json(['data' => $categories]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['slug'] = $data['slug'] ?? Str::slug($data['name_fa']);

        $category = Category::create($data);
        ActivityLogger::log('category.created', $category);

        return response()->json(['data' => $category], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $this->validated($request, $category->id);
        $category->update($data);
        ActivityLogger::log('category.updated', $category);

        return response()->json(['data' => $category->fresh()]);
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->products()->exists()) {
            return response()->json(['message' => 'این دسته دارای محصول است و قابل حذف نیست.'], 422);
        }

        ActivityLogger::log('category.deleted', $category);
        $category->delete();

        return response()->json(['message' => 'دسته حذف شد.']);
    }

    private function validated(Request $request, ?int $ignoreId = null): array
    {
        $slugRule = 'unique:categories,slug';
        if ($ignoreId) {
            $slugRule .= ','.$ignoreId;
        }

        return $request->validate([
            'name_fa' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', $slugRule],
            'description' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'string', 'max:255', 'regex:/^(?!.*\\.\\.)[A-Za-z0-9_\\/-]+\\.(jpe?g|png|webp|gif)$/i'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
