<?php

namespace App\Http\Controllers;

use App\Models\Signature;
use App\Services\SupabaseStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SignatureController extends Controller
{
    protected $supabase;

    public function __construct(SupabaseStorageService $supabase)
    {
        $this->supabase = $supabase;
    }

    public function index(Request $request)
    {
        $churchId = $request->query('church_id');
        
        if (!$churchId) {
            return response()->json(['message' => 'Church ID is required'], 400);
        }

        $signatures = Signature::where('church_id', $churchId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($signatures);
    }

    public function store(Request $request)
    {
        $request->validate([
            'church_id' => 'required|exists:Church,ChurchID',
            'name' => 'required|string|max:255',
            'signature' => 'required|image|mimes:jpeg,png,jpg,gif,svg|max:5120', // 5MB max
        ]);

        $imagePath = null;
        
        if ($request->hasFile('signature')) {
            $file = $request->file('signature');
            $filename = 'signatures/' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            
            // Upload to Supabase
            $result = $this->supabase->upload(
                $filename,
                file_get_contents($file->getRealPath()),
                $file->getMimeType()
            );
            
            if ($result['success']) {
                $imagePath = $result['path'];
                
                Log::info('Signature uploaded to Supabase', [
                    'path' => $imagePath,
                    'url' => $result['url']
                ]);
            } else {
                Log::error('Failed to upload signature to Supabase', [
                    'error' => $result['error'] ?? 'Unknown error'
                ]);
                return response()->json([
                    'message' => 'Failed to upload signature image',
                    'error' => $result['error'] ?? 'Upload failed'
                ], 500);
            }
        }

        $signature = Signature::create([
            'church_id' => $request->church_id,
            'name' => $request->name,
            'imagePath' => $imagePath,
        ]);

        return response()->json($signature, 201);
    }

    public function destroy($id)
    {
        $signature = Signature::find($id);

        if (!$signature) {
            return response()->json(['message' => 'Signature not found'], 404);
        }

        // Delete the image file from Supabase storage
        if ($signature->imagePath) {
            $result = $this->supabase->delete($signature->imagePath);
            
            if (!$result['success']) {
                Log::warning('Failed to delete signature from Supabase', [
                    'signature_id' => $id,
                    'path' => $signature->imagePath,
                    'error' => $result['error'] ?? 'Unknown error'
                ]);
                // Continue with database deletion even if Supabase deletion fails
            }
        }

        $signature->delete();

        return response()->json(['message' => 'Signature deleted successfully']);
    }

    public function getImage($id)
    {
        $signature = Signature::find($id);

        if (!$signature || !$signature->imagePath) {
            return response()->json(['message' => 'Signature not found'], 404);
        }

        // Redirect to the public Supabase URL
        $publicUrl = $this->supabase->getPublicUrl($signature->imagePath);
        return redirect($publicUrl);
    }
}
