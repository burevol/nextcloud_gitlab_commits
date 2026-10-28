<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 GitLab commits contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Bootstraps the app classes and the Nextcloud stand-ins for the test run.
 * Paths are derived from this file, so the suite runs from any checkout and
 * from any working directory.
 */

define('APP_ROOT', dirname(__DIR__));
define('TEST_ROOT', __DIR__);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '0');

require TEST_ROOT . '/stubs.php';

spl_autoload_register(static function (string $class): void {
	$prefix = 'OCA\\GitLabCommits\\';
	if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
		return;
	}

	$path = APP_ROOT . '/lib/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
	if (is_file($path)) {
		require $path;
	}
});
