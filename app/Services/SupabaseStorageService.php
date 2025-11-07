<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupabaseStorageService
{
    protected $url;
    protected $serviceKey;
    protected $bucket;

    public function __construct()
    {
        $this->url = config('supabase.url');
        $this->serviceKey = config('supabase.service_key');
        $this->bucket = config('supabase.storage_bucket');
    }

    /**
     * Upload a file to Supabase Storage
     *
     * @param string $path The path where the file should be stored
     * @param string $fileContents The file contents
     * @param string $contentType The MIME type of the file
     * @return array
     */
    public function upload($path, $fileContents, $contentType = 'application/octet-stream')
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->serviceKey,
                'Content-Type' => $contentType,
            ])->withBody($fileContents, $contentType)
              ->post("{$this->url}/storage/v1/object/{$this->bucket}/{$path}");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'path' => $path,
                    'url' => $this->getPublicUrl($path),
                    'data' => $response->json()
                ];
            }

            Log::error('Supabase upload failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return [
                'success' => false,
                'error' => $response->json()['message'] ?? 'Upload failed'
            ];
        } catch (\Exception $e) {
            Log::error('Supabase upload exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Download a file from Supabase Storage
     *
     * @param string $path The path of the file to download
     * @return array
     */
    public function download($path)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->serviceKey,
            ])->get("{$this->url}/storage/v1/object/{$this->bucket}/{$path}");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'contents' => $response->body(),
                    'content_type' => $response->header('Content-Type')
                ];
            }

            return [
                'success' => false,
                'error' => 'File not found'
            ];
        } catch (\Exception $e) {
            Log::error('Supabase download exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Delete a file from Supabase Storage
     *
     * @param string $path The path of the file to delete
     * @return array
     */
    public function delete($path)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->serviceKey,
            ])->delete("{$this->url}/storage/v1/object/{$this->bucket}/{$path}");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'message' => 'File deleted successfully'
                ];
            }

            return [
                'success' => false,
                'error' => $response->json()['message'] ?? 'Delete failed'
            ];
        } catch (\Exception $e) {
            Log::error('Supabase delete exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get the public URL of a file
     *
     * @param string $path The path of the file
     * @return string
     */
    public function getPublicUrl($path)
    {
        return "{$this->url}/storage/v1/object/public/{$this->bucket}/{$path}";
    }

    /**
     * Get a signed URL for private file access
     *
     * @param string $path The path of the file
     * @param int $expiresIn Expiration time in seconds (default: 1 hour)
     * @return array
     */
    public function getSignedUrl($path, $expiresIn = 3600)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->serviceKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->url}/storage/v1/object/sign/{$this->bucket}/{$path}", [
                'expiresIn' => $expiresIn
            ]);

            if ($response->successful()) {
                $signedPath = $response->json()['signedURL'] ?? null;
                return [
                    'success' => true,
                    'url' => $this->url . '/storage/v1' . $signedPath
                ];
            }

            return [
                'success' => false,
                'error' => $response->json()['message'] ?? 'Failed to generate signed URL'
            ];
        } catch (\Exception $e) {
            Log::error('Supabase signed URL exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * List files in a directory
     *
     * @param string $path The directory path
     * @return array
     */
    public function listFiles($path = '')
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->serviceKey,
            ])->post("{$this->url}/storage/v1/object/list/{$this->bucket}", [
                'prefix' => $path,
                'limit' => 100
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'files' => $response->json()
                ];
            }

            return [
                'success' => false,
                'error' => $response->json()['message'] ?? 'Failed to list files'
            ];
        } catch (\Exception $e) {
            Log::error('Supabase list files exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
