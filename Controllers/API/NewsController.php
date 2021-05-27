<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\News\News;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;


class NewsController extends Controller
{

    /**
     * Get news list
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getNewsList(Request $request): ?JsonResponse
    {
        if($request->limit >= config('api.max_per_page')){
            $request->limit = config('api.max_per_page');
        }
        $news = News::query()->orderByDesc('created_at')->paginate($request->limit, ['*'], 'page', $request->page);
        return \response()->json($news);
    }

    /**
     * Get news by slug
     *
     * @param $slug
     * @return JsonResponse
     */
    public function getNews($slug): ?JsonResponse
    {
        $news = News::query()->whereSlug($slug)->first();

        if(!$news) {
            return \response()->json(['message' => 'Новость не найдена'], $this->notFoundStatus);
        }

        return \response()->json($news);
    }

    /**
     * Get news all
     *
     * @return JsonResponse
     */
    public function getNewsAll(): ?JsonResponse
    {
        $news = News::orderByDesc('created_at')->get();
        return \response()->json($news);
    }

}
