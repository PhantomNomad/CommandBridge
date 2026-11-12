<?php
declare(strict_types=1);
function command_bridge_find_tcpdf_path(): ?string
{
    $candidates = [
        '/usr/share/php/tcpdf/tcpdf.php',
        '/usr/share/php/TCPDF/tcpdf.php',
        '/usr/share/tcpdf/tcpdf.php',
        '/usr/local/share/php/tcpdf/tcpdf.php',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function command_bridge_require_tcpdf(): bool
{
    if (class_exists('TCPDF', false)) {
        return true;
    }

    $path = command_bridge_find_tcpdf_path();
    if ($path === null) {
        return false;
    }

    require_once $path;
    return class_exists('TCPDF', false);
}
