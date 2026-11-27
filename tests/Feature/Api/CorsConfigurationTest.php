<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CorsConfigurationTest extends TestCase
{
    public function test_respons_error_api_tetap_mendapat_header_cors(): void
    {
        Route::get('/backend-api/cors-error-test', function (): never {
            throw new \RuntimeException('CORS error response test');
        });

        $this->withHeaders(['Origin' => 'https://siamagdishub.vercel.app'])
            ->getJson('/backend-api/cors-error-test')
            ->assertStatus(500)
            ->assertHeader('Access-Control-Allow-Origin', 'https://siamagdishub.vercel.app');
    }

    public function test_preflight_dari_frontend_production_mendapat_header_cors_yang_lengkap(): void
    {
        $response = $this->call('OPTIONS', '/backend-api/pembimbing/absensi?per_page=100', [], [], [], [
            'HTTP_ORIGIN' => 'https://siamagdishub.vercel.app',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'accept,authorization,content-type,x-requested-with',
        ]);

        $response->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://siamagdishub.vercel.app')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');

        $allowedMethods = $response->headers->get('Access-Control-Allow-Methods');
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $this->assertStringContainsString($method, $allowedMethods);
        }

        $allowedHeaders = $response->headers->get('Access-Control-Allow-Headers');
        foreach (['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'] as $header) {
            $this->assertStringContainsStringIgnoringCase($header, $allowedHeaders);
        }
    }

    public function test_origin_yang_tidak_diizinkan_tidak_mendapat_header_cors(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://evil.example'])
            ->getJson('/backend-api/pembimbing/absensi?per_page=100')
            ->assertUnauthorized();

        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));

        $preflight = $this->call('OPTIONS', '/backend-api/pembimbing/absensi?per_page=100', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization',
        ])->assertNoContent();

        $this->assertFalse($preflight->headers->has('Access-Control-Allow-Origin'));
    }

    public function test_preview_vercel_tidak_diizinkan(): void
    {
        $response = $this->withHeaders(['Origin' => 'https://siamagdishub-frontend-abc123.vercel.app'])
            ->getJson('/backend-api/pembimbing/absensi?per_page=100')
            ->assertUnauthorized();

        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));

        $response = $this->withHeaders(['Origin' => 'https://attacker.vercel.app'])
            ->getJson('/backend-api/pembimbing/absensi?per_page=100')
            ->assertUnauthorized();

        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }
}
