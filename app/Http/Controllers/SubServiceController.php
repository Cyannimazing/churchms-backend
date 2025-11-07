<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\SubService;
use App\Models\SubServiceSchedule;
use App\Models\SacramentService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class SubServiceController extends Controller
{
    /**
     * Get all sub-services for a specific sacrament service
     */
    public function index(int $serviceId): JsonResponse
    {
        try {
            // Verify service exists
            $service = SacramentService::find($serviceId);
            if (!$service) {
                return response()->json([
                    'error' => 'Sacrament service not found.'
                ], 404);
            }

            // Get all sub-services with their schedules
            $subServices = SubService::with('schedules')
                ->where('ServiceID', $serviceId)
                ->orderBy('SubServiceName')
                ->get();

            return response()->json([
                'sub_services' => $subServices
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching sub-services.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific sub-service by ID
     */
    public function show(int $subServiceId): JsonResponse
    {
        try {
            $subService = SubService::with('schedules')->find($subServiceId);

            if (!$subService) {
                return response()->json([
                    'error' => 'Sub-service not found.'
                ], 404);
            }

            return response()->json($subService);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching the sub-service.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new sub-service with schedules
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'ServiceID' => 'required|integer|exists:sacrament_service,ServiceID',
                'SubServiceName' => 'required|string|max:100',
                'Description' => 'nullable|string',
                'IsActive' => 'nullable|boolean',
                'schedules' => 'nullable|array',
                'schedules.*.DayOfWeek' => 'required|string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
                'schedules.*.StartTime' => 'required|date_format:H:i',
                'schedules.*.EndTime' => 'required|date_format:H:i|after:schedules.*.StartTime',
                'schedules.*.OccurrenceType' => 'required|string|in:weekly,nth_day_of_month',
                'schedules.*.OccurrenceValue' => 'nullable|integer|in:1,2,3,4,-1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            try {
                // Create sub-service
                $subService = SubService::create([
                    'ServiceID' => $request->ServiceID,
                    'SubServiceName' => $request->SubServiceName,
                    'Description' => $request->Description,
                    'IsActive' => $request->IsActive ?? true,
                ]);

                // Create schedules if provided
                if ($request->has('schedules') && is_array($request->schedules)) {
                    foreach ($request->schedules as $schedule) {
                        SubServiceSchedule::create([
                            'SubServiceID' => $subService->SubServiceID,
                            'DayOfWeek' => $schedule['DayOfWeek'],
                            'StartTime' => $schedule['StartTime'],
                            'EndTime' => $schedule['EndTime'],
                            'OccurrenceType' => $schedule['OccurrenceType'],
                            'OccurrenceValue' => $schedule['OccurrenceValue'] ?? null,
                        ]);
                    }
                }

                DB::commit();

                // Load schedules for response
                $subService->load('schedules');

                return response()->json($subService, 201);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while creating the sub-service.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a sub-service and its schedules
     */
    public function update(Request $request, int $subServiceId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'SubServiceName' => 'required|string|max:100',
                'Description' => 'nullable|string',
                'IsActive' => 'nullable|boolean',
                'schedules' => 'nullable|array',
                'schedules.*.DayOfWeek' => 'required|string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
                'schedules.*.StartTime' => 'required|date_format:H:i',
                'schedules.*.EndTime' => 'required|date_format:H:i|after:schedules.*.StartTime',
                'schedules.*.OccurrenceType' => 'required|string|in:weekly,nth_day_of_month',
                'schedules.*.OccurrenceValue' => 'nullable|integer|in:1,2,3,4,-1',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $subService = SubService::find($subServiceId);

            if (!$subService) {
                return response()->json([
                    'error' => 'Sub-service not found.'
                ], 404);
            }

            DB::beginTransaction();

            try {
                // Update sub-service
                $subService->update([
                    'SubServiceName' => $request->SubServiceName,
                    'Description' => $request->Description,
                    'IsActive' => $request->IsActive ?? $subService->IsActive,
                ]);

                // Delete existing schedules
                SubServiceSchedule::where('SubServiceID', $subServiceId)->delete();

                // Create new schedules if provided
                if ($request->has('schedules') && is_array($request->schedules)) {
                    foreach ($request->schedules as $schedule) {
                        SubServiceSchedule::create([
                            'SubServiceID' => $subService->SubServiceID,
                            'DayOfWeek' => $schedule['DayOfWeek'],
                            'StartTime' => $schedule['StartTime'],
                            'EndTime' => $schedule['EndTime'],
                            'OccurrenceType' => $schedule['OccurrenceType'],
                            'OccurrenceValue' => $schedule['OccurrenceValue'] ?? null,
                        ]);
                    }
                }

                DB::commit();

                // Load schedules for response
                $subService->load('schedules');

                return response()->json($subService);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while updating the sub-service.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a sub-service
     */
    public function destroy(int $subServiceId): JsonResponse
    {
        try {
            $subService = SubService::find($subServiceId);

            if (!$subService) {
                return response()->json([
                    'error' => 'Sub-service not found.'
                ], 404);
            }

            $subService->delete();

            return response()->json([
                'message' => 'Sub-service deleted successfully.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while deleting the sub-service.',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
