<?php

namespace App\Http\Controllers;

use App\Models\ServiceSchedule;
use App\Models\ScheduleRecurrence;
use App\Models\ScheduleTime;
use App\Models\SacramentService;
use App\Models\SubSacramentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class ScheduleController extends Controller
{
    /**
     * Get all schedules for a specific service
     */
    public function getServiceSchedules($serviceId)
    {
        try {
            $service = SacramentService::findOrFail($serviceId);
            
            $schedules = ServiceSchedule::with(['recurrences', 'times', 'subSacramentService'])
                ->where('ServiceID', $serviceId)
                ->orderBy('StartDate', 'asc')
                ->get();
            
            return response()->json([
                'success' => true,
                'service' => $service,
                'schedules' => $schedules
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch schedules: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific schedule with all its details
     */
    public function getSchedule($scheduleId)
    {
        try {
            $schedule = ServiceSchedule::with(['recurrences', 'times', 'sacramentService', 'subSacramentService'])
                ->findOrFail($scheduleId);
            
            return response()->json([
                'success' => true,
                'schedule' => $schedule
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Schedule not found'
            ], 404);
        }
    }

    /**
     * Create a new service schedule
     */
    public function store(Request $request, $serviceId)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'nullable|date',
            'slot_capacity' => 'required|integer|min:1',
            'service_id' => 'nullable|integer',
            'is_variant' => 'nullable|boolean',
            
            // Recurrence rules
            'recurrences' => 'required|array|min:1',
            'recurrences.*.recurrence_type' => 'required|string',
            'recurrences.*.day_of_week' => 'nullable|integer',
            'recurrences.*.week_of_month' => 'nullable|integer',
            'recurrences.*.specific_date' => 'nullable|date',
            
            // Time slots
            'times' => 'required|array|min:1',
            'times.*.start_time' => 'required|date_format:H:i',
            'times.*.end_time' => 'required|date_format:H:i|after:start_time',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Determine if this is for a variant or parent service
            $actualServiceId = $request->input('service_id', $serviceId);
            $subSacramentServiceId = null;
            
            if ($request->input('is_variant', false)) {
                // This is a variant schedule
                $subSacramentService = SubSacramentService::find($actualServiceId);
                if ($subSacramentService) {
                    $subSacramentServiceId = $subSacramentService->SubSacramentServiceID;
                    $serviceId = $subSacramentService->ParentServiceID; // Link to parent
                }
            }
            
            // Create the main schedule
            $schedule = ServiceSchedule::create([
                'ServiceID' => $serviceId,
                'SubSacramentServiceID' => $subSacramentServiceId,
                'StartDate' => $request->start_date,
                'EndDate' => $request->end_date,
                'SlotCapacity' => $request->slot_capacity,
            ]);

            // Create recurrence patterns
            foreach ($request->recurrences as $recurrenceData) {
                $data = [
                    'ScheduleID' => $schedule->ScheduleID,
                    'RecurrenceType' => $recurrenceData['recurrence_type'],
                ];

                switch ($recurrenceData['recurrence_type']) {
                    case 'Weekly':
                        $data['DayOfWeek'] = $recurrenceData['day_of_week'];
                        break;
                    case 'MonthlyNth':
                        $data['DayOfWeek'] = $recurrenceData['day_of_week'];
                        $data['WeekOfMonth'] = $recurrenceData['week_of_month'];
                        break;
                    case 'OneTime':
                        $data['SpecificDate'] = $recurrenceData['specific_date'];
                        break;
                }

                ScheduleRecurrence::create($data);
            }

            // Create time slots
            foreach ($request->times as $timeSlot) {
                ScheduleTime::create([
                    'ScheduleID' => $schedule->ScheduleID,
                    'StartTime' => $timeSlot['start_time'],
                    'EndTime' => $timeSlot['end_time'],
                ]);
            }

            DB::commit();

            // Load the created schedule with relationships
            $schedule->load(['recurrences', 'times', 'subSacramentService']);

            return response()->json([
                'success' => true,
                'message' => 'Schedule created successfully',
                'schedule' => $schedule
            ], 201);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to create schedule: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an existing schedule
     */
    public function update(Request $request, $scheduleId)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after:start_date',
            'slot_capacity' => 'required|integer|min:1|max:1000',
            
            // Recurrence rules
            'recurrences' => 'required|array|min:1',
            'recurrences.*.recurrence_type' => 'required|in:Weekly,MonthlyNth,OneTime',
            'recurrences.*.day_of_week' => 'nullable|integer|between:0,6',
            'recurrences.*.week_of_month' => 'nullable|integer|between:1,5',
            'recurrences.*.specific_date' => 'nullable|date',
            
            // Time slots
            'times' => 'required|array|min:1',
            'times.*.start_time' => 'required|date_format:H:i',
            'times.*.end_time' => 'required|date_format:H:i|after:start_time',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $schedule = ServiceSchedule::findOrFail($scheduleId);
            
            // Update the main schedule
            $schedule->update([
                'StartDate' => $request->start_date,
                'EndDate' => $request->end_date,
                'SlotCapacity' => $request->slot_capacity,
            ]);

            // Update or create recurrence patterns
            $existingRecurrences = $schedule->recurrences->keyBy('RecurrenceID');
            $processedRecurrenceIds = [];

            foreach ($request->recurrences as $recurrenceData) {
                $data = [
                    'ScheduleID' => $schedule->ScheduleID,
                    'RecurrenceType' => $recurrenceData['recurrence_type'],
                ];

                switch ($recurrenceData['recurrence_type']) {
                    case 'Weekly':
                        $data['DayOfWeek'] = $recurrenceData['day_of_week'];
                        break;
                    case 'MonthlyNth':
                        $data['DayOfWeek'] = $recurrenceData['day_of_week'];
                        $data['WeekOfMonth'] = $recurrenceData['week_of_month'];
                        break;
                    case 'OneTime':
                        $data['SpecificDate'] = $recurrenceData['specific_date'];
                        break;
                }

                // If recurrence_id is provided, update existing; otherwise create new
                if (isset($recurrenceData['recurrence_id']) && isset($existingRecurrences[$recurrenceData['recurrence_id']])) {
                    $existingRecurrences[$recurrenceData['recurrence_id']]->update($data);
                    $processedRecurrenceIds[] = $recurrenceData['recurrence_id'];
                } else {
                    $newRecurrence = ScheduleRecurrence::create($data);
                    $processedRecurrenceIds[] = $newRecurrence->RecurrenceID;
                }
            }

            // Delete recurrences that were removed
            $recurrencesToDelete = $existingRecurrences->keys()->diff($processedRecurrenceIds);
            if ($recurrencesToDelete->isNotEmpty()) {
                ScheduleRecurrence::whereIn('RecurrenceID', $recurrencesToDelete)->delete();
            }

            // Update or create time slots
            $existingTimes = $schedule->times->keyBy('ScheduleTimeID');
            $processedTimeIds = [];

            foreach ($request->times as $timeSlot) {
                $timeData = [
                    'ScheduleID' => $schedule->ScheduleID,
                    'StartTime' => $timeSlot['start_time'],
                    'EndTime' => $timeSlot['end_time'],
                ];

                // If time_id is provided, update existing; otherwise create new
                if (isset($timeSlot['time_id']) && isset($existingTimes[$timeSlot['time_id']])) {
                    $existingTimes[$timeSlot['time_id']]->update($timeData);
                    $processedTimeIds[] = $timeSlot['time_id'];
                } else {
                    $newTime = ScheduleTime::create($timeData);
                    $processedTimeIds[] = $newTime->ScheduleTimeID;
                }
            }

            // Delete times that were removed
            $timesToDelete = $existingTimes->keys()->diff($processedTimeIds);
            if ($timesToDelete->isNotEmpty()) {
                ScheduleTime::whereIn('ScheduleTimeID', $timesToDelete)->delete();
            }

            DB::commit();

            // Load the updated schedule with relationships
            $schedule->load(['recurrences', 'times']);

            return response()->json([
                'success' => true,
                'message' => 'Schedule updated successfully',
                'schedule' => $schedule
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update schedule: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a schedule
     */
    public function destroy($scheduleId)
    {
        try {
            $schedule = ServiceSchedule::findOrFail($scheduleId);
            
            // Check if schedule has any bookings (you'll need to implement this based on your booking system)
            // For now, we'll assume it's safe to delete
            
            $schedule->delete(); // This will cascade delete related records due to foreign key constraints
            
            return response()->json([
                'success' => true,
                'message' => 'Schedule deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete schedule: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available time slots for a service on a specific date
     */
    public function getAvailableTimeSlots(Request $request, $serviceId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'date' => 'nullable|date',
                'recurrence_type' => 'required|string|in:Weekly,MonthlyNth,OneTime',
                'day_of_week' => 'nullable|integer|between:0,6',
                'week_of_month' => 'nullable|integer|between:1,5',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $date = $request->date;
            $recurrenceType = $request->recurrence_type;
            $dayOfWeekParam = $request->day_of_week;
            $weekOfMonthParam = $request->week_of_month;

            // Compute target day-of-week and week-of-month based on provided inputs
            $dateCarbon = $date ? Carbon::parse($date) : null;
            $dateDow = $dateCarbon ? $dateCarbon->dayOfWeek : null; // 0=Sunday
            $dateWeekOfMonth = $dateCarbon ? intval(floor(($dateCarbon->day - 1) / 7) + 1) : null;

            $targetDayOfWeek = $recurrenceType === 'OneTime' ? $dateDow : $dayOfWeekParam;
            $targetWeekOfMonth = $recurrenceType === 'MonthlyNth' ? ($weekOfMonthParam ?? $dateWeekOfMonth) : null;

            \Log::info('GetAvailableTimeSlots called', [
                'serviceId' => $serviceId,
                'date' => $date,
                'recurrence_type' => $recurrenceType,
                'day_of_week' => $targetDayOfWeek,
                'week_of_month' => $targetWeekOfMonth
            ]);

            // Determine the church of this service, then fetch ALL schedules for the church
            $service = SacramentService::find($serviceId);
            if (!$service) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service not found'
                ], 404);
            }

            $serviceIdsInChurch = SacramentService::where('ChurchID', $service->ChurchID)->pluck('ServiceID');

            $existingSchedules = ServiceSchedule::with(['recurrences', 'times'])
                ->whereIn('ServiceID', $serviceIdsInChurch)
                ->get();

            \Log::info('Found schedules', ['count' => $existingSchedules->count()]);

            // Get all occupied time slots for the given date/recurrence
            $occupiedTimes = [];
            foreach ($existingSchedules as $schedule) {
                foreach ($schedule->recurrences as $recurrence) {
                    $matches = $this->recurrenceConflicts($recurrence, $date, $recurrenceType, $targetDayOfWeek, $targetWeekOfMonth);
                    \Log::info('Checking recurrence', [
                        'recurrence_type' => $recurrence->RecurrenceType,
                        'day_of_week' => $recurrence->DayOfWeek,
                        'week_of_month' => $recurrence->WeekOfMonth ?? null,
                        'specific_date' => $recurrence->SpecificDate,
                        'target_day_of_week' => $targetDayOfWeek,
                        'target_week_of_month' => $targetWeekOfMonth,
                        'matches' => $matches
                    ]);
                    if ($matches) {
                        foreach ($schedule->times as $time) {
                            // Extract HH:mm from TIME field (stored as HH:mm:ss)
                            $startRaw = $time->StartTime;
                            $endRaw = $time->EndTime;
                            
                            // Handle if it's already a string or convert from Carbon
                            $startTime = is_string($startRaw) ? substr($startRaw, 0, 5) : substr($startRaw, 0, 5);
                            $endTime = is_string($endRaw) ? substr($endRaw, 0, 5) : substr($endRaw, 0, 5);
                            
                            \Log::info('Processing time', [
                                'start_raw' => $startRaw,
                                'end_raw' => $endRaw,
                                'start_formatted' => $startTime,
                                'end_formatted' => $endTime
                            ]);
                            
                            $occupiedTimes[] = [
                                'start' => $startTime,
                                'end' => $endTime
                            ];
                        }
                    }
                }
            }

            \Log::info('Returning occupied times', ['count' => count($occupiedTimes), 'times' => $occupiedTimes]);

            return response()->json([
                'success' => true,
                'occupied_times' => $occupiedTimes
            ]);
        } catch (\Exception $e) {
            \Log::error('GetAvailableTimeSlots error', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to get available times: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Determine if an existing recurrence conflicts with the target selection across types.
     * Rules:
     * - Weekly blocks any target on the same day-of-week.
     * - MonthlyNth blocks any target on the same day-of-week AND matching week-of-month.
     * - OneTime blocks targets that fall on the same actual date; for Weekly it blocks if the date's day-of-week matches; for MonthlyNth it blocks if both day-of-week and week-of-month match the date.
     */
    private function recurrenceConflicts($existing, $targetDate, $targetType, $targetDayOfWeek, $targetWeekOfMonth)
    {
        $type = $existing->RecurrenceType;

        // Normalize target date info
        $date = $targetDate ? Carbon::parse($targetDate) : null;
        $dateDow = $date ? $date->dayOfWeek : null; // 0=Sun
        $dateWom = $date ? intval(floor(($date->day - 1) / 7) + 1) : null;

        // Existing: Weekly
        if ($type === 'Weekly') {
            $existingDow = (int) $existing->DayOfWeek;
            if ($targetType === 'Weekly') {
                return $existingDow === (int) $targetDayOfWeek;
            }
            if ($targetType === 'OneTime') {
                return $dateDow !== null && $existingDow === $dateDow;
            }
            if ($targetType === 'MonthlyNth') {
                return $existingDow === (int) $targetDayOfWeek; // weekly blocks same weekday
            }
        }

        // Existing: MonthlyNth
        if ($type === 'MonthlyNth') {
            $existingDow = (int) $existing->DayOfWeek;
            $existingWom = (int) ($existing->WeekOfMonth ?? 0);
            if ($targetType === 'MonthlyNth') {
                return $existingDow === (int) $targetDayOfWeek && $existingWom === (int) $targetWeekOfMonth;
            }
            if ($targetType === 'Weekly') {
                return $existingDow === (int) $targetDayOfWeek;
            }
            if ($targetType === 'OneTime') {
                return $dateDow !== null && $dateWom !== null && $existingDow === $dateDow && $existingWom === $dateWom;
            }
        }

        // Existing: OneTime
        if ($type === 'OneTime') {
            $existingDate = substr($existing->SpecificDate, 0, 10);
            if ($targetType === 'OneTime') {
                $newDate = substr($targetDate, 0, 10);
                return $existingDate === $newDate;
            }
            if ($targetType === 'Weekly') {
                $existingDow = Carbon::parse($existingDate)->dayOfWeek;
                return $existingDow === (int) $targetDayOfWeek;
            }
            if ($targetType === 'MonthlyNth') {
                $ex = Carbon::parse($existingDate);
                $existingDow = $ex->dayOfWeek;
                $existingWom = intval(floor(($ex->day - 1) / 7) + 1);
                return $existingDow === (int) $targetDayOfWeek && $existingWom === (int) $targetWeekOfMonth;
            }
        }

        return false;
    }
}
