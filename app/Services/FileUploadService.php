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
     */
    public function upload(UploadedFile $file, string $directory): string
    {
        $filename = Str::uuid().'.'.$file->getClientOriginalExtension();
        $path = $file->storeAs($directory, $filename, $this->disk);

        return $path;
    }

    /**
     * Upload binary or base64 string (useful for digital signatures / webcam capture).
     */
    public function uploadBase64(string $base64Data, string $directory, string $extension = 'png'): string
    {
        // Handle data URI scheme if present (e.g. data:image/png;base64,...)
        if (str_contains($base64Data, ';base64,')) {
            [$meta, $data] = explode(';base64,', $base64Data, 2);
            if (preg_match('/data:image\/(\w+)/', $meta, $matches)) {
                $extension = $matches[1];
            }
            $base64Data = $data;
        }

        $decoded = base64_decode($base64Data);
        $filename = $directory.'/'.Str::uuid().'.'.$extension;

        Storage::disk($this->disk)->put($filename, $decoded);

        return $filename;
    }

    /**
     * Delete a file if it exists.
     */
    public function delete(?string $path): bool
    {
        if (! $path) {
            return false;
        }

        if (Storage::disk($this->disk)->exists($path)) {
            return Storage::disk($this->disk)->delete($path);
        }

        return false;
    }

    /**
     * Get the public URL of a file.
     */
    public function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Storage::disk($this->disk)->url($path);
    }

    /**
     * Stream or download a file response.
     */
    public function downloadResponse(string $path, ?string $filename = null): StreamedResponse
    {
        if (! Storage::disk($this->disk)->exists($path)) {
            abort(404, 'File tidak ditemukan di penyimpanan server.');
        }

        return Storage::disk($this->disk)->download($path, $filename);
    }
}
