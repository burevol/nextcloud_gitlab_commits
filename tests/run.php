<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 GitLab commits contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Entry point of the behaviour test suite.
 *
 *   php tests/run.php
 *
 * The suite has no dependencies outside of PHP itself: Nextcloud is replaced by
 * the stand-ins in tests/stubs.php.
 */

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/cases.php';
