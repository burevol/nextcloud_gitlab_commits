<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 GitLab commits contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GitLabCommits\Service;

use OCP\IConfig;

/**
 * Typed access to the connection settings stored in oc_appconfig.
 *
 * Only administrators can change these values, see
 * \OCA\GitLabCommits\Settings\AdminSettings.
 */
class Configuration {
	public const APP_ID = 'gitlab_commits';

	public const KEY_URL = 'gitlab_url';
	public const KEY_TOKEN = 'gitlab_token';
	public const KEY_PROJECT = 'gitlab_project';
	public const KEY_BRANCH = 'gitlab_branch';
	public const KEY_CACHE_TTL = 'cache_ttl';
	public const KEY_COMMITS_LIMIT = 'commits_limit';

	public const DEFAULT_BRANCH = 'main';
	public const DEFAULT_CACHE_TTL = 60;
	public const DEFAULT_COMMITS_LIMIT = 7;

	public const MIN_CACHE_TTL = 1;
	public const MAX_CACHE_TTL = 86400;
	public const MIN_COMMITS_LIMIT = 1;
	public const MAX_COMMITS_LIMIT = 30;

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * GitLab base URL without a trailing slash, e.g. "https://gitlab.example.com".
	 */
	public function getGitLabUrl(): string {
		return rtrim(trim($this->getValue(self::KEY_URL)), '/');
	}

	public function setGitLabUrl(string $url): void {
		$this->config->setAppValue(self::APP_ID, self::KEY_URL, rtrim(trim($url), '/'));
	}

	/**
	 * Personal access token that is only ever read on the server side.
	 */
	public function getGitLabToken(): string {
		return $this->getValue(self::KEY_TOKEN);
	}

	public function setGitLabToken(string $token): void {
		$this->config->setAppValue(self::APP_ID, self::KEY_TOKEN, $token);
	}

	public function hasGitLabToken(): bool {
		return $this->getGitLabToken() !== '';
	}

	/**
	 * Project id ("23") or project path ("group/subgroup/project"), the latter
	 * may also be stored URL encoded ("group%2Fproject").
	 */
	public function getGitLabProject(): string {
		return trim($this->getValue(self::KEY_PROJECT));
	}

	public function setGitLabProject(string $project): void {
		$this->config->setAppValue(self::APP_ID, self::KEY_PROJECT, trim($project));
	}

	public function getGitLabBranch(): string {
		$branch = trim($this->getValue(self::KEY_BRANCH));

		return $branch === '' ? self::DEFAULT_BRANCH : $branch;
	}

	public function setGitLabBranch(string $branch): void {
		$branch = trim($branch);
		$this->config->setAppValue(
			self::APP_ID,
			self::KEY_BRANCH,
			$branch === '' ? self::DEFAULT_BRANCH : $branch,
		);
	}

	/**
	 * Cache lifetime of a successful GitLab request in seconds.
	 */
	public function getCacheTtl(): int {
		$ttl = (int)$this->getValue(self::KEY_CACHE_TTL, (string)self::DEFAULT_CACHE_TTL);

		return max(self::MIN_CACHE_TTL, min($ttl, self::MAX_CACHE_TTL));
	}

	public function setCacheTtl(int $ttl): void {
		$ttl = max(self::MIN_CACHE_TTL, min($ttl, self::MAX_CACHE_TTL));
		$this->config->setAppValue(self::APP_ID, self::KEY_CACHE_TTL, (string)$ttl);
	}

	/**
	 * Number of commits the widget shows at most.
	 */
	public function getCommitsLimit(): int {
		$limit = (int)$this->getValue(self::KEY_COMMITS_LIMIT, (string)self::DEFAULT_COMMITS_LIMIT);

		return max(self::MIN_COMMITS_LIMIT, min($limit, self::MAX_COMMITS_LIMIT));
	}

	public function setCommitsLimit(int $limit): void {
		$limit = max(self::MIN_COMMITS_LIMIT, min($limit, self::MAX_COMMITS_LIMIT));
		$this->config->setAppValue(self::APP_ID, self::KEY_COMMITS_LIMIT, (string)$limit);
	}

	/**
	 * The widget can only work when URL, token and project are all set.
	 */
	public function isConfigured(): bool {
		return $this->getGitLabUrl() !== ''
			&& $this->hasGitLabToken()
			&& $this->getGitLabProject() !== '';
	}

	/**
	 * Web URL of the configured project, used for the widget "show all commits"
	 * button. Returns null when the project is unknown or configured by a
	 * numeric id, because the project path cannot be derived from an id without
	 * an extra API request.
	 */
	public function getProjectWebUrl(): ?string {
		$url = $this->getGitLabUrl();
		$project = $this->getGitLabProject();
		if ($url === '' || $project === '' || ctype_digit($project)) {
			return null;
		}

		return $url . '/' . ltrim(rawurldecode($project), '/');
	}

	private function getValue(string $key, string $default = ''): string {
		return $this->config->getAppValue(self::APP_ID, $key, $default);
	}
}
