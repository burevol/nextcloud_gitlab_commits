<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 GitLab commits contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GitLabCommits\AppInfo;

use OCA\GitLabCommits\Dashboard\GitLabCommitsWidget;
use OCA\GitLabCommits\Service\Configuration;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;

class Application extends App implements IBootstrap {
	public function __construct() {
		parent::__construct(Configuration::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(GitLabCommitsWidget::class);

		// Make sure the token is obfuscated if a Configuration instance ever ends
		// up in a log record or an exception context.
		$context->registerSensitiveMethods(Configuration::class, [
			'getGitLabToken',
			'setGitLabToken',
		]);
	}

	public function boot(IBootContext $context): void {
		// Nextcloud 27 renders the dashboard widget header icon from the CSS class
		// returned by IWidget::getIconClass(). The rule that maps that class to
		// img/gitlab.svg lives in css/dashboard.css and has to be loaded on the
		// dashboard page, which does not load app styles by itself.
		Util::addStyle(Configuration::APP_ID, 'dashboard');
	}
}
