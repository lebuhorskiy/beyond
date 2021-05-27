<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\FAQ;
use App\Models\Users\EmailSubscriber;
use App\Models\Users\User;
use App\Models\Utm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExtensionsController extends Controller
{

    /**
     * Get list of all countries api
     *
     * @return JsonResponse
     */
    public function countries(): JsonResponse
    {
        $countries = DB::table('countries')->orderBy('name')->select('id', 'name')->get();

        return response()->json([
            'countries' => $countries,
            'success' => true
        ]);
    }

    public function updateUserEpicNickname(Request $request): void
    {
        $user = User::where('epic_id', '=', $request->get('fid'))->first();

        if($user) {
            $user->update(['epic_nickname' => $request->get('nick')]);
        }
    }

    /**
     * @param $fid
     */
    public function deleteUserEpicNickname($fid): void
    {
        $user = User::where('epic_id', '=', $fid)->first();

        if($user) {
            $user->update(['epic_nickname' => null, 'epic_id' => null]);
        }
    }

    /**
     * Subscribe an email
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function emailSubscribe(Request $request): JsonResponse
    {
        $exists = EmailSubscriber::where('email', '=', $request->get('email'))->first();

        if($exists) {
            return response()->json(['error' => 'Email already exists'], $this->existStatus);
        }

        EmailSubscriber::create(['email' => $request->get('email')]);

        return response()->json(['message' => 'You have successfully subscribed!', 'success' => true]);
    }

    /**
     * Get faqs limited by 20
     * @return JsonResponse
     */
    public function getFaqsList(): JsonResponse
    {
        $response = FAQ::limit(20)->get();

        return response()->json($response);
    }

    /**
     * Get faqs by query
     * @param $query
     * @return JsonResponse
     */
    public function getFaqsByQuery($query): JsonResponse
    {
        $response = FAQ::where('question->ru', 'ilike', "%$query%")
            ->orWhere('answer->ru', 'ilike', "%$query%")
            ->where('question->en', 'ilike', "%$query%")
            ->orWhere('answer->en', 'ilike', "%$query%")
            ->get();

        return response()->json($response);
    }

    /**
     * Get faq by id
     * @param $id
     * @return JsonResponse
     */
    public function getFaqById($id): JsonResponse
    {
        $response = FAQ::find($id);

        return response()->json($response);
    }

    /**
     * Store UTM data
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function storeUtm(Request $request): JsonResponse
    {
        Utm::create($request->all());

        return response()->json('');
    }
}
