<?php

namespace App\Http\Controllers\API\Store;

use App\Http\Controllers\Controller;
use App\Models\Store\Catalog;
use App\Models\Store\Good;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class GoodsController extends Controller
{
    public $successStatus = 200;

    /**
     * Get list of all goods api
     *
     * @return JsonResponse
     */
    public function getGoods()
    {
        $goods = Good::with('catalog')
            ->orderBy('price')
            ->get();
        return response()->json([
            'goods' => $goods,
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Get list of goods by catalog api
     *
     * @param $category
     * @return JsonResponse
     */
    public function getGoodsByCatalog($category)
    {
        $category = Catalog::where('title', '=', $category)->first();

        $goods = Good::with('catalog')
            ->orderBy('price')
            ->where('catalog_id', '=', $category->id)
            ->get();

        return response()->json([
            'goods' => $goods,
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Get list of goods for header slider api
     *
     * @return JsonResponse
     */
    public function getGoodsForSlider()
    {
        $goods = Good::with('catalog')
            ->orderBy('price')
            ->where('show_on_slider', '=', true)
            ->get();

        return response()->json([
            'goods' => $goods,
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Get one from goods api
     *
     * @param $slug
     * @return JsonResponse
     */
    public function getGood($slug)
    {
        $good = Good::with('catalog')->where('slug', '=', $slug)->first();
        return response()->json([
            'good' => $good,
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Create one from goods api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function makeGood(Request $request)
    {
        $image_preview = $request->get('image_preview');
        $slug = Str::slug($request->get('title')['en']);

        $image_preview_name = $slug . '_' . time() . '.' . explode('/', explode(':', substr($image_preview, 0, strpos($image_preview, ';')))[1])[1];
        Image::make($image_preview)->save(storage_path('app/public/uploads/shop/') . $image_preview_name);

        Good::create([
            'title' => $request->get('title'),
            'description' => $request->get('description'),
            'price' => $request->get('price'),
            'show_on_slider' => $request->get('show_on_slider'),
            'slug' => $slug,
            'catalog_id' => $request->get('catalog_id'),
            'image_preview' => $image_preview_name,
            'action' => $request->get('action'),
        ]);

        return response()->json([
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Update one from goods api
     *
     * @param $id
     * @param Request $request
     * @return JsonResponse
     */
    public function updateGood($id, Request $request)
    {
        $good = Good::find($id);

        $data = $request->all();

        if($request->get('image_preview')) {
            $image_preview = $request->get('image_preview');

            $image_preview_name = $good->slug . '_' . time() . '.' . explode('/', explode(':', substr($image_preview, 0, strpos($image_preview, ';')))[1])[1];
            Image::make($image_preview)->save(storage_path('app/public/uploads/shop/') . $image_preview_name);

            $data['image_preview'] = $image_preview_name;
        }

        $good->update($data);

        return response()->json([
            'message' => 'Товар успешно обновлен',
            'success' => true
        ], $this->successStatus);
    }

    /**
     * Delete one from goods api
     *
     * @param $id
     * @return JsonResponse
     */
    public function deleteGood($id)
    {
        Good::find($id)->delete();
        return response()->json([
            'message' => 'Товар успешно удален',
            'success' => true
        ], $this->successStatus);
    }


}
