<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\News\News;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class NewsController extends Controller
{

    /**
     * Create news
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function create(Request $request): ?JsonResponse
    {
        $slug = Str::slug($request->get('title')['en']);
        $image = $request->get('image');
        $name = $slug . '_' . time() . '.' . explode('/', explode(':', substr($image, 0, strpos($image, ';')))[1])[1];

        Image::make($image)->save(storage_path('app/public/uploads/news/') . $name);

        News::create([
            'user_id' => auth()->id(),
            'image' => $name,
            'title' => $request->get('title'),
            'description' => $request->get('description'),
            'slug' => $slug
        ]);

        return \response()->json(['message' => 'Новость успешно опубликована']);
    }

    /**
     * Edit news
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function update(Request $request, $id): ?JsonResponse
    {
        $existing = News::find($id);

        $image = $request->get('image');

        if($image) {
            // Defining name with uuid _ time and mime
            $name = $existing->slug . '_' . time() . '.' . explode('/', explode(':', substr($image, 0, strpos($image, ';')))[1])[1];
            Image::make($image)->save(storage_path('app/public/uploads/news/') . $name);
        }

        $existing->update([
            'user_id' => auth()->id(),
            'image' => $image ? $name : $existing->image,
            'title' => $request->get('title'),
            'description' => $request->get('description'),
            'slug' => Str::slug($request->get('title')['en'])
        ]);

        return \response()->json(['message' => 'Новость успешно обновлена']);
    }

    /**
     * Delete news
     *
     * @param $id
     * @return JsonResponse
     */
    public function delete($id): ?JsonResponse
    {
        News::whereId($id)->delete();
        return \response()->json(['message' => 'Новость успешно удалена']);
    }


}
