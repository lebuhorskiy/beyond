<?php

namespace App\Http\Controllers\API\Education;

use App\Http\Controllers\Controller;
use App\Models\Education\Coach;
use App\Models\Education\Person;
use App\Models\Education\PopUp;
use App\Models\Education\Program;
use App\Models\Education\Review;
use App\Models\Education\SelectionRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoachesController extends Controller
{
    /**
     * Get Main program (From Beginner To Pro)
     *
     * @return JsonResponse
     */
    public function getMainProgram() :JsonResponse
    {
        $popup = PopUp::where('is_from_beginner_to_pro', '=', true)->first();
        return response()->json([
            'popup' => $popup,
            'program' => Program::where('pop_up_id', '=', $popup->id)->first()
        ]);
    }

    /**
     * Get list of all coaches
     *
     * @return JsonResponse
     */
    public function getAllCoaches() :JsonResponse
    {
        return response()->json([
            'coaches' => Coach::with('person', 'programs', 'reviews')->orderBy('sort')->get()
        ]);
    }

    /**
     * Get single coach by slug
     *
     * @param $slug
     * @return JsonResponse
     */
    public function getCoach($slug) :JsonResponse
    {
        $person = Person::whereSlug($slug)->first();

        if(!$person) {
            return response()->json([
                'message' => __('messages.coach_not_found')
            ], $this->notFoundStatus);
        }

        $collection = $person->coachByDiscipline('fortnite')->programs;

        $popup = PopUp::where('is_from_beginner_to_pro', '=', true)->first()->toArray();
        $program = Program::where('pop_up_id', '=', $popup['id'])->first();

        $array = [
            'program' => $program,
            'coach_id' => null,
            'created_at' => Carbon::now(),
            'id' => null,
            'prices' => null,
            'program_id' => $program->id,
            'updated_at' => Carbon::now()
        ];

        $array['program']['pop_up'] = $popup;

        $collection[] = $array;

        $programs = collect($collection)->sortBy('program.sort')->values()->all();

        return response()->json([
            'person' => $person,
            'coach' => $person->coachByDiscipline('fortnite'),
            'programs' => $programs,
            'reviews' => $person->coachByDiscipline('fortnite')->reviews,
            'reviews_rating_average' => $person->coachByDiscipline('fortnite')->reviews->average('rating'),
            'course' => !!$person->courseByDiscipline('fortnite')
        ]);
    }

    /**
     * Get list of all programs
     *
     * @return JsonResponse
     */
    public function getAllPrograms() :JsonResponse
    {
        return response()->json([
            'programs' => Program::with('popUp', 'coaches')->orderBy('sort')->get()
        ]);
    }

    /**
     * Get list of all programs
     *
     * @return JsonResponse
     */
    public function getAllReviews() :JsonResponse
    {
        return response()->json([
            'reviews' => Review::with('program', 'user')->get()
        ]);
    }

    /**
     * Make selection request
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function makeSelectionRequest(Request $request) :JsonResponse
    {
        SelectionRequest::create($request->all());

        return response()->json([
            'message' => 'Запрос успешно отправлен! Мы свяжемся с Вами в ближайшее время!'
        ]);
    }
}
