<?php
declare(strict_types=1);

// =============================================================================
//  PHPUnit bootstrap
//
//  Runs once before any test. Loads Composer's autoloader (for namespaced test
//  classes and Guzzle) then includes the pure-function stub used by unit tests.
//
//  Integration tests do not use helpers.php — they start a real PHP built-in
//  server and make full HTTP round-trips against it.
// =============================================================================

require_once __DIR__ . '/../vendor/autoload.php';

// Pure helper functions from index.php, extracted for safe unit testing.
// Functions that call sendError() / exit() are intentionally excluded here;
// they are covered by the integration suite instead.
require_once __DIR__ . '/helpers.php';
