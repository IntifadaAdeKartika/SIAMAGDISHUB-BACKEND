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
        return $this->fileResponse($path, $filename, 'attachment');
    }

    /**
     * Stream a file so the browser can display it directly (images, PDF).
     */
    public function inlineResponse(string $path, ?string $filename = null): StreamedResponse
    {
        return $this->fileResponse($path, $filename, 'inline');
    }

    /**
     * Stream a stored file (disk path or Base64 Data URI) with the requested disposition.
     */
    public function fileResponse(string $path, ?string $filename = null, string $disposition = 'attachment'): StreamedResponse
    {
        $disposition = $disposition === 'inline' ? 'inline' : 'attachment';

        if (str_starts_with($path, 'data:')) {
            if (! preg_match('/^data:([^;,]+)?(;base64)?,(.*)$/s', $path, $parts)) {
                abort(422, 'Format lampiran tidak valid.');
            }

            $mime = ($parts[1] ?? '') !== '' ? strtolower($parts[1]) : 'application/octet-stream';
            $isBase64 = ($parts[2] ?? '') === ';base64';
            $decoded = $isBase64 ? base64_decode($parts[3], true) : rawurldecode($parts[3]);

            if ($decoded === false) {
                abort(422, 'Isi lampiran tidak valid.');
            }

            $filename = $this->normalizeFilename($filename, $mime, 'file');
            $headers = $this->fileHeaders($mime, $filename, $disposition);
            $headers['Content-Length'] = (string) strlen($decoded);

            return response()->stream(function () use ($decoded): void {
                echo $decoded;
            }, 200, $headers);
        }

        if (! Storage::disk($this->disk)->exists($path)) {
            abort(404, 'File tidak ditemukan di penyimpanan server.');
        }

        try {
            $mime = Storage::disk($this->disk)->mimeType($path) ?: 'application/octet-stream';
        } catch (\Throwable) {
            $mime = 'application/octet-stream';
        }

        $filename = $this->normalizeFilename($filename, $mime, basename($path));
        $headers = $this->fileHeaders($mime, $filename, $disposition);

        try {
            $headers['Content-Length'] = (string) Storage::disk($this->disk)->size($path);
        } catch (\Throwable) {
            // Content-Length is optional; streaming still works without it.
        }

        $stream = Storage::disk($this->disk)->readStream($path);

        if ($stream === false) {
            abort(404, 'File tidak ditemukan di penyimpanan server.');
        }

        return response()->stream(function () use ($stream): void {
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, $headers);
    }

    /**
     * Build response headers that let the browser display or download the file safely.
     */
    protected function fileHeaders(string $mime, string $filename, string $disposition): array
    {
        $asciiName = preg_replace('/[^\x20-\x7E]/', '_', $filename) ?: 'file';
        $asciiName = str_replace(['"', '\\', "\r", "\n"], '_', $asciiName);

        return [
            'Content-Type' => $mime,
            'Content-Disposition' => $disposition.'; filename="'.$asciiName.'"; filename*=UTF-8\'\''.rawurlencode($filename),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=0, no-store',
        ];
    }

    /**
     * Ensure the file name has a useful extension when one can be derived from the MIME type.
     */
    protected function normalizeFilename(?string $filename, string $mime, string $fallback): string
    {
        $filename = trim((string) ($filename ?: $fallback));

        if ($filename === '') {
            $filename = 'file';
        }

        if (pathinfo($filename, PATHINFO_EXTENSION) === '') {
            $filename .= '.'.$this->extensionForMime($mime);
        }

        return $filename;
    }

    protected function extensionForMime(string $mime): string
    {
        return [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/plain' => 'txt',
            'application/zip' => 'zip',
        ][$mime] ?? 'bin';
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
