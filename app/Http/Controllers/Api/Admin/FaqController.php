<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FaqController extends Controller
{
    public function index(): JsonResponse
    {
        $faqs = Faq::orderBy('sort_order')->get();

        return response()->json(['data' => $faqs]);
    }

    public function store(Request $request): JsonResponse
    {
        $faq = Faq::create($this->validated($request));

        return response()->json(['data' => $faq], 201);
    }

    public function update(Request $request, Faq $faq): JsonResponse
    {
        $faq->update($this->validated($request));

        return response()->json(['data' => $faq->fresh()]);
    }

    public function destroy(Faq $faq): JsonResponse
    {
        $faq->delete();

        return response()->json(['message' => 'سؤال حذف شد.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'question_fa' => ['required', 'string', 'max:500'],
            'answer_fa' => ['required', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
