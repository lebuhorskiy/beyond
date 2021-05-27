<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\UrlShortener;
use App\Models\Utm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UTMController extends Controller
{

    /**
     * Get all campaigns
     *
     * @return JsonResponse
     */
    public function getCampaigns(): ?JsonResponse
    {
        $query = Utm::where('utm_campaign', '!=', null)->select('utm_campaign as label', 'utm_campaign as value')->groupBy('utm_campaign')->get();
        $campaigns = [];
        $campaigns[0] = [
            'text' => 'Все компании',
            'value' => 'all'
        ];

        for($i = 0; $i < count($query); $i++) {
            $campaigns[$i+1] = [
                'text' => $query[$i]->label,
                'value' => $query[$i]->value
            ];
        }

        return response()->json([
            'campaigns' => $campaigns
        ]);
    }

    /**
     * Get list and stats for campaign
     *
     * @param $campaign
     * @return JsonResponse
     */
    public function getCampaignList($campaign): ?JsonResponse
    {
        $list = Utm::query();
        if($campaign === 'all') {
            $list = $list->where('utm_campaign', '!=', null);
        } else {
            $list = $list->where('utm_campaign', '=', $campaign);
        }

        return response()->json([
            'stats' => $list->get()->count(),
            'list' => $list->latest('created_at')->limit(1000)->get(),
        ]);
    }

    /**
     * Create campaign
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createCampaign(Request $request): ?JsonResponse
    {
        UrlShortener::create($request->all());

        return response()->json([
            'message' => 'Компания успешно добавлена',
        ]);
    }
}
