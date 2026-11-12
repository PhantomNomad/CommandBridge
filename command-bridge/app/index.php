<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

if (!tsc_admin_exists()) {
    header('Location: ' . app_url('/setup/setup_tsc.php'));
    exit;
}

if (current_user()) {
    if (!empty(current_user()['is_tsc_admin']) && !active_incident()) {
        // Fresh install: administrator exists but no incident has been started yet.
        header('Location: ' . app_url('/setup/setup_tsc.php?step=2'));
        exit;
    }
    header('Location: ' . app_url('/dashboard/index.php'));
    exit;
}

header('Location: ' . app_url('/login.php'));
exit;
