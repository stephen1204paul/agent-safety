<?php

/**
 * PHPStan-only bootstrap: defines the handful of real WordPress core
 * constants referenced from plugin/src that php-stubs/wordpress-stubs does
 * NOT declare (that package stubs functions/classes, not the `define()`
 * calls WordPress core itself runs at boot to create these). Executed (not
 * merely scanned) so PHPStan reflects the constants with their real,
 * exact-value types — in particular `ARRAY_A`, whose value is checked
 * against wpdb::get_row()/get_results()'s literal-string-keyed
 * `@phpstan-return` conditional type (see wordpress-stubs' wpdb stub).
 * Never loaded outside a PHPStan run (a real WordPress boot always defines
 * these first, hence the guards).
 */

if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

if (!defined('OBJECT_K')) {
    define('OBJECT_K', 'OBJECT_K');
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
