<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileUploadService
{
    protected string $disk;

    public function __construct()
    {
        $this->disk = config('siamag.storage_disk', 'public');
    }

    /**
     * Upload an uploaded file instance.
     * Fallback to base64 Data URI if disk is not writable (e.g. serverless Vercel).
     */
    public function upload(UploadedFile $file, string $directory): string
    {
        try {
            if (! $this->isDiskWritable()) {
                $mime = $file->getMimeType() ?: 'application/octet-stream';
                $content = base64_encode($file->get());

                return "data:{$mime};base64,{$content}";
            }

            $filename = Str::uuid().'.'.$file->getClientOriginalExtension();

            return $file->storeAs($directory, $filename, $this->disk);
        } catch (\Throwable) {
            // Fallback to base64 Data URI if storage write fails
            $mime = $file->getMimeType() ?: 'application/octet-stream';
            $content = base64_encode($file->get());

            return "data:{$mime};base64,{$content}";
        }
    }

    /**
     * Store binary or base64 string directly as Data URI (avoids writing to read-only disk on Vercel).
     */
    public function uploadBase64(string $base64Data, string $directory, string $extension = 'png'): string
    {
        // If it's already a Data URI, return directly
        if (str_starts_with($base64Data, 'data:')) {
            return $base64Data;
        }

        return "data:image/{$extension};base64,{$base64Data}";
    }

    /**
     * Delete a file if it exists on disk.
     */
    public function delete(?string $path): bool
    {
        if (! $path || str_starts_with($path, 'data:')) {
            return false;
        }

        try {
            if (Storage::disk($this->disk)->exists($path)) {
                return Storage::disk($this->disk)->delete($path);
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    /**
     * Get the public URL of a file or the Data URI if stored as base64.
     */
    public function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        // If stored as Data URI (Base64), return directly
        if (str_starts_with($path, 'data:')) {
            return $path;
        }

        // If already a full URL, return directly
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        // Clean up redundant storage prefix if present
        if (str_starts_with($path, '/storage/')) {
            $path = substr($path, 9);
        } elseif (str_starts_with($path, 'storage/')) {
            $path = substr($path, 8);
        }

        try {
            return Storage::disk($this->disk)->url($path);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Stream or download a file response.
     */
    public function downloadResponse(string $path, ?string $filename = null): StreamedResponse
    {
        if (str_starts_with($path, 'data:')) {
            [$meta, $data] = explode(';base64,', $path, 2);
            $mime = str_replace('data:', '', $meta);
            $decoded = base64_decode($data);
            $filename = $filename ?: 'file.bin';

            return response()->streamDownload(function () use ($decoded): void {
                echo $decoded;
            }, $filename, ['Content-Type' => $mime]);
        }

        if (! Storage::disk($this->disk)->exists($path)) {
            abort(404, 'File tidak ditemukan di penyimpanan server.');
        }

        return Storage::disk($this->disk)->download($path, $filename);
    }

    /**
     * Check if the current storage disk root directory is writable.
     */
    protected function isDiskWritable(): bool
    {
        try {
            $root = Storage::disk($this->disk)->path('');

            return is_dir($root) && is_writable($root);
        } catch (\Throwable) {
            return false;
        }
    }
}
