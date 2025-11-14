<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\SacramentService;
use App\Models\Church;
use App\Models\ServiceInputField;
use App\Models\ServiceRequirement;
use App\Models\SubService;
use App\Models\SubServiceSchedule;
use App\Models\SubServiceRequirement;
use App\Models\SubSacramentService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class SacramentServiceController extends Controller
{
    /**
     * Get church and its sacrament services by church name
     */
    public function getChurchAndSacramentServices(string $churchName): JsonResponse
    {
        try {
            // Find the church by name (using same logic as RolePermissionController)
            $churchName = preg_replace('/:\d+$/', '', $churchName);
            $name = str_replace('-', ' ', ucwords($churchName, '-'));
            $church = Church::whereRaw('LOWER(ChurchName) = ?', [strtolower($name)])
                           ->where('ChurchStatus', Church::STATUS_ACTIVE)
                           ->first();

            if (!$church) {
                return response()->json([
                    'error' => 'Church not found or is not active.'
                ], 404);
            }

            // Get all sacrament services for this church with sub-services and sub-sacrament services
            $sacramentServices = SacramentService::with(['subServices.schedules', 'subServices.requirements', 'subSacramentServices'])
                                                ->where('ChurchID', $church->ChurchID)
                                                ->orderBy('ServiceName')
                                                ->get();

            return response()->json([
                'ChurchID' => $church->ChurchID,
                'ChurchName' => $church->ChurchName,
                'sacraments' => $sacramentServices
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching church and sacrament services.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific sacrament service by ID
     */
    public function show(int $serviceId, Request $request): JsonResponse
    {
        try {
            $churchId = $request->query('church_id');
            
            if (!$churchId) {
                return response()->json([
                    'error' => 'Church ID is required.'
                ], 400);
            }

            $sacramentService = SacramentService::where('ServiceID', $serviceId)
                                              ->where('ChurchID', $churchId)
                                              ->first();

            if (!$sacramentService) {
                return response()->json([
                    'error' => 'Sacrament service not found.'
                ], 404);
            }

            return response()->json($sacramentService);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching the sacrament service.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new sacrament service
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'church_name' => 'required|string',
                'ServiceName' => 'required|string|max:100',
                'Description' => 'nullable|string',
                'isStaffForm' => 'nullable|boolean',
                'isMass' => 'nullable|boolean',
                'advanceBookingNumber' => 'nullable|integer|min:1|max:12',
                'advanceBookingUnit' => 'nullable|string|in:weeks,months',
                'member_discount_type' => 'nullable|string|in:percentage,fixed',
                'member_discount_value' => 'nullable|numeric|min:0',
                'fee' => 'nullable|numeric|min:0',
                'isMultipleService' => 'nullable|boolean',
                'isCertificateGeneration' => 'nullable|boolean',
                'sub_sacrament_services' => 'nullable|array',
                'sub_sacrament_services.*.name' => 'required_with:sub_sacrament_services|string|max:100',
                'sub_sacrament_services.*.fee' => 'required_with:sub_sacrament_services|numeric|min:0',
                'sub_services' => 'nullable|array',
                'sub_services.*.SubServiceName' => 'required|string|max:100',
                'sub_services.*.Description' => 'nullable|string',
                'sub_services.*.IsActive' => 'nullable|boolean',
                'sub_services.*.schedules' => 'nullable|array',
                'sub_services.*.schedules.*.DayOfWeek' => 'required|string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
                'sub_services.*.schedules.*.StartTime' => 'required|date_format:H:i',
                'sub_services.*.schedules.*.EndTime' => 'required|date_format:H:i',
                'sub_services.*.schedules.*.OccurrenceType' => 'required|string|in:weekly,nth_day_of_month',
                'sub_services.*.schedules.*.OccurrenceValue' => 'nullable|integer|in:1,2,3,4,-1',
                'sub_services.*.requirements' => 'nullable|array',
                'sub_services.*.requirements.*.RequirementName' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Find the church by name (using same logic as RolePermissionController)
            $churchName = preg_replace('/:\d+$/', '', $request->church_name);
            $name = str_replace('-', ' ', ucwords($churchName, '-'));
            $church = Church::whereRaw('LOWER(ChurchName) = ?', [strtolower($name)])
                           ->where('ChurchStatus', Church::STATUS_ACTIVE)
                           ->first();

            if (!$church) {
                return response()->json([
                    'error' => 'Church not found or is not active.'
                ], 404);
            }

            DB::beginTransaction();

            try {
                $sacramentService = SacramentService::create([
                    'ChurchID' => $church->ChurchID,
                    'ServiceName' => $request->ServiceName,
                    'Description' => $request->Description,
                    'isStaffForm' => $request->isStaffForm,
                    'isMass' => $request->isMass,
                    'advanceBookingNumber' => $request->advanceBookingNumber,
                    'advanceBookingUnit' => $request->advanceBookingUnit,
                    'member_discount_type' => $request->member_discount_type,
                    'member_discount_value' => $request->member_discount_value,
                    'fee' => $request->isMultipleService ? 0 : ($request->fee ?? 0),
                    'isMultipleService' => $request->isMultipleService ?? false,
                    'isCertificateGeneration' => $request->isCertificateGeneration ?? false,
                ]);

                // Create sub-sacrament services (variants) if provided
                if ($request->has('sub_sacrament_services') && is_array($request->sub_sacrament_services) && $request->isMultipleService) {
                    foreach ($request->sub_sacrament_services as $variant) {
                        SubSacramentService::create([
                            'ParentServiceID' => $sacramentService->ServiceID,
                            'SubServiceName' => $variant['name'],
                            'fee' => $variant['fee'] ?? 0,
                        ]);
                    }
                }

                // Create sub-services if provided
                if ($request->has('sub_services') && is_array($request->sub_services)) {
                    foreach ($request->sub_services as $subServiceData) {
                        $subService = SubService::create([
                            'ServiceID' => $sacramentService->ServiceID,
                            'SubServiceName' => $subServiceData['SubServiceName'],
                            'Description' => $subServiceData['Description'] ?? null,
                            'IsActive' => $subServiceData['IsActive'] ?? true,
                        ]);

                        // Create schedules for this sub-service
                        if (isset($subServiceData['schedules']) && is_array($subServiceData['schedules'])) {
                            foreach ($subServiceData['schedules'] as $scheduleData) {
                                SubServiceSchedule::create([
                                    'SubServiceID' => $subService->SubServiceID,
                                    'DayOfWeek' => $scheduleData['DayOfWeek'],
                                    'StartTime' => $scheduleData['StartTime'],
                                    'EndTime' => $scheduleData['EndTime'],
                                    'OccurrenceType' => $scheduleData['OccurrenceType'],
                                    'OccurrenceValue' => $scheduleData['OccurrenceValue'] ?? null,
                                ]);
                            }
                        }
                        
                        // Create requirements for this sub-service
                        if (isset($subServiceData['requirements']) && is_array($subServiceData['requirements'])) {
                            foreach ($subServiceData['requirements'] as $index => $requirementData) {
                                SubServiceRequirement::create([
                                    'SubServiceID' => $subService->SubServiceID,
                                    'RequirementName' => $requirementData['RequirementName'],
                                    'SortOrder' => $index,
                                ]);
                            }
                        }
                    }
                }

                DB::commit();

                // Load sub-services and sub-sacrament services for response
                $sacramentService->load(['subServices.schedules', 'subServices.requirements', 'subSacramentServices']);

                return response()->json($sacramentService, 201);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while creating the sacrament service.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a sacrament service
     */
    public function update(Request $request, int $serviceId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'church_name' => 'required|string',
                'ServiceName' => 'required|string|max:100',
                'Description' => 'nullable|string',
                'isStaffForm' => 'nullable|boolean',
                'isMass' => 'nullable|boolean',
                'advanceBookingNumber' => 'nullable|integer|min:1|max:12',
                'advanceBookingUnit' => 'nullable|string|in:weeks,months',
                'member_discount_type' => 'nullable|string|in:percentage,fixed',
                'member_discount_value' => 'nullable|numeric|min:0',
                'fee' => 'nullable|numeric|min:0',
                'isMultipleService' => 'nullable|boolean',
                'isCertificateGeneration' => 'nullable|boolean',
                'sub_sacrament_services' => 'nullable|array',
                'sub_sacrament_services.*.name' => 'required_with:sub_sacrament_services|string|max:100',
                'sub_sacrament_services.*.fee' => 'required_with:sub_sacrament_services|numeric|min:0',
                'sub_services' => 'nullable|array',
                'sub_services.*.SubServiceName' => 'required|string|max:100',
                'sub_services.*.Description' => 'nullable|string',
                'sub_services.*.IsActive' => 'nullable|boolean',
                'sub_services.*.schedules' => 'nullable|array',
                'sub_services.*.schedules.*.DayOfWeek' => 'required|string|in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
                'sub_services.*.schedules.*.StartTime' => 'required|date_format:H:i',
                'sub_services.*.schedules.*.EndTime' => 'required|date_format:H:i',
                'sub_services.*.schedules.*.OccurrenceType' => 'required|string|in:weekly,nth_day_of_month',
                'sub_services.*.schedules.*.OccurrenceValue' => 'nullable|integer|in:1,2,3,4,-1',
                'sub_services.*.requirements' => 'nullable|array',
                'sub_services.*.requirements.*.RequirementName' => 'required|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Find the church by name (using same logic as RolePermissionController)
            $churchName = preg_replace('/:\d+$/', '', $request->church_name);
            $name = str_replace('-', ' ', ucwords($churchName, '-'));
            $church = Church::whereRaw('LOWER(ChurchName) = ?', [strtolower($name)])
                           ->where('ChurchStatus', Church::STATUS_ACTIVE)
                           ->first();

            if (!$church) {
                return response()->json([
                    'error' => 'Church not found or is not active.'
                ], 404);
            }

            $sacramentService = SacramentService::where('ServiceID', $serviceId)
                                              ->where('ChurchID', $church->ChurchID)
                                              ->first();

            if (!$sacramentService) {
                return response()->json([
                    'error' => 'Sacrament service not found.'
                ], 404);
            }

            DB::beginTransaction();

            try {
                $sacramentService->update([
                    'ServiceName' => $request->ServiceName,
                    'Description' => $request->Description,
                    'isStaffForm' => $request->isStaffForm,
                    'isMass' => $request->isMass,
                    'advanceBookingNumber' => $request->advanceBookingNumber,
                    'advanceBookingUnit' => $request->advanceBookingUnit,
                    'member_discount_type' => $request->member_discount_type,
                    'member_discount_value' => $request->member_discount_value,
                    'fee' => $request->isMultipleService ? 0 : ($request->fee ?? 0),
                    'isMultipleService' => $request->isMultipleService ?? false,
                    'isCertificateGeneration' => $request->isCertificateGeneration ?? false,
                ]);

                // Only update variants if explicitly provided in request
                // Otherwise, keep existing variants unchanged
                if ($request->has('sub_sacrament_services') && is_array($request->sub_sacrament_services) && $request->isMultipleService) {
                    $existingVariants = SubSacramentService::where('ParentServiceID', $serviceId)->get()->keyBy('SubSacramentServiceID');
                    $processedVariantIds = [];

                    foreach ($request->sub_sacrament_services as $variant) {
                        $variantData = [
                            'ParentServiceID' => $sacramentService->ServiceID,
                            'SubServiceName' => $variant['name'],
                            'fee' => $variant['fee'] ?? 0,
                        ];

                        if (isset($variant['id']) && isset($existingVariants[$variant['id']])) {
                            // Update existing variant
                            $existingVariants[$variant['id']]->update($variantData);
                            $processedVariantIds[] = $variant['id'];
                        } else {
                            // Create new variant
                            $newVariant = SubSacramentService::create($variantData);
                            $processedVariantIds[] = $newVariant->SubSacramentServiceID;
                        }
                    }

                    // Only delete variants that were explicitly removed
                    $variantsToDelete = $existingVariants->keys()->diff($processedVariantIds);
                    if ($variantsToDelete->isNotEmpty()) {
                        SubSacramentService::whereIn('SubSacramentServiceID', $variantsToDelete)->delete();
                    }
                } elseif (!$request->isMultipleService) {
                    // If service changed from multiple to single, delete all variants
                    SubSacramentService::where('ParentServiceID', $serviceId)->delete();
                }
                // If isMultipleService but no sub_sacrament_services in request, keep existing variants

                // Upsert sub-services so their IDs (and related submission state) are preserved
                $existingSubServices = SubService::where('ServiceID', $serviceId)
                    ->get()
                    ->keyBy('SubServiceID');
                $processedSubServiceIds = [];

                if ($request->has('sub_services') && is_array($request->sub_services)) {
                    foreach ($request->sub_services as $subServiceData) {
                        $subServiceId = $subServiceData['id'] ?? null;

                        $subServicePayload = [
                            'ServiceID' => $sacramentService->ServiceID,
                            'SubServiceName' => $subServiceData['SubServiceName'],
                            'Description' => $subServiceData['Description'] ?? null,
                            'IsActive' => $subServiceData['IsActive'] ?? true,
                        ];

                        if ($subServiceId && isset($existingSubServices[$subServiceId])) {
                            // Update existing sub-service
                            $subService = $existingSubServices[$subServiceId];
                            $subService->update($subServicePayload);
                        } else {
                            // Create new sub-service
                            $subService = SubService::create($subServicePayload);
                            $subServiceId = $subService->SubServiceID;
                        }

                        $processedSubServiceIds[] = $subServiceId;

                        // Replace schedules for this sub-service (no submission state tied to schedules)
                        SubServiceSchedule::where('SubServiceID', $subServiceId)->delete();
                        if (isset($subServiceData['schedules']) && is_array($subServiceData['schedules'])) {
                            foreach ($subServiceData['schedules'] as $scheduleData) {
                                SubServiceSchedule::create([
                                    'SubServiceID' => $subServiceId,
                                    'DayOfWeek' => $scheduleData['DayOfWeek'],
                                    'StartTime' => $scheduleData['StartTime'],
                                    'EndTime' => $scheduleData['EndTime'],
                                    'OccurrenceType' => $scheduleData['OccurrenceType'],
                                    'OccurrenceValue' => $scheduleData['OccurrenceValue'] ?? null,
                                ]);
                            }
                        }

                        // Upsert requirements for this sub-service
                        $existingSubReqs = SubServiceRequirement::where('SubServiceID', $subServiceId)
                            ->orderBy('SortOrder')
                            ->get()
                            ->keyBy('RequirementID');
                        $processedReqIds = [];

                        if (isset($subServiceData['requirements']) && is_array($subServiceData['requirements'])) {
                            foreach ($subServiceData['requirements'] as $index => $requirementData) {
                                $reqId = $requirementData['id'] ?? null;

                                $payload = [
                                    'SubServiceID' => $subServiceId,
                                    'RequirementName' => $requirementData['RequirementName'],
                                    'SortOrder' => $index,
                                ];

                                if ($reqId && isset($existingSubReqs[$reqId])) {
                                    // Update existing requirement, keep ID so submissions stay linked
                                    $existingSubReqs[$reqId]->update($payload);
                                    $processedReqIds[] = $reqId;
                                } else {
                                    // Create new requirement
                                    $newReq = SubServiceRequirement::create($payload);
                                    $processedReqIds[] = $newReq->RequirementID;
                                }
                            }
                        }

                        // Delete removed sub-service requirements
                        $reqsToDelete = $existingSubReqs->keys()->diff($processedReqIds);
                        if ($reqsToDelete->isNotEmpty()) {
                            SubServiceRequirement::where('SubServiceID', $subServiceId)
                                ->whereIn('RequirementID', $reqsToDelete)
                                ->delete();
                        }
                    }
                }

                // Delete sub-services that were removed from the request
                $subServicesToDelete = $existingSubServices->keys()->diff($processedSubServiceIds);
                if ($subServicesToDelete->isNotEmpty()) {
                    SubService::where('ServiceID', $serviceId)
                        ->whereIn('SubServiceID', $subServicesToDelete)
                        ->delete();
                }

                DB::commit();

                // Load sub-services and sub-sacrament services for response
                $sacramentService->load(['subServices.schedules', 'subServices.requirements', 'subSacramentServices']);

                return response()->json($sacramentService);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while updating the sacrament service.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a sacrament service
     */
    public function destroy(int $serviceId, Request $request): JsonResponse
    {
        try {
            $churchId = $request->query('church_id');
            
            if (!$churchId) {
                return response()->json([
                    'error' => 'Church ID is required.'
                ], 400);
            }

            $sacramentService = SacramentService::where('ServiceID', $serviceId)
                                              ->where('ChurchID', $churchId)
                                              ->first();

            if (!$sacramentService) {
                return response()->json([
                    'error' => 'Sacrament service not found.'
                ], 404);
            }

            $sacramentService->delete();

            return response()->json([
                'message' => 'Sacrament service deleted successfully.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while deleting the sacrament service.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save form configuration for a sacrament service
     */
    public function saveFormConfiguration(Request $request, int $serviceId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'service_id' => 'required|integer',
                'form_elements' => 'required|array',
                'requirements' => 'array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verify service exists
            $sacramentService = SacramentService::where('ServiceID', $serviceId)->first();
            if (!$sacramentService) {
                return response()->json([
                    'error' => 'Sacrament service not found.'
                ], 404);
            }

            DB::beginTransaction();

            try {
                // Get existing input fields to preserve InputFieldIDs
                $existingFields = ServiceInputField::where('ServiceID', $serviceId)->get()->keyBy('element_id');
                $processedElementIds = [];

                // NOTE: Do NOT delete existing requirements here.
                // Requirement IDs are referenced by appointment_requirement_submissions,
                // so we must preserve them to keep "Submitted" state for past appointments.

                // Save form elements, preserving existing InputFieldIDs where possible
                foreach ($request->form_elements as $index => $element) {
                    $inputType = $element['type'];
                    
                    // Map frontend types to backend types
                    if ($inputType === 'tel') {
                        $inputType = 'phone';
                    }

                    $elementId = $element['elementId'] ?? null;
                    $fieldData = [
                        'ServiceID' => $serviceId,
                        'Label' => $element['label'] ?? '',
                        'InputType' => $inputType,
                        'IsRequired' => $element['required'] ?? false,
                        'Options' => $element['options'] ?? null,
                        'Placeholder' => $element['placeholder'] ?? '',
                        'HelpText' => null,
                        'SortOrder' => $index,
                        'element_id' => $elementId,
                        'x_position' => $element['properties']['x'] ?? null,
                        'y_position' => $element['properties']['y'] ?? null,
                        'width' => $element['properties']['width'] ?? null,
                        'height' => $element['properties']['height'] ?? null,
                        'z_index' => 1,
                        'text_content' => $element['properties']['text'] ?? null,
                        'text_size' => $element['properties']['size'] ?? null,
                        'text_align' => $element['properties']['align'] ?? 'left',
                        'text_color' => $element['properties']['color'] ?? '#000000',
                        'textarea_rows' => $element['properties']['rows'] ?? null,
                    ];

                    // If element_id exists and we have an existing field, update it to preserve InputFieldID
                    if ($elementId && isset($existingFields[$elementId])) {
                        $existingFields[$elementId]->update($fieldData);
                        $processedElementIds[] = $elementId;
                    } else {
                        // Create new field for new elements
                        ServiceInputField::create($fieldData);
                        if ($elementId) {
                            $processedElementIds[] = $elementId;
                        }
                    }
                }

                // Delete fields that are no longer in the configuration
                $elementsToDelete = $existingFields->keys()->diff($processedElementIds)->filter();
                if ($elementsToDelete->isNotEmpty()) {
                    ServiceInputField::where('ServiceID', $serviceId)
                        ->whereIn('element_id', $elementsToDelete->toArray())
                        ->delete();
                }

                // Also delete fields that have null element_id and are not in the new configuration
                // (these might be orphaned fields from before element_id was implemented)
                $hasNullElementIds = collect($request->form_elements)->pluck('elementId')->contains(null);
                if (!$hasNullElementIds) {
                    ServiceInputField::where('ServiceID', $serviceId)
                        ->whereNull('element_id')
                        ->delete();
                }

                // Save requirements - preserve existing RequirementID when possible
                $existingRequirements = ServiceRequirement::where('ServiceID', $serviceId)
                    ->get()
                    ->keyBy('RequirementID');
                $processedRequirementIds = [];

                if (isset($request->requirements) && is_array($request->requirements)) {
                    foreach ($request->requirements as $index => $requirement) {
                        $requirementId = $requirement['id'] ?? null;

                        $data = [
                            'ServiceID' => $serviceId,
                            'Description' => $requirement['description'],
                            'isNeeded' => $requirement['is_needed'] ?? true,
                            'RequirementType' => 'custom',
                            'RequirementData' => null,
                            'SortOrder' => $index,
                        ];

                        if ($requirementId && isset($existingRequirements[$requirementId])) {
                            // Update existing requirement, keep ID so submissions stay linked
                            $existingRequirements[$requirementId]->update($data);
                            $processedRequirementIds[] = $requirementId;
                        } else {
                            // Create new requirement
                            $newReq = ServiceRequirement::create($data);
                            $processedRequirementIds[] = $newReq->RequirementID;
                        }
                    }
                }

                // Delete requirements that were removed from the configuration
                $requirementsToDelete = $existingRequirements->keys()->diff($processedRequirementIds);
                if ($requirementsToDelete->isNotEmpty()) {
                    ServiceRequirement::where('ServiceID', $serviceId)
                        ->whereIn('RequirementID', $requirementsToDelete)
                        ->delete();
                }

                DB::commit();

                return response()->json([
                    'message' => 'Form configuration saved successfully.',
                    'service_id' => $serviceId
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while saving form configuration.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get sacrament services for a specific church (public endpoint for map)
     */
    public function getPublicChurchServices(int $churchId): JsonResponse
    {
        try {
            // Verify church exists and is active and public
            $church = Church::where('ChurchID', $churchId)
                          ->where('ChurchStatus', Church::STATUS_ACTIVE)
                          ->where('IsPublic', true)
                          ->first();

            if (!$church) {
                return response()->json([
                    'error' => 'Church not found or is not available.'
                ], 404);
            }

            // Get all sacrament services for this church
            $sacramentServices = SacramentService::where('ChurchID', $churchId)
                                                ->orderBy('ServiceName')
                                                ->get([
                                                    'ServiceID', 
                                                    'ServiceName', 
                                                    'Description', 
                                                    'isStaffForm',
                                                    'isMass',
                                                    'fee',
                                                    'advanceBookingNumber',
                                                    'advanceBookingUnit',
                                                    'member_discount_type',
                                                    'member_discount_value'
                                                ]);

            return response()->json([
                'church' => [
                    'ChurchID' => $church->ChurchID,
                    'ChurchName' => $church->ChurchName,
                ],
                'services' => $sacramentServices
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching sacrament services.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get schedules for a specific sacrament service (public endpoint)
     */
    public function getPublicServiceSchedules(int $serviceId): JsonResponse
    {
        try {
            // Verify service exists and church is public
            $service = SacramentService::with('church')
                ->where('ServiceID', $serviceId)
                ->first();

            if (!$service) {
                return response()->json([
                    'error' => 'Sacrament service not found.'
                ], 404);
            }

            // Check if church is public and active
            if (!$service->church || 
                $service->church->ChurchStatus !== Church::STATUS_ACTIVE || 
                !$service->church->IsPublic) {
                return response()->json([
                    'error' => 'Service not available.'
                ], 404);
            }

            // Get schedules with related data (don't filter by RemainingSlot here)
            $schedules = \App\Models\ServiceSchedule::with(['recurrences', 'times', 'subSacramentService'])
                ->where('ServiceID', $serviceId)
                ->orderBy('StartDate', 'asc')
                ->get()
                ->map(function ($schedule) {
                    // Check if schedule has recurrences to determine if it's recurring
                    $isRecurring = $schedule->recurrences && $schedule->recurrences->count() > 0;
                    $recurrencePattern = null;
                    
                    if ($isRecurring) {
                        // Build recurrence pattern string from recurrences
                        $recurrence = $schedule->recurrences->first();
                        if ($recurrence) {
                            // Use the model's built-in description method
                            $recurrencePattern = $recurrence->getDescription();
                        }
                    }
                    
                    // Frontend will calculate date-specific availability using dynamic slot calculation
                    return [
                        'ScheduleID' => $schedule->ScheduleID,
                        'SubSacramentServiceID' => $schedule->SubSacramentServiceID,
                        'StartDate' => $schedule->StartDate,
                        'EndDate' => $schedule->EndDate,
                        'SlotCapacity' => $schedule->SlotCapacity,
                        'IsRecurring' => $isRecurring,
                        'RecurrencePattern' => $recurrencePattern,
                        'recurrences' => $schedule->recurrences,
                        'times' => $schedule->times,
                        'sub_sacrament_service' => $schedule->subSacramentService
                    ];
                });

            return response()->json([
                'service' => [
                    'ServiceID' => $service->ServiceID,
                    'ServiceName' => $service->ServiceName,
                    'Description' => $service->Description,
                    'isStaffForm' => $service->isStaffForm,
                    'isMass' => $service->isMass,
                    'fee' => $service->fee,
                    'advanceBookingNumber' => $service->advanceBookingNumber,
                    'advanceBookingUnit' => $service->advanceBookingUnit,
                ],
                'schedules' => $schedules
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching schedules.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get remaining slots for specific schedule times on a specific date
     */
    public function getScheduleRemainingSlots(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'schedule_id' => 'required|integer',
                'date' => 'required|date',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            $scheduleId = $request->schedule_id;
            $date = $request->date;

            // Get the schedule with its times and their date slots using Eloquent relationships
            $schedule = \App\Models\ServiceSchedule::with([
                'times.dateSlots' => function ($query) use ($date) {
                    $query->whereDate('SlotDate', $date);
                }
            ])->where('ScheduleID', $scheduleId)->first();

            if (!$schedule) {
                return response()->json([
                    'error' => 'Schedule not found.'
                ], 404);
            }

            // Process time slots using Eloquent relationships
            $timeSlots = $schedule->times->map(function ($scheduleTime) use ($schedule) {
                // Get the date slot for this time (should be only one for the specific date)
                $dateSlot = $scheduleTime->dateSlots->first();
                
                if ($dateSlot) {
                    return [
                        'ScheduleTimeID' => $scheduleTime->ScheduleTimeID,
                        'StartTime' => $scheduleTime->StartTime,
                        'EndTime' => $scheduleTime->EndTime,
                        'RemainingSlots' => $dateSlot->RemainingSlots,
                        'SlotCapacity' => $schedule->SlotCapacity,
                        'BookedCount' => $schedule->SlotCapacity - $dateSlot->RemainingSlots
                    ];
                }
                
                return null; // No date slot found for this time/date combination
            })->filter()->values(); // Remove null entries and reindex

            $totalRemainingSlots = $timeSlots->sum('RemainingSlots');

            return response()->json([
                'schedule_id' => $scheduleId,
                'date' => $date,
                'slot_capacity' => $schedule->SlotCapacity,
                'total_remaining_slots' => $totalRemainingSlots,
                'time_slots' => $timeSlots->toArray()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching remaining slots.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get form configuration for a sacrament service
     */
    public function getFormConfiguration(int $serviceId): JsonResponse
    {
        try {
            // Verify service exists
            $sacramentService = SacramentService::where('ServiceID', $serviceId)->first();
            if (!$sacramentService) {
                return response()->json([
                    'error' => 'Sacrament service not found.'
                ], 404);
            }

            // Get form elements
            $formElements = ServiceInputField::where('ServiceID', $serviceId)
                                           ->orderBy('SortOrder')
                                           ->get()
                                           ->map(function ($field) {
                                               $inputType = $field->InputType;
                                               
                                               // Map backend types to frontend types
                                               if ($inputType === 'phone') {
                                                   $inputType = 'tel';
                                               }
                                               
                                               return [
                                                   'InputFieldID' => $field->InputFieldID,
                                                   'elementId' => $field->element_id,
                                                   'type' => $inputType,
                                                   'label' => $field->Label,
                                                   'placeholder' => $field->Placeholder,
                                                   'required' => $field->IsRequired,
                                                   'options' => $field->Options ?? [],
                                                   'properties' => [
                                                       'x' => $field->x_position,
                                                       'y' => $field->y_position,
                                                       'width' => $field->width,
                                                       'height' => $field->height,
                                                       'text' => $field->text_content,
                                                       'size' => $field->text_size,
                                                       'align' => $field->text_align,
                                                       'color' => $field->text_color,
                                                       'rows' => $field->textarea_rows,
                                                       'elementId' => $field->element_id,
                                                   ]
                                               ];
                                           });

            // Get requirements
            $requirements = ServiceRequirement::where('ServiceID', $serviceId)
                                             ->orderBy('SortOrder')
                                             ->get()
                                             ->map(function ($requirement) {
                                                 return [
                                                     'id' => $requirement->RequirementID,
                                                     'description' => $requirement->Description,
                                                     'is_needed' => $requirement->isNeeded,
                                                 ];
                                             });

            // Get sub-services with their requirements
            $subServices = SubService::where('ServiceID', $serviceId)
                                    ->where('IsActive', true)
                                    ->orderBy('SubServiceID')
                                    ->get()
                                    ->map(function ($subService) {
                                        // Get requirements for this sub-service
                                        $subServiceRequirements = SubServiceRequirement::where('SubServiceID', $subService->SubServiceID)
                                            ->orderBy('SortOrder')
                                            ->get()
                                            ->map(function ($req) {
                                                return [
                                                    'name' => $req->RequirementName,
                                                    'is_needed' => $req->isNeeded ?? true,
                                                ];
                                            });

                                        return [
                                            'id' => $subService->SubServiceID,
                                            'name' => $subService->SubServiceName,
                                            'description' => $subService->Description,
                                            'requirements' => $subServiceRequirements
                                        ];
                                    });

            return response()->json([
                'form_elements' => $formElements,
                'requirements' => $requirements,
                'sub_services' => $subServices
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching form configuration.',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
