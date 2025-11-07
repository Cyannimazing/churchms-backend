<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    public function getProvinces()
    {
        $provinces = DB::table('provinces')
            ->orderBy('name')
            ->get();

        return response()->json($provinces);
    }

    public function getCitiesByProvince($provinceId)
    {
        $cities = DB::table('cities')
            ->where('province_id', $provinceId)
            ->orderBy('name')
            ->get();

        return response()->json($cities);
    }

    public function getAllLocations()
    {
        $provinces = DB::table('provinces')
            ->orderBy('name')
            ->get();

        $locations = [];
        foreach ($provinces as $province) {
            $cities = DB::table('cities')
                ->where('province_id', $province->id)
                ->orderBy('name')
                ->get();

            $locations[] = [
                'id' => $province->id,
                'name' => $province->name,
                'cities' => $cities
            ];
        }

        return response()->json($locations);
    }
}
