<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\CertificateConfiguration;
use App\Models\Church;
use App\Models\AppointmentAnswer;
use App\Models\CertificateVerification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class CertificateConfigurationController extends Controller
{
    /**
     * Get certificate configuration for a church
     */
    public function getConfiguration(string $churchName, string $certificateType): JsonResponse
    {
        try {
            // Find the church by name
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

            // Current configuration for this certificate type
            $config = CertificateConfiguration::where('ChurchID', $church->ChurchID)
                                            ->where('CertificateType', $certificateType)
                                            ->with('service')
                                            ->first();

            // All service IDs that are already mapped to any certificate for this church
            $usedServiceIds = CertificateConfiguration::where('ChurchID', $church->ChurchID)
                ->whereNotNull('ServiceID')
                ->pluck('ServiceID')
                ->unique()
                ->values();

            if (!$config) {
                return response()->json([
                    'message' => 'No configuration found for this certificate type.',
                    'config' => null,
                    'used_service_ids' => $usedServiceIds,
                ], 200);
            }

            return response()->json([
                'config' => $config,
                'used_service_ids' => $usedServiceIds,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching certificate configuration.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete certificate configuration for a church & certificate type
     */
    public function deleteConfiguration(string $churchName, string $certificateType): JsonResponse
    {
        try {
            // Find the church by name
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

            $deleted = CertificateConfiguration::where('ChurchID', $church->ChurchID)
                ->where('CertificateType', $certificateType)
                ->delete();

            return response()->json([
                'success' => true,
                'deleted' => $deleted,
                'message' => 'Certificate configuration has been reset and removed.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while deleting certificate configuration.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Save or update certificate configuration
     */
    public function saveConfiguration(Request $request, string $churchName): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'certificate_type' => 'required|string|in:baptism,matrimony,confirmation,firstCommunion',
                'service_id' => 'nullable|integer',
                'field_mappings' => 'nullable|array',
                'is_enabled' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Find the church by name
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

            // Update or create configuration
            $config = CertificateConfiguration::updateOrCreate(
                [
                    'ChurchID' => $church->ChurchID,
                    'CertificateType' => $request->certificate_type
                ],
                [
                    'ServiceID' => $request->service_id,
                    'FieldMappings' => $request->field_mappings,
                    'IsEnabled' => $request->is_enabled,
                ]
            );

            return response()->json([
                'message' => 'Certificate configuration saved successfully.',
                'config' => $config
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while saving certificate configuration.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get certificate field data auto-populated from appointment answers
     */
    public function getCertificateFieldData(Request $request, int $appointmentId, string $certificateType): JsonResponse
    {
        try {
            // Verify appointment exists and get church info
            $appointment = DB::table('Appointment')
                ->join('sacrament_service', 'Appointment.ServiceID', '=', 'sacrament_service.ServiceID')
                ->where('Appointment.AppointmentID', $appointmentId)
                ->select('Appointment.*', 'sacrament_service.ChurchID')
                ->first();

            if (!$appointment) {
                return response()->json([
                    'error' => 'Appointment not found.'
                ], 404);
            }

            // Get church with profile to access Diocese
            $church = Church::with('profile')->find($appointment->ChurchID);

            // Get certificate configuration for this church and certificate type
            $config = CertificateConfiguration::where('ChurchID', $appointment->ChurchID)
                ->where('CertificateType', $certificateType)
                ->where('IsEnabled', true)
                ->first();

            if (!$config || !$config->FieldMappings) {
                return response()->json([
                    'error' => 'No certificate configuration found or field mappings not set.',
                    'field_data' => []
                ], 200);
            }

            // Get all answers for this appointment
            $answers = AppointmentAnswer::where('AppointmentID', $appointmentId)
                ->get()
                ->keyBy('InputFieldID');

            \Log::info('Certificate Config Field Mappings:', ['mappings' => $config->FieldMappings]);
            \Log::info('Available Answers:', ['answers' => $answers->toArray()]);

            // Map certificate fields to their values from appointment answers
            $fieldData = [];
            foreach ($config->FieldMappings as $certificateField => $mappingData) {
                // Extract InputFieldID from mapping data
                $inputFieldId = null;
                
                if (is_array($mappingData) && isset($mappingData['field'])) {
                    // Format: {"field": "56-groom_full_name"} - extract the number
                    $fieldParts = explode('-', $mappingData['field']);
                    $inputFieldId = (int)$fieldParts[0];
                } elseif (is_scalar($mappingData)) {
                    // Direct InputFieldID
                    $inputFieldId = $mappingData;
                }
                
                if ($inputFieldId && isset($answers[$inputFieldId])) {
                    $fieldData[$certificateField] = $answers[$inputFieldId]->AnswerText;
                    \Log::info('Matched field', ['field' => $certificateField, 'inputFieldId' => $inputFieldId, 'value' => $answers[$inputFieldId]->AnswerText]);
                } else {
                    $fieldData[$certificateField] = null;
                    \Log::info('Field not matched', ['field' => $certificateField, 'extracted_id' => $inputFieldId]);
                }
            }

            // Add Diocese from church profile for confirmation certificates
            if ($certificateType === 'confirmation' && $church && $church->profile && $church->profile->Diocese) {
                $fieldData['diocese'] = $church->profile->Diocese;
            }

            return response()->json([
                'success' => true,
                'field_data' => $fieldData,
                'config' => $config
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while fetching certificate field data.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resolve which certificate type should be used for a given appointment
     * based on the certificate_configurations table (ChurchID + ServiceID mapping).
     */
    public function getCertificateTypeForAppointment(int $appointmentId): JsonResponse
    {
        try {
            // Get appointment with its church and service
            $appointment = DB::table('Appointment')
                ->where('AppointmentID', $appointmentId)
                ->select('AppointmentID', 'ChurchID', 'ServiceID')
                ->first();

            if (!$appointment) {
                return response()->json([
                    'error' => 'Appointment not found.'
                ], 404);
            }

            // Find certificate configuration linked to this service
            $config = CertificateConfiguration::where('ChurchID', $appointment->ChurchID)
                ->where('ServiceID', $appointment->ServiceID)
                ->where('IsEnabled', true)
                ->first();

            if (!$config) {
                return response()->json([
                    'error' => 'No enabled certificate configuration found for this service.',
                    'certificate_type' => null
                ], 404);
            }

            return response()->json([
                'success' => true,
                'certificate_type' => $config->CertificateType,
                'config' => $config,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while resolving certificate type for appointment.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a verification record for a certificate
     */
    public function createCertificateVerification(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'appointment_id' => 'required|integer|exists:Appointment,AppointmentID',
                'certificate_type' => 'required|string|in:baptism,matrimony,confirmation,firstCommunion',
                'certificate_data' => 'required|array',
                'recipient_name' => 'required|string|max:255',
                'certificate_date' => 'required|date',
                'issued_by' => 'required|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => 'Validation failed.',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get appointment details to find church
            $appointment = DB::table('Appointment')
                ->join('sacrament_service', 'Appointment.ServiceID', '=', 'sacrament_service.ServiceID')
                ->where('Appointment.AppointmentID', $request->appointment_id)
                ->select('Appointment.*', 'sacrament_service.ChurchID')
                ->first();

            if (!$appointment) {
                return response()->json([
                    'error' => 'Appointment not found.'
                ], 404);
            }

            // Create verification record
            $verification = CertificateVerification::create([
                'AppointmentID' => $request->appointment_id,
                'ChurchID' => $appointment->ChurchID,
                'CertificateType' => $request->certificate_type,
                'VerificationToken' => CertificateVerification::generateToken(),
                'CertificateData' => $request->certificate_data,
                'RecipientName' => $request->recipient_name,
                'CertificateDate' => $request->certificate_date,
                'IssuedBy' => $request->issued_by
            ]);

            return response()->json([
                'success' => true,
                'verification_token' => $verification->VerificationToken,
                'verification_url' => $verification->getVerificationUrl()
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while creating certificate verification.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify a certificate using its token
     */
    public function verifyCertificate(string $token): JsonResponse
    {
        try {
            $verification = CertificateVerification::with(['church.profile', 'appointment'])
                ->where('VerificationToken', $token)
                ->first();

            if (!$verification) {
                return response()->json([
                    'valid' => false,
                    'error' => 'Certificate verification not found.'
                ], 404);
            }

            if (!$verification->isValid()) {
                return response()->json([
                    'valid' => false,
                    'error' => 'Certificate verification is no longer active.'
                ], 410);
            }

            return response()->json([
                'valid' => true,
                'certificate' => [
                    'type' => $verification->CertificateType,
                    'recipient_name' => $verification->RecipientName,
                    'certificate_date' => $verification->CertificateDate->format('F d, Y'),
                    'issued_by' => $verification->IssuedBy,
                    'church_name' => $verification->church->ChurchName,
                    'church_city' => $verification->church->City,
                    'church_province' => $verification->church->Province,
                    'church_street' => $verification->church->Street,
                    'church_profile_image' => $verification->church->profile ? asset('storage/' . $verification->church->profile->ProfilePicturePath) : null,
                    'verified_at' => now()->format('Y-m-d H:i:s'),
                    'certificate_data' => $verification->CertificateData
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'valid' => false,
                'error' => 'An error occurred while verifying certificate.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

}
