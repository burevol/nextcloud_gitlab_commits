<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 GitLab commits contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GitLabCommits\Dashboard;

use OCA\GitLabCommits\Service\Configuration;
use OCA\GitLabCommits\Service\GitLabClient;
use OCA\GitLabCommits\Service\GitLabException;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use Throwable;

/**
 * Dashboard widget "Latest GitLab commits".
 *
 * The widget is read only: it shows the latest commits of the single project
 * an administrator configured, and it never throws, so a broken GitLab
 * connection cannot break the rest of the dashboard.
 */
class GitLabCommitsWidget implements IAPIWidgetV2, IIconWidget, IButtonWidget {
	public function __construct(
		private IL10N $l10n,
		private GitLabClient $gitLabClient,
		private Configuration $configuration,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getId(): string {
		return 'gitlab_commits';
	}

	public function getTitle(): string {
		return $this->l10n->t('Latest GitLab commits');
	}

	public function getOrder(): int {
		return 10;
	}

	/**
	 * Nextcloud 27 renders the widget header icon from this CSS class, see
	 * css/dashboard.css. Newer releases use getIconUrl() instead.
	 */
	public function getIconClass(): string {
		return 'icon-gitlab-commits';
	}

	public function getIconUrl(): string {
		try {
			return $this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->imagePath(Configuration::APP_ID, 'gitlab.svg'),
			);
		} catch (Throwable) {
			return '';
		}
	}

	/**
	 * Link behind the widget title: the project page in GitLab.
	 */
	public function getUrl(): ?string {
		return $this->configuration->getProjectWebUrl();
	}

	public function load(): void {
	}

	/**
	 * @return WidgetButton[]
	 */
	public function getWidgetButtons(string $userId): array {
		$projectUrl = $this->configuration->getProjectWebUrl();
		if ($projectUrl === null) {
			return [];
		}

		return [
			new WidgetButton(
				WidgetButton::TYPE_MORE,
				$projectUrl . '/-/commits',
				$this->l10n->t('All commits in GitLab'),
			),
		];
	}

	/**
	 * The widget always returns the newest commits. $since (the last since id
	 * the API handed out) is not used to filter, because the dashboard API
	 * replaces the item list instead of merging it.
	 *
	 * @param string $userId
	 * @param string|null $since
	 * @param int $limit
	 */
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		if (!$this->configuration->isConfigured()) {
			return new WidgetItems(
				[],
				$this->l10n->t('GitLab is not configured yet. An administrator can set it up in the administration settings.'),
			);
		}

		try {
			$commits = $this->gitLabClient->getLatestCommits($this->getEffectiveLimit($limit));
		} catch (GitLabException $exception) {
			return new WidgetItems([], $exception->getUserMessage());
		} catch (Throwable) {
			// Never let an unexpected error break the dashboard.
			return new WidgetItems([], $this->l10n->t('Could not load GitLab commits.'));
		}

		$items = [];
		foreach ($commits as $commit) {
			$items[] = new WidgetItem(
				$commit['title'],
				$commit['subtitle'],
				$commit['link'],
				$commit['iconUrl'],
				$commit['date'],
			);
		}

		if ($items === []) {
			return new WidgetItems([], $this->l10n->t('No commits'));
		}

		return new WidgetItems($items);
	}

	/**
	 * The administrator configures the upper bound, a smaller limit requested by
	 * the dashboard (per user widget setting) is respected.
	 */
	private function getEffectiveLimit(int $requestedLimit): int {
		$configuredLimit = $this->configuration->getCommitsLimit();
		$limit = $requestedLimit > 0 ? min($requestedLimit, $configuredLimit) : $configuredLimit;

		return max(Configuration::MIN_COMMITS_LIMIT, min($limit, Configuration::MAX_COMMITS_LIMIT));
	}
}
