<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 GitLab commits contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GitLabCommits\Service;

use Exception;

/**
 * Thrown when the GitLab API could not be queried successfully.
 *
 * The message is already translated and safe to show in the widget, it never
 * contains the personal access token.
 */
class GitLabException extends Exception {
	/**
	 * Translated, user facing error description.
	 */
	public function getUserMessage(): string {
		return $this->getMessage();
	}
}
