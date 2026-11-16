<?php

namespace App\Services;

class GeofencingService
{
    /**
     * @var array<int, array{name: string, latitude: float, longitude: float}>
     */
    protected array $offices;

    protected float $maxDistanceMeters;

    public function __construct()
    {
        $this->maxDistanceMeters = (float) config('siamag.max_distance_meters', 70);
        $this->offices = config('siamag.offices', [
            [
                'name' => 'Kantor Sekretariat Dishub',
                'latitude' => -7.867828,
                'longitude' => 111.462034,
            ],
            [
                'name' => 'Kantor UPT PKB (KIR)',
                'latitude' => -7.838971,
                'longitude' => 111.480587,
            ],
        ]);
    }

    /**
     * Hitung jarak dua koordinat GPS menggunakan Haversine Formula (satuan: meter).
     */
    public function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // Radius bumi dalam meter

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return round($angle * $earthRadius, 2);
    }

    /**
     * Periksa apakah koordinat peserta berada dalam radius maksimal (70m) dari salah satu kantor Dishub.
     *
     * @return array{
     *     valid: bool,
     *     message: string,
     *     matched_office: array{name: string, distance: float, latitude: float, longitude: float}|null,
     *     closest_office: array{name: string, distance: float, latitude: float, longitude: float},
     *     details: array<int, array{name: string, distance: float, is_within_radius: bool}>,
     *     max_distance: float
     * }
     */
    public function checkWithinRadius(float $userLat, float $userLon): array
    {
        $calculatedOffices = [];
        $matchedOffice = null;

        foreach ($this->offices as $office) {
            $distance = $this->calculateDistance(
                $userLat,
                $userLon,
                $office['latitude'],
                $office['longitude']
            );

            $isWithinRadius = $distance <= $this->maxDistanceMeters;

            $officeData = [
                'name' => $office['name'],
                'distance' => $distance,
                'latitude' => $office['latitude'],
                'longitude' => $office['longitude'],
                'is_within_radius' => $isWithinRadius,
            ];

            $calculatedOffices[] = $officeData;

            // Jika dalam radius salah satu kantor, simpan sebagai matched office
            if ($isWithinRadius && $matchedOffice === null) {
                $matchedOffice = $officeData;
            }
        }

        // Urutkan berdasarkan jarak terdekat (ascending)
        usort($calculatedOffices, fn ($a, $b) => $a['distance'] <=> $b['distance']);

        $closestOffice = $calculatedOffices[0];
        $isValid = $matchedOffice !== null;

        if ($isValid) {
            $message = "Lokasi terverifikasi di {$matchedOffice['name']} (Jarak: {$matchedOffice['distance']} meter).";
        } else {
            $message = "Posisi Anda berada di luar jangkauan kantor Dishub. Jarak terdekat Anda adalah {$closestOffice['distance']} meter dari {$closestOffice['name']} (Maksimal radius yang diizinkan adalah {$this->maxDistanceMeters} meter).";
        }

        return [
            'valid' => $isValid,
            'message' => $message,
            'matched_office' => $matchedOffice,
            'closest_office' => $closestOffice,
            'details' => $calculatedOffices,
            'max_distance' => $this->maxDistanceMeters,
        ];
    }
}
