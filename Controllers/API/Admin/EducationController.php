<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Education\Coach;
use App\Models\Education\EducationPayment;
use App\Models\Education\Person;
use App\Models\Education\SelectionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class EducationController extends Controller
{

    /**
     * Get persons list
     *
     * @return JsonResponse
     */
    public function getPersonsList(): ?JsonResponse
    {
        return response()->json([
            'list' => Person::orderByDesc('id')->get()
        ]);
    }

    /**
     * Get person
     *
     * @param $id
     * @return JsonResponse
     */
    public function getPerson($id): ?JsonResponse
    {
        return response()->json([
            'person' => Person::find($id)
        ]);
    }

    /**
     * Create person
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createPerson(Request $request): ?JsonResponse
    {
        $first_name_en = $request->post('first_name')['en'];
        $last_name_en = $request->post('last_name')['en'];
        $photo = $request->post('photo');
        $slug = Str::slug($first_name_en . '-' . $request->post('nick_name') . '-' . $last_name_en);

        // Defining name with uuid _ time and mime
        $name = $slug . '_' . time() . '.' . explode('/', explode(':', substr($photo, 0, strpos($photo, ';')))[1])[1];
        Image::make($photo)->save(storage_path('app/public/uploads/education/persons/') . $name);

        Person::create([
            'photo' => $name,
            'first_name' => $request->post('first_name'),
            'nick_name' => $request->post('nick_name'),
            'last_name' => $request->post('last_name'),
            'bio' => $request->post('bio'),
            'slug' => $slug
        ]);

        return response()->json(['message' => 'Человек успешно добавлен']);
    }

    /**
     * Update person
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function updatePerson(Request $request, $id): ?JsonResponse
    {
        $person = Person::find($id);

        $photo = $request->post('photo');

        $name = explode('/', $person->photo);
        $name = $name[count($name) - 1];

        if($photo) {
            // Defining name with uuid _ time and mime
            $name = $person->slug . '_' . time() . '.' . explode('/', explode(':', substr($photo, 0, strpos($photo, ';')))[1])[1];
            Image::make($photo)->save(storage_path('app/public/uploads/education/persons/') . $name);
        }

        $person->update([
            'photo' => $name,
            'first_name' => $request->post('first_name'),
            'nick_name' => $request->post('nick_name'),
            'last_name' => $request->post('last_name'),
            'bio' => $request->post('bio'),
            'slug' => $request->post('slug')
        ]);

        return response()->json(['message' => 'Информация успешно обновлена']);
    }

    /**
     * Delete person
     *
     * @param $id
     * @return JsonResponse
     */
    public function deletePerson($id): ?JsonResponse
    {
        Person::find($id)->delete();

        return response()->json(['message' => 'Человек успешно удален']);
    }

    /**
     * Get selection requests list
     *
     * @return JsonResponse
     */
    public function getRequestsList(): ?JsonResponse
    {
        return response()->json([
            'list' => SelectionRequest::orderBy('processed')->get()
        ]);
    }

    /**
     * Get selection requests list
     *
     * @return JsonResponse
     */
    public function getPaymentsList(): ?JsonResponse
    {
        return response()->json([
            'list' => EducationPayment::with('program', 'coach', 'user')->where('paid', '=', true)->orderByDesc('updated_at')->get()
        ]);
    }

    /**
     * Update request processed state
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function updateRequestProcessed(Request $request, $id): ?JsonResponse
    {
        SelectionRequest::find($id)->update(['processed' => $request->post('processed')]);

        return response()->json([
            'message' => 'Информация успешно обновлена!'
        ]);
    }

    /**
     * Update request processed state
     *
     * @param Request $request
     * @param $id
     * @return JsonResponse
     */
    public function updatePaymentCompleted(Request $request, $id): ?JsonResponse
    {
        EducationPayment::find($id)->update(['completed' => $request->post('completed')]);

        return response()->json([
            'message' => $request->post('completed') ? 'Заказ отмечен выполненым!' : 'Заказ отмечен не выполненным!'
        ]);
    }

    /**
     * Get coaches list
     *
     * @return JsonResponse
     */
    public function getCoachesList(): ?JsonResponse
    {
        return response()->json([
            'list' => Coach::with('person')->orderByDesc('id')->get()
        ]);
    }
}
