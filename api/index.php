<?php

error_log('[SIAMAG_BOOT_TEST] '.json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'uri' => $_SERVER['REQUEST_URI'] ?? 'UNKNOWN',
]));

// Forward request ke public/index.php milik Laravel
require __DIR__ . '/../public/index.php';