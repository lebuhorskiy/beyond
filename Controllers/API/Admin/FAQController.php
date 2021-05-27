<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\FAQ;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FAQController extends Controller
{

    /**
     * Create FAQ
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function create(Request $request): ?JsonResponse
    {
        FAQ::create([
            'question' => [
                "ru" => $request->get('question_ru'),
                "en" => $request->get('question_en'),
            ],
            'answer' => [
                "ru" => $request->get('answer_ru'),
                "en" => $request->get('answer_en'),
            ],
        ]);

        return \response()->json(['message' => 'FAQ успешно опубликован']);
    }

    /**
     * Edit FAQ
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function update(Request $request, $id): ?JsonResponse
    {
        $faq = FAQ::find($id);

        $faq->update([
            'question' => [
                "ru" => $request->get('question_ru'),
                "en" => $request->get('question_en'),
            ],
            'answer' => [
                "ru" => $request->get('answer_ru'),
                "en" => $request->get('answer_en'),
            ],
        ]);
        return \response()->json(['message' => 'FAQ успешно обновлен']);
    }

    /**
     * Delete FAQ
     *
     * @param $id
     * @return JsonResponse
     */
    public function delete($id): ?JsonResponse
    {
        FAQ::whereId($id)->delete();
        return \response()->json(['message' => 'FAQ успешно удален']);
    }


}
