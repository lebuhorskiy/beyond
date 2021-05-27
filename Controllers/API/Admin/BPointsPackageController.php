<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\BpPointsPackage;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BPointsPackageController extends Controller
{

    /**
     * Create bpoint package api
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function create(Request $request): ?JsonResponse
    {
        $model = new BpPointsPackage();
        $model->amount = $request->post('amount');
        $model->discount = $request->post('discount');
        $model->uuid = Str::uuid();
        $model->created_at = Carbon::now();
        $model->updated_at = Carbon::now();
        $model->save();
        return \response()->json(['message' => 'B points package создан']);
    }

    /**
     * Delete bpoint package api
     *
     * @param $uuid
     * @return JsonResponse
     */
    public function delete($uuid): ?JsonResponse
    {
        BpPointsPackage::whereUuid($uuid)->delete();
        return \response()->json(['message' => 'B points package удалён']);
    }

    /**
     * Update bpoint package api
     *
     * @param $uuid
     * @return JsonResponse
     */
    public function update(Request $request, $uuid): ?JsonResponse
    {
        BpPointsPackage::whereUuid($uuid)->update([
            'amount' => $request->patch('amount'),
            'discount' => $request->patch('discount'),
            'updated_at' => Carbon::now()
        ]);
        return \response()->json(['message' => 'B points package обновлён']);
    }

}
