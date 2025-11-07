<?php

namespace App\Http\Controllers;

use App\Models\Signature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SignatureController extends Controller
{
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
            'signature' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120', // 5MB max
        ]);

        $imagePath = null;
        if ($request->hasFile('signature')) {
            $file = $request->file('signature');
            $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $imagePath = $file->storeAs('signature', $filename, 'local');
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

        // Delete the image file from storage
        if ($signature->imagePath && Storage::disk('local')->exists($signature->imagePath)) {
            Storage::disk('local')->delete($signature->imagePath);
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

        if (!Storage::disk('local')->exists($signature->imagePath)) {
            return response()->json(['message' => 'Image file not found'], 404);
        }

        $file = Storage::disk('local')->get($signature->imagePath);
        $type = Storage::disk('local')->mimeType($signature->imagePath);

        return response($file, 200)->header('Content-Type', $type);
    }
}
