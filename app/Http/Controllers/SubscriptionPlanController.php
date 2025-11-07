<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;

class SubscriptionPlanController extends Controller
{

    public function index()
    {
        return SubscriptionPlan::all();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'PlanName' => ['required', 'string', 'max:50'],
            'Price' => ['required', 'numeric', 'min:0'],
            'DurationInMonths' => ['required', 'integer', 'min:1'],
            'MaxChurchesAllowed' => ['required', 'integer', 'min:1'],
            'Description' => ['nullable', 'string'],
        ]);

        $plan = SubscriptionPlan::create($validated);

        return response()->json($plan, 201);
    }

    public function update(Request $request, $id)
    {
        $plan = SubscriptionPlan::findOrFail($id);

        $validated = $request->validate([
            'PlanName' => ['required', 'string', 'max:50'],
            'Price' => ['required', 'numeric', 'min:0'],
            'DurationInMonths' => ['required', 'integer', 'min:1'],
            'MaxChurchesAllowed' => ['required', 'integer', 'min:1'],
            'Description' => ['nullable', 'string'],
        ]);

        $plan->update($validated);

        return response()->json($plan);
    }

    public function destroy($id)
    {
        $plan = SubscriptionPlan::findOrFail($id);
        $plan->delete();

        return response()->noContent();
    }
}