<?php

namespace App\Http\Controllers;

use App\Models\Church;
use App\Models\ChurchOwnerDocument;
use App\Models\ChurchProfile;
use App\Models\ChurchSubscription;
use App\Models\ChurchPaymentConfig;
use App\Models\ChurchRole;
use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\SupabaseStorageService;
use App\Models\Notification;
use App\Models\UserProfile;
use App\Events\NotificationCreated;

class ChurchController extends Controller
{
    /**
     * Store a newly created church in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        // Validate the request
        $validated = $request->validate([
            'ChurchName' => 'required|string|max:255|unique:Church,ChurchName',
            'Latitude' => 'required|numeric|between:-90,90',
            'Longitude' => 'required|numeric|between:-180,180',
            'Street' => 'nullable|string|max:255',
            'City' => 'required|string|max:255',
            'Province' => 'required|string|max:255',
            'Description' => 'nullable|string|min:10|max:1000',
            'ParishDetails' => 'nullable|string|min:10|max:1000',
            'Diocese' => 'required|string|max:255',
            'ContactNumber' => 'nullable|string|max:50',
            'Email' => 'nullable|email|max:255',
            'ProfilePicture' => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'SEC' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
            'BIR' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
            'BarangayPermit' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
            'AuthorizationLetter' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
            'RepresentativeID' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
        ], [
            'ChurchName.unique' => 'A church with this name already exists.',
            'ChurchName.required' => 'Church name is required.',
            'Latitude.required' => 'Latitude is required.',
            'Longitude.required' => 'Longitude is required.',
            'City.required' => 'City is required.',
            'Province.required' => 'Province is required.',
            'Description.min' => 'Description must be at least 10 characters.',
            'ParishDetails.min' => 'Parish details must be at least 10 characters.',
            'ProfilePicture.image' => 'Profile picture must be an image (JPEG, PNG, or JPG).',
            'ProfilePicture.max' => 'Profile picture must not exceed 2MB.',
        ]);

        // Get authenticated user
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'User not authenticated'], 401);
        }

        // Verify user has the Church Owner role
        if ($user->profile->system_role_id != 2) { // Assuming 2 is Church Owner
            return response()->json(['error' => 'Only church owners can register churches'], 403);
        }

        // Check for active subscription
        $activeSubscription = ChurchSubscription::where('UserID', $user->id)
            ->where('Status', 'Active')
            ->where('EndDate', '>=', now())
            ->first();

        if (!$activeSubscription) {
            return response()->json([
                'error' => 'No active subscription',
                'message' => 'You need an active subscription to register a church.',
                'action' => 'Please visit the subscription plans page to purchase a subscription.'
            ], 403);
        }

        // Check if user has reached their church limit
        $plan = $activeSubscription->plan;
        $churchCount = $user->churches()->count();

        if ($churchCount >= $plan->MaxChurchesAllowed) {
            return response()->json([
                'error' => 'Maximum churches reached for your plan',
                'message' => "Your current plan allows a maximum of {$plan->MaxChurchesAllowed} churches.",
                'action' => 'Please upgrade your subscription to register more churches.'
            ], 403);
        }

        // Start a transaction to ensure data consistency
        return DB::transaction(function () use ($request, $validated, $user) {
            try {
                // Create the church record
                $church = Church::create([
                    'ChurchName' => $validated['ChurchName'],
                    'IsPublic' => false, // Churches start as private until approved
                    'Latitude' => $validated['Latitude'],
                    'Longitude' => $validated['Longitude'],
                    'Street' => $validated['Street'] ?? null,
                    'City' => $validated['City'],
                    'Province' => $validated['Province'],
                    'ChurchStatus' => Church::STATUS_PENDING, // All new churches start as pending
                    'user_id' => $user->id,
                ]);

                // Create church profile with information
                $profileData = [
                    'ChurchID' => $church->ChurchID,
                    'Description' => isset($validated['Description']) ? $validated['Description'] : null,
                    'ParishDetails' => isset($validated['ParishDetails']) ? $validated['ParishDetails'] : null,
                    'Diocese' => isset($validated['Diocese']) ? $validated['Diocese'] : null,
                    'ContactNumber' => isset($validated['ContactNumber']) ? $validated['ContactNumber'] : null,
                    'Email' => isset($validated['Email']) ? $validated['Email'] : null,
                ];

                // Handle profile picture
                if ($request->hasFile('ProfilePicture')) {
                    $file = $request->file('ProfilePicture');
                    $filename = $church->ChurchID . '_ProfilePicture_' . time() . '.' . $file->getClientOriginalExtension();
                    
                    // Store the file in Supabase
                    $supabase = new SupabaseStorageService();
                    $uploadResult = $supabase->upload(
                        'profile-pictures/' . $filename,
                        file_get_contents($file->path()),
                        $file->getMimeType()
                    );
                    
                    if ($uploadResult['success']) {
                        // Update profile with path
                        $profileData['ProfilePicturePath'] = 'profile-pictures/' . $filename;
                    } else {
                        Log::error('Failed to upload profile picture to Supabase: ' . ($uploadResult['error'] ?? 'Unknown error'));
                    }
                }

                // Create the church profile
                $profile = ChurchProfile::create($profileData);

                // Define document types with their display names
                $documentTypes = [
                    'SEC' => 'SEC Registration',
                    'BIR' => 'BIR Certificate',
                    'BarangayPermit' => 'Barangay Permit',
                    'AuthorizationLetter' => 'Authorization Letter',
                    'RepresentativeID' => 'Representative Government ID',
                ];

                // Create document storage directory if it doesn't exist
                $storageDir = storage_path('app/church_documents');
                if (!file_exists($storageDir)) {
                    mkdir($storageDir, 0755, true);
                }

                // Track uploaded documents
                $uploadedDocuments = [];

                // Process each document
                $supabase = new SupabaseStorageService();
                foreach ($documentTypes as $inputName => $documentType) {
                    if ($request->hasFile($inputName)) {
                        $file = $request->file($inputName);
                        $originalName = $file->getClientOriginalName();
                        $extension = $file->getClientOriginalExtension();
                        
                        // Create a unique filename that preserves the original name
                        $filename = $church->ChurchID . '_' . str_replace(' ', '_', $documentType) . '_' . time() . '.' . $extension;
                        $storagePath = 'documents/' . $filename;
                        
                        // Store the file in Supabase
                        $uploadResult = $supabase->upload(
                            $storagePath,
                            file_get_contents($file->path()),
                            $file->getMimeType()
                        );
                        
                        if ($uploadResult['success']) {
                            // Create document record
                            $document = ChurchOwnerDocument::create([
                                'ChurchID' => $church->ChurchID,
                                'DocumentType' => $documentType,
                                'DocumentPath' => $storagePath,
                                'SubmissionDate' => now(),
                            ]);
                            
                            $uploadedDocuments[] = [
                                'DocumentID' => $document->DocumentID,
                                'DocumentType' => $documentType,
                                'OriginalFilename' => $originalName,
                                'SubmissionDate' => $document->SubmissionDate->format('Y-m-d H:i:s')
                            ];
                        } else {
                            Log::error('Failed to upload document to Supabase: ' . ($uploadResult['error'] ?? 'Unknown error'));
                        }
                    }
                }

                // Create Admin role with all permissions
                $adminRole = ChurchRole::create([
                    'ChurchID' => $church->ChurchID,
                    'RoleName' => 'Admin'
                ]);

                // Get all permissions
                $allPermissions = Permission::all();

                // Attach all permissions to the admin role
                $adminRole->permissions()->attach($allPermissions->pluck('PermissionID'));

                // Notify all system admins about the new church application
                $systemAdmins = UserProfile::where('system_role_id', 1)->pluck('user_id');
                
                $ownerName = trim(($user->profile->first_name ?? '') . ' ' . ($user->profile->last_name ?? '')) ?: $user->email;
                
                foreach ($systemAdmins as $adminId) {
                    $notification = Notification::create([
                        'user_id' => $adminId,
                        'type' => 'church_application',
                        'title' => 'New Church Registration',
                        'message' => "{$ownerName} has submitted a new church registration for {$church->ChurchName}.",
                        'data' => [
                            'church_id' => $church->ChurchID,
                            'church_name' => $church->ChurchName,
                            'owner_name' => $ownerName,
                            'owner_email' => $user->email,
                            'status' => 'pending',
                            'link' => "/church-ms-taupe.vercel.app/application",
                        ],
                        'is_read' => false,
                    ]);
                    
                    // Broadcast to each admin's private channel
                    broadcast(new NotificationCreated($adminId, $notification));
                }

                // Return successful response with comprehensive information
                return response()->json([
                    'status' => 'success',
                    'message' => 'Church registered successfully and is awaiting admin approval.',
                    'church' => [
                        'ChurchID' => $church->ChurchID,
                        'ChurchName' => $church->ChurchName,
                        'ChurchStatus' => $church->ChurchStatus,
                        'IsPublic' => $church->IsPublic,
                        'Location' => [
                            'Latitude' => $church->Latitude,
                            'Longitude' => $church->Longitude,
                            'Street' => $church->Street,
                            'City' => $church->City,
                            'Province' => $church->Province,
                        ],
                        'Profile' => [
                            'Description' => $profile->Description,
                            'ParishDetails' => $profile->ParishDetails,
                            'HasProfilePicture' => !empty($profile->ProfilePictureData)
                        ],
                        'Documents' => $uploadedDocuments,
                        'CreatedAt' => $church->created_at->format('Y-m-d H:i:s')
                    ],
                    'next_steps' => 'Your church registration will be reviewed by an administrator. You will be notified once it is approved.'
                ], 201);
                
            } catch (\Exception $e) {
                // Log the error
                Log::error('Church registration error: ' . $e->getMessage());
                
                // Return error response
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to register church due to an internal error.',
                    'error' => config('app.debug') ? $e->getMessage() : 'Server error'
                ], 500);
            }
        });
    }

    public function showOwnedChurches(Request $request)
    {
        $user = Auth::user();
        $churches = $user->churches()->with(['profile', 'documents'])->get();

        return response()->json([
            'churches' => $churches->map(function ($church) {
                // Check if church has active payment configuration
                $paymentConfig = ChurchPaymentConfig::where('church_id', $church->ChurchID)
                    ->where('provider', 'paymongo')
                    ->where('is_active', true)
                    ->first();
                
                $hasPaymentConfig = $paymentConfig && $paymentConfig->isComplete();
                
                return [
                    'ChurchID' => $church->ChurchID,
                    'ChurchName' => $church->ChurchName,
                    'ChurchStatus' => $church->ChurchStatus,
                    'IsPublic' => $church->IsPublic,
                    'HasPaymentConfig' => $hasPaymentConfig,
                    'Location' => [
                        'Latitude' => $church->Latitude,
                        'Longitude' => $church->Longitude,
                    ],
                    'ChurchProfile' => $church->profile ? [
                        'Description' => $church->profile->Description,
                        'ParishDetails' => $church->profile->ParishDetails,
                        'ProfilePictureUrl' => $church->profile->ProfilePicturePath 
                            ? url('/api/churches/' . $church->ChurchID . '/profile-picture')
                            : null,
                    ] : null,
                    'DocumentCount' => $church->documents->count(),
                ];
            }),
        ]);
    }

    public function index(Request $request)
    {
        $churches = Church::with(['owner.profile', 'profile', 'documents'])->get();

        return response()->json([
            'churches' => $churches->map(function ($church) {
                $ownerProfile = $church->owner && $church->owner->profile 
                    ? $church->owner->profile 
                    : null;
                
                return [
                    'ChurchID' => $church->ChurchID,
                    'ChurchName' => $church->ChurchName,
                    'ChurchStatus' => $church->ChurchStatus,
                    'IsPublic' => $church->IsPublic,
                    'Location' => [
                        'Latitude' => $church->Latitude,
                        'Longitude' => $church->Longitude,
                    ],
                    'Owner' => $church->owner ? $church->owner->email : 'N/A',
                    'OwnerProfile' => $ownerProfile ? [
                        'FirstName' => $ownerProfile->first_name,
                        'MiddleName' => $ownerProfile->middle_name,
                        'LastName' => $ownerProfile->last_name,
                        'FullName' => trim(($ownerProfile->first_name ?? '') . ' ' . 
                                        ($ownerProfile->middle_name ?? '') . ' ' . 
                                        ($ownerProfile->last_name ?? ''))
                    ] : null,
                    'ChurchProfile' => $church->profile ? [
                        'Description' => $church->profile->Description,
                        'ParishDetails' => $church->profile->ParishDetails,
                        'ProfilePictureUrl' => $church->profile->ProfilePicturePath 
                            ? url('/api/churches/' . $church->ChurchID . '/profile-picture')
                            : null,
                    ] : null,
                    'DocumentCount' => $church->documents->count(),
                ];
            }),
        ]);
    }

    public function updateStatus(Request $request, $churchId)
    {
        $validated = $request->validate([
            'ChurchStatus' => 'required|in:' . implode(',', Church::$validStatuses),
        ]);

        $church = Church::findOrFail($churchId);
        $oldStatus = $church->ChurchStatus;
        $church->update(['ChurchStatus' => $validated['ChurchStatus']]);

        // Notify church owner about status change
        if ($church->user_id && $oldStatus !== $validated['ChurchStatus']) {
            $statusMessages = [
                Church::STATUS_ACTIVE => 'Your church registration has been approved! You can now configure your church settings.',
                Church::STATUS_REJECTED => 'Your church registration has been rejected. Please review the feedback and resubmit your application.',
                Church::STATUS_DISABLED => 'Your church has been disabled by the system administrator.',
            ];
            
            $message = $statusMessages[$validated['ChurchStatus']] ?? "Your church status has been updated to {$validated['ChurchStatus']}.";
            
            $notification = Notification::create([
                'user_id' => $church->user_id,
                'type' => 'church_status_changed',
                'title' => 'Church Application Status Updated',
                'message' => $message,
                'data' => [
                    'church_id' => $church->ChurchID,
                    'church_name' => $church->ChurchName,
                    'old_status' => $oldStatus,
                    'new_status' => $validated['ChurchStatus'],
                    'link' => '/church-owner/churches',
                ],
                'is_read' => false,
            ]);
            
            // Broadcast to church owner
            broadcast(new NotificationCreated($church->user_id, $notification));
        }

        return response()->json([
            'message' => 'Church status updated.',
            'church' => $church,
        ]);
    }

    public function reviewDocuments(Request $request, $churchId)
    {
        try {
            // Find church
            $church = Church::find($churchId);
            if (!$church) {
                return response()->json(['error' => 'Church not found'], 404);
            }

            // Get documents
            $documents = ChurchOwnerDocument::where('ChurchID', $churchId)->get();

            $supabase = new SupabaseStorageService();
            $documentData = $documents->map(function ($document) use ($supabase) {
                $filePath = $document->DocumentPath ?? '';
                $fileUrl = $filePath ? $supabase->getPublicUrl($filePath) : null;

                return [
                    'DocumentID' => $document->DocumentID,
                    'DocumentType' => $document->DocumentType,
                    'DocumentPath' => $document->DocumentPath,
                    'SubmissionDate' => $document->SubmissionDate,
                    'FileExists' => !empty($filePath),
                    'DocumentUrl' => url('/api/documents/' . $document->DocumentID)
                ];
            })->toArray();

            // Get church profile and owner information
            $church->load(['profile', 'owner.profile']);
            
            $ownerProfile = $church->owner && $church->owner->profile 
                ? $church->owner->profile 
                : null;

            return response()->json([
                'church' => [
                    'ChurchID' => $church->ChurchID,
                    'ChurchName' => $church->ChurchName,
                    'ChurchStatus' => $church->ChurchStatus,
                    'IsPublic' => $church->IsPublic,
                    'Location' => [
                        'Latitude' => $church->Latitude,
                        'Longitude' => $church->Longitude,
                    ],
                    'Owner' => $church->owner ? $church->owner->email : 'N/A',
                    'OwnerProfile' => $ownerProfile ? [
                        'FirstName' => $ownerProfile->first_name,
                        'MiddleName' => $ownerProfile->middle_name,
                        'LastName' => $ownerProfile->last_name,
                        'FullName' => trim(($ownerProfile->first_name ?? '') . ' ' . 
                                        ($ownerProfile->middle_name ?? '') . ' ' . 
                                        ($ownerProfile->last_name ?? ''))
                    ] : null,
                    'ChurchProfile' => $church->profile ? [
                        'Description' => $church->profile->Description,
                        'ParishDetails' => $church->profile->ParishDetails,
                        'ProfilePictureUrl' => $church->profile->ProfilePicturePath 
                            ? url('/api/churches/' . $church->ChurchID . '/profile-picture')
                            : null,
                    ] : null,
                ],
                'documents' => $documentData,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server error while fetching documents',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function downloadDocument(Request $request, $documentId)
    {
        try {
            // Get the specific document by ID using findOrFail to ensure we get the exact document
            $document = ChurchOwnerDocument::findOrFail($documentId);
            
            // Get the church associated with this document
            $church = Church::findOrFail($document->ChurchID);
            
            // Authorization: Only allow access to:
            // 1. System Admins (system_role_id = 1)
            // 2. Church Owner (user_id matches church owner)
            $user = Auth::user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthorized. Please login.'], 401);
            }
            
            $isSystemAdmin = $user->profile && $user->profile->system_role_id === 1;
            $isChurchOwner = $church->user_id === $user->id;
            
            if (!$isSystemAdmin && !$isChurchOwner) {
                Log::warning('Unauthorized document access attempt', [
                    'user_id' => $user->id,
                    'document_id' => $documentId,
                    'church_id' => $document->ChurchID
                ]);
                return response()->json(['error' => 'Unauthorized. You do not have permission to access this document.'], 403);
            }
            
            // Get the correct file path
            $filePath = $document->DocumentPath ?? '';
            if (!$filePath) {
                return response()->json(['error' => 'Document file not found'], 404);
            }

            // Download from Supabase
            $supabase = new SupabaseStorageService();
            $downloadResult = $supabase->download($filePath);
            
            if (!$downloadResult['success']) {
                return response()->json(['error' => 'Document file not found in storage'], 404);
            }
            
            // Get the correct MIME type based on actual file extension
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeType = match ($extension) {
                'pdf' => 'application/pdf',
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                default => $downloadResult['content_type'] ?? 'application/octet-stream'
            };
            
            $fileName = rawurlencode($document->DocumentType . '.' . $extension);

            // Return the file
            return response($downloadResult['contents'], 200, [
                'Content-Type' => $mimeType,
                'Content-Disposition' => 'inline; filename="' . $fileName . '"',
            ]);
        } catch (\Exception $e) {
            Log::error('Document download error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to retrieve document',
                'message' => config('app.debug') ? $e->getMessage() : 'Server error',
                'documentId' => $documentId
            ], 500);
        }
    }

    /**
     * Toggle church's public status.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $churchId
     * @return \Illuminate\Http\JsonResponse
     */
    public function togglePublish(Request $request, $churchId)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'IsPublic' => 'required|boolean',
            ]);

            // Find the church
            $church = Church::findOrFail($churchId);
            
            // Verify user owns the church
            $user = Auth::user();
            if ($church->user_id !== $user->id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'You do not have permission to modify this church.'
                ], 403);
            }
            
            // Verify church is in Active status
            if ($church->ChurchStatus !== Church::STATUS_ACTIVE) {
                $statusMessage = $church->ChurchStatus === Church::STATUS_PENDING 
                    ? 'This church is still pending approval and cannot be published yet.'
                    : 'This church has been rejected and cannot be published.';
                
                return response()->json([
                    'status' => 'error',
                    'message' => $statusMessage,
                    'churchStatus' => $church->ChurchStatus
                ], 403);
            }
            
            // Check if trying to publish (set to public)
            if ($validated['IsPublic'] === true) {
                // Verify PayMongo is configured and active
                $paymentConfig = ChurchPaymentConfig::where('church_id', $churchId)
                    ->where('provider', 'paymongo')
                    ->where('is_active', true)
                    ->first();
                
                if (!$paymentConfig) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'PayMongo payment gateway must be configured and active before publishing your church.',
                        'action' => 'Please go to Church Settings → Payment Gateway to configure PayMongo first.',
                        'requires_payment_setup' => true
                    ], 403);
                }
                
                // Verify configuration is complete
                if (!$paymentConfig->isComplete()) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'PayMongo configuration is incomplete. Both public and secret keys are required.',
                        'action' => 'Please complete your PayMongo setup in Church Settings → Payment Gateway.',
                        'requires_payment_setup' => true
                    ], 403);
                }
            }
            
            // Toggle IsPublic status
            $church->IsPublic = $validated['IsPublic'];
            $church->save();
            
            // Return success response
            return response()->json([
                'status' => 'success',
                'message' => $church->IsPublic 
                    ? 'Church has been successfully published.' 
                    : 'Church has been unpublished.',
                'church' => [
                    'ChurchID' => $church->ChurchID,
                    'ChurchName' => $church->ChurchName,
                    'ChurchStatus' => $church->ChurchStatus,
                    'IsPublic' => $church->IsPublic
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Church not found.'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error toggling church publish status: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while updating the church status.',
                'error' => config('app.debug') ? $e->getMessage() : 'Server error'
            ], 500);
        }
    }

    /**
     * Show a specific church with its details.
     *
     * @param  int  $churchId
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($churchId)
    {
        try {
            $church = Church::with(['profile', 'documents'])
                ->where('ChurchID', $churchId)
                ->firstOrFail();

            // Check if user has permission to view this church
            if ($church->user_id !== Auth::id()) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            // Format document URLs and types
            $documents = $church->documents->map(function($doc) {
                return [
                    'DocumentID' => $doc->DocumentID,
                    'DocumentType' => $doc->DocumentType,
                    'DocumentPath' => $doc->DocumentPath,
                    'DocumentUrl' => url('/api/documents/' . $doc->DocumentID),
                    'SubmissionDate' => $doc->SubmissionDate
                ];
            });

            return response()->json([
                'church' => [
                    'ChurchID' => $church->ChurchID,
                    'ChurchName' => $church->ChurchName,
                    'ChurchStatus' => $church->ChurchStatus,
                    'IsPublic' => $church->IsPublic,
                    'Latitude' => $church->Latitude,
                    'Longitude' => $church->Longitude,
                    'Street' => $church->Street,
                    'City' => $church->City,
                    'Province' => $church->Province,
                    'Description' => $church->profile ? $church->profile->Description : null,
                    'ParishDetails' => $church->profile ? $church->profile->ParishDetails : null,
                    'Diocese' => $church->profile ? $church->profile->Diocese : null,
                    'ContactNumber' => $church->profile ? $church->profile->ContactNumber : null,
                    'Email' => $church->profile ? $church->profile->Email : null,
                    'ProfilePicturePath' => $church->profile ? $church->profile->ProfilePicturePath : null,
                    'ProfilePictureUrl' => $church->profile && $church->profile->ProfilePicturePath ? 
                        url('/api/churches/' . $church->ChurchID . '/profile-picture') : null,
                    'documents' => $documents
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Church not found'], 404);
        }
    }


    /**
     * Get church profile picture
     *
     * @param int $churchId
     * @return \Illuminate\Http\Response
     */
    public function getProfilePicture($churchId)
    {
        try {
            $church = Church::with('profile')->findOrFail($churchId);
            
            if (!$church->profile || !$church->profile->ProfilePicturePath) {
                return response()->json(['error' => 'No profile picture found'], 404);
            }

            $filePath = $church->profile->ProfilePicturePath;
            
            // Download from Supabase
            $supabase = new SupabaseStorageService();
            $downloadResult = $supabase->download($filePath);
            
            if (!$downloadResult['success']) {
                return response()->json(['error' => 'Profile picture file not found in storage'], 404);
            }
            
            // Get file extension and determine MIME type more explicitly
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeType = match ($extension) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                default => $downloadResult['content_type'] ?? 'image/jpeg'
            };

            // Generate an ETag for caching purposes
            $eTag = md5($filePath . time());

            // Return the file with proper headers
            $response = response($downloadResult['contents'], 200);
            $response->header('Content-Type', $mimeType);
            $response->header('Content-Disposition', 'inline');
            $response->header('Cache-Control', 'public, max-age=86400');
            $response->header('Pragma', 'public');
            $response->header('ETag', $eTag);
            
            // Don't set CORS headers here - let middleware handle it
            return $response;
        } catch (\Exception $e) {
            Log::error('Profile picture error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to retrieve profile picture'], 500);
        }
    }

    /**
     * Get all public churches for the map display.
     * This endpoint is publicly accessible and returns only published churches.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPublicChurches()
    {
        try {
            $churches = Church::with(['profile'])
                ->where('IsPublic', true)
                ->where('ChurchStatus', Church::STATUS_ACTIVE)
                ->get();

            return response()->json([
                'churches' => $churches->map(function ($church) {
                    return [
                        'ChurchID' => $church->ChurchID,
                        'ChurchName' => $church->ChurchName,
                        'Latitude' => $church->Latitude,
                        'Longitude' => $church->Longitude,
                        'Description' => $church->profile ? $church->profile->Description : null,
                        'ParishDetails' => $church->profile ? $church->profile->ParishDetails : null,
                        'ProfilePictureUrl' => $church->profile && $church->profile->ProfilePicturePath 
                            ? url('/api/churches/' . $church->ChurchID . '/profile-picture')
                            : null,
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching public churches: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch public churches',
                'message' => config('app.debug') ? $e->getMessage() : 'Server error'
            ], 500);
        }
    }

    /**
     * Get public church information by ID for member registration.
     * This endpoint is publicly accessible and returns only basic church info.
     *
     * @param  int  $churchId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPublicChurch($churchId)
    {
        try {
            $church = Church::with(['profile'])
                ->where('ChurchID', $churchId)
                ->where('IsPublic', true)
                ->where('ChurchStatus', Church::STATUS_ACTIVE)
                ->firstOrFail();

            return response()->json([
                'ChurchID' => $church->ChurchID,
                'ChurchName' => $church->ChurchName,
                'Latitude' => $church->Latitude,
                'Longitude' => $church->Longitude,
                'Description' => $church->profile ? $church->profile->Description : null,
                'ParishDetails' => $church->profile ? $church->profile->ParishDetails : null,
                'ProfilePictureUrl' => $church->profile && $church->profile->ProfilePicturePath 
                    ? url('/api/churches/' . $church->ChurchID . '/profile-picture')
                    : null,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Church not found or not publicly available',
                'message' => 'The requested church either does not exist, is not published, or is not active.'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error fetching public church: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch church information',
                'message' => config('app.debug') ? $e->getMessage() : 'Server error'
            ], 500);
        }
    }

    /**
     * Handle profile picture upload
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param \App\Models\Church $church
     * @return string|null
     */
    protected function handleProfilePicture($file, $church)
    {
        if (!$file) return null;
        
        $filename = $church->ChurchID . '_ProfilePicture_' . time() . '.' . $file->getClientOriginalExtension();
        $storagePath = 'profile-pictures/' . $filename;
        
        $supabase = new SupabaseStorageService();
        
        // Delete old profile picture if exists
        if ($church->profile && $church->profile->ProfilePicturePath) {
            $supabase->delete($church->profile->ProfilePicturePath);
        }
        
        // Store new file in Supabase
        $uploadResult = $supabase->upload(
            $storagePath,
            file_get_contents($file->path()),
            $file->getMimeType()
        );
        
        if ($uploadResult['success']) {
            return $storagePath;
        }
        
        Log::error('Failed to upload profile picture to Supabase: ' . ($uploadResult['error'] ?? 'Unknown error'));
        return null;
    }

    public function update(Request $request, $churchId)
    {
        try {
            // Find the church
            $church = Church::findOrFail($churchId);
            
            // Verify ownership
            if ($church->user_id !== Auth::id()) {
                return response()->json(['error' => 'You do not have permission to edit this church'], 403);
            }

            // Validate the request
            $validated = $request->validate([
                'ChurchName' => 'sometimes|string|max:255|unique:Church,ChurchName,' . $church->ChurchID . ',ChurchID',
                'Latitude' => 'sometimes|numeric|between:-90,90',
                'Longitude' => 'sometimes|numeric|between:-180,180',
                'Street' => 'sometimes|string|max:255',
                'City' => 'sometimes|string|max:255',
                'Province' => 'sometimes|string|max:255',
                'Description' => 'sometimes|string|min:10|max:1000',
                'ParishDetails' => 'sometimes|string|min:10|max:1000',
                'Diocese' => 'required|string|max:255',
                'ContactNumber' => 'sometimes|string|max:50',
                'Email' => 'sometimes|email|max:255',
                'ProfilePicture' => 'sometimes|image|mimes:jpeg,png,jpg|max:2048',
                'SEC' => 'sometimes|file|mimes:jpeg,png,jpg,pdf|max:5120',
                'BIR' => 'sometimes|file|mimes:jpeg,png,jpg,pdf|max:5120',
                'BarangayPermit' => 'sometimes|file|mimes:jpeg,png,jpg,pdf|max:5120',
                'AuthorizationLetter' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
                'RepresentativeID' => 'sometimes|file|mimes:jpeg,png,jpg,pdf|max:5120',
            ]);

            return DB::transaction(function () use ($request, $validated, $church) {
                // Update church basic info
                if (isset($validated['ChurchName'])) {
                    $church->ChurchName = $validated['ChurchName'];
                }
                if (isset($validated['Latitude'])) {
                    $church->Latitude = $validated['Latitude'];
                }
                if (isset($validated['Longitude'])) {
                    $church->Longitude = $validated['Longitude'];
                }
                if (isset($validated['Street'])) {
                    $church->Street = $validated['Street'];
                }
                if (isset($validated['City'])) {
                    $church->City = $validated['City'];
                }
                if (isset($validated['Province'])) {
                    $church->Province = $validated['Province'];
                }
                
                // If church was rejected and is being updated, set back to pending
                $isResubmission = false;
                if ($church->ChurchStatus === Church::STATUS_REJECTED) {
                    $church->ChurchStatus = Church::STATUS_PENDING;
                    $isResubmission = true;
                }
                
                $church->save();
                
                // Notify system admins if this is a resubmission
                if ($isResubmission) {
                    $systemAdmins = UserProfile::where('system_role_id', 1)->pluck('user_id');
                    $ownerName = trim(($church->owner->profile->first_name ?? '') . ' ' . ($church->owner->profile->last_name ?? '')) ?: $church->owner->email;
                    
                    foreach ($systemAdmins as $adminId) {
                        $notification = Notification::create([
                            'user_id' => $adminId,
                            'type' => 'church_resubmission',
                            'title' => 'Church Application Resubmitted',
                            'message' => "{$ownerName} has resubmitted the church registration for {$church->ChurchName}.",
                            'data' => [
                                'church_id' => $church->ChurchID,
                                'church_name' => $church->ChurchName,
                                'owner_name' => $ownerName,
                                'owner_email' => $church->owner->email,
                                'status' => 'pending',
                                'link' => '/church-ms-taupe.vercel.app/application',
                            ],
                            'is_read' => false,
                        ]);
                        
                        // Broadcast to each admin's private channel
                        broadcast(new NotificationCreated($adminId, $notification));
                    }
                }

                // Update or create profile text fields
                if (isset($validated['Description']) || isset($validated['ParishDetails']) || isset($validated['Diocese']) || isset($validated['ContactNumber']) || isset($validated['Email'])) {
                    if ($church->profile) {
                        $church->profile()->update([
                            'Description' => $validated['Description'] ?? $church->profile->Description,
                            'ParishDetails' => $validated['ParishDetails'] ?? $church->profile->ParishDetails,
                            'Diocese' => $validated['Diocese'] ?? $church->profile->Diocese,
                            'ContactNumber' => $validated['ContactNumber'] ?? $church->profile->ContactNumber,
                            'Email' => $validated['Email'] ?? $church->profile->Email,
                        ]);
                    } else {
                        ChurchProfile::create([
                            'ChurchID' => $church->ChurchID,
                            'Description' => $validated['Description'] ?? null,
                            'ParishDetails' => $validated['ParishDetails'] ?? null,
                            'Diocese' => $validated['Diocese'] ?? null,
                            'ContactNumber' => $validated['ContactNumber'] ?? null,
                            'Email' => $validated['Email'] ?? null,
                        ]);
                    }
                }

                // Handle profile picture
                if ($request->hasFile('ProfilePicture')) {
                    $filename = $this->handleProfilePicture($request->file('ProfilePicture'), $church);
                    
                    if ($filename) {
                        if ($church->profile) {
                            $church->profile()->update([
                                'ProfilePicturePath' => $filename
                            ]);
                        } else {
                            ChurchProfile::create([
                                'ChurchID' => $church->ChurchID,
                                'ProfilePicturePath' => $filename,
                            ]);
                        }
                    }
                }

                // Document type mapping
                $documentTypes = [
                    'SEC' => 'SEC Registration',
                    'BIR' => 'BIR Certificate',
                    'BarangayPermit' => 'Barangay Permit',
                    'AuthorizationLetter' => 'Authorization Letter',
                    'RepresentativeID' => 'Representative Government ID',
                ];

                // Process documents
                $supabase = new SupabaseStorageService();
                foreach ($documentTypes as $inputName => $documentType) {
                    if ($request->hasFile($inputName)) {
                        $file = $request->file($inputName);
                        $filename = $church->ChurchID . '_' . str_replace(' ', '_', $documentType) . '_' . time() . '.' . $file->getClientOriginalExtension();
                        $storagePath = 'documents/' . $filename;
                        
                        // Find existing document if any
                        $existingDoc = ChurchOwnerDocument::where('ChurchID', $church->ChurchID)
                            ->where('DocumentType', $documentType)
                            ->first();
                            
                        // Delete old file if exists
                        if ($existingDoc && $existingDoc->DocumentPath) {
                            $supabase->delete($existingDoc->DocumentPath);
                        }
                        
                        // Store new file in Supabase
                        $uploadResult = $supabase->upload(
                            $storagePath,
                            file_get_contents($file->path()),
                            $file->getMimeType()
                        );
                        
                        if ($uploadResult['success']) {
                            // Update or create document record
                            ChurchOwnerDocument::updateOrCreate(
                                [
                                    'ChurchID' => $church->ChurchID,
                                    'DocumentType' => $documentType
                                ],
                                [
                                    'DocumentPath' => $storagePath,
                                    'SubmissionDate' => now(),
                                ]
                            );
                        } else {
                            Log::error('Failed to upload document to Supabase: ' . ($uploadResult['error'] ?? 'Unknown error'));
                        }
                    }
                }

                // Load fresh data with relationships
                $church->load(['profile', 'documents']);

                // Prepare document URLs
                $documents = $church->documents->map(function($doc) {
                    return [
                        'DocumentID' => $doc->DocumentID,
                        'DocumentType' => $doc->DocumentType,
                        'DocumentPath' => $doc->DocumentPath,
                        'DocumentUrl' => url('/api/documents/' . $doc->DocumentID),
                        'SubmissionDate' => $doc->SubmissionDate
                    ];
                });

                return response()->json([
                    'message' => 'Church updated successfully',
                    'church' => [
                        'ChurchID' => $church->ChurchID,
                        'ChurchName' => $church->ChurchName,
                        'ChurchStatus' => $church->ChurchStatus,
                        'IsPublic' => $church->IsPublic,
                        'Latitude' => $church->Latitude,
                        'Longitude' => $church->Longitude,
                        'City' => $church->City,
                        'Province' => $church->Province,
                        'Description' => $church->profile ? $church->profile->Description : null,
                        'ParishDetails' => $church->profile ? $church->profile->ParishDetails : null,
                        'Diocese' => $church->profile ? $church->profile->Diocese : null,
                        'ContactNumber' => $church->profile ? $church->profile->ContactNumber : null,
                        'Email' => $church->profile ? $church->profile->Email : null,
                        'ProfilePicturePath' => $church->profile ? $church->profile->ProfilePicturePath : null,
                        'ProfilePictureUrl' => $church->profile && $church->profile->ProfilePicturePath ? 
                            url('/api/churches/' . $church->ChurchID . '/profile-picture') : null,
                        'documents' => $documents
                    ]
                ]);
                
            });
        } catch (\Exception $e) {
            Log::error('Church update error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to update church',
                'message' => config('app.debug') ? $e->getMessage() : 'An unexpected error occurred'
            ], 500);
        }
    }

    /**
     * Disable a church
     */
    public function disableChurch($churchId)
    {
        try {
            $church = Church::findOrFail($churchId);
            
            $church->update([
                'ChurchStatus' => Church::STATUS_DISABLED,
                'IsPublic' => false
            ]);
            
            return response()->json([
                'message' => 'Church disabled successfully',
                'church' => [
                    'ChurchID' => $church->ChurchID,
                    'ChurchName' => $church->ChurchName,
                    'ChurchStatus' => $church->ChurchStatus,
                    'IsPublic' => $church->IsPublic
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Church disable error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to disable church',
                'message' => config('app.debug') ? $e->getMessage() : 'An unexpected error occurred'
            ], 500);
        }
    }
}
