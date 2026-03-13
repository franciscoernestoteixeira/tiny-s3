<?php
declare(strict_types=1);

// =============================================================================
//  PHPUnit bootstrap
//
//  Runs once before any test. Loads Composer's autoloader (for namespaced test
//  classes and Guzzle) then loads index.php in test-safe mode.
//
//  WHY index.php INSTEAD OF helpers.php
//  ─────────────────────────────────────
//  PHPUnit measures coverage against the files listed in phpunit.xml <source>.
//  That list includes index.php. For coverage to be non-zero, index.php must be
//  executed (required) inside *this* process — the one PHPUnit instruments.
//
//  Defining TINY_S3_TEST before the require makes Section 10 (the HTTP entry
//  point — ini_set, loadEnv, checkSignature, match dispatch …) a no-op, so the
//  file is safe to include without a running web server or HTTP globals.
//  All function definitions in Sections 1–9 are registered normally and can be
//  called directly by unit tests.
//
//  helpers.php is kept for reference but is no longer loaded here.
//
//  Integration tests do NOT rely on this file for the code under test — they
//  start a real PHP built-in server (proc_open) and make full HTTP round-trips.
// =============================================================================

require_once __DIR__ . '/../vendor/autoload.php';

// Signal index.php to skip the HTTP bootstrap block (Section 10).
define('TINY_S3_TEST', true);

// Load the production file directly so PHPUnit can instrument every line.
require_once __DIR__ . '/../index.php';
