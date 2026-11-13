<?php
declare(strict_types=1);

/**
 * Command Bridge release version. The single source of truth is the VERSION file in the project root
 * (copied to the application folder by install.sh / update.sh).
 */
if (!defined('CB_VERSION')) {
    $cbVersionFile = dirname(__DIR__) . '/VERSION';
    $cbVersion = is_file($cbVersionFile) ? trim((string)@file_get_contents($cbVersionFile)) : '';
    define('CB_VERSION', $cbVersion !== '' ? $cbVersion : '0.9.0-beta.4');
    unset($cbVersionFile, $cbVersion);
}

/** Where users of a network service can get the source (AGPL-3.0 section 13). Set this to your public repository URL. */
if (!defined('CB_SOURCE_URL')) {
    define('CB_SOURCE_URL', 'https://github.com/PhantomNomad/CommandBridge');
}
