<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Slider;
use Carbon\Carbon;

class SliderController extends Controller
{
    /**
     * Return public active sliders filtered by start/end dates.
     */
    public function index(Request $request)
    {
        $now = Carbon::now();

        $sliders = Slider::where('is_active', true)
            ->where(function($q) use ($now) {
                $q->whereNull('start_at')->orWhere('start_at', '<=', $now);
            })
            ->where(function($q) use ($now) {
                $q->whereNull('end_at')->orWhere('end_at', '>', $now);
            })
            ->orderBy('order')
            ->get()
            ->map(function($s){
                $sArr = $s->toArray();
                if (!empty($sArr['image']) && !preg_match('/^https?:\/\//', $sArr['image'])) {
                    $sArr['image'] = asset('storage/' . ltrim($sArr['image'], '/'));
                }
                return $sArr;
            });

        return response()->json($sliders);
    }
}
