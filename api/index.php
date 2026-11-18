<?php

error_log('[SIAMAG_BOOT_TEST] api/index.php berhasil dijalankan');

// Forward request ke public/index.php milik Laravel
require __DIR__ . '/../public/index.php';