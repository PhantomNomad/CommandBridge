<?php
declare(strict_types=1);

/** Release version: single source of truth is the VERSION file in the project root. */
if (!defined('GATEWAY_VERSION')) {
    $v = is_file(__DIR__ . '/../VERSION') ? trim((string)@file_get_contents(__DIR__ . '/../VERSION')) : '';
    define('GATEWAY_VERSION', $v !== '' ? $v : '0.9.0-beta.2');
    unset($v);
}
/** Where users of this network service can get the source (AGPL-3.0 section 13). */
if (!defined('GATEWAY_SOURCE_URL')) {
    define('GATEWAY_SOURCE_URL', 'https://github.com/PhantomNomad/CommandBridge');
}
