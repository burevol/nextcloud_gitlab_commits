<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 GitLab commits contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GitLabCommits\Controller;

use OCA\GitLabCommits\Service\Configuration;
use OCA\GitLabCommits\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stores the administration form of the app.
 *
 * The route is only reachable with the AuthorizedAdminSetting attribute, and the
 * CSRF check of the framework covers the plain POST of the form.
 */
class SettingsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private Configuration $configuration,
		private IURLGenerator $urlGenerator,
		private ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function save(): RedirectResponse {
		$baseUrl = $this->normalizeBaseUrl((string)$this->request->getParam('gitlab_url', ''));
		$project = trim((string)$this->request->getParam('gitlab_project', ''));
		$branch = trim((string)$this->request->getParam('gitlab_branch', ''));
		$token = trim((string)$this->request->getParam('gitlab_token', ''));
		$removeToken = (bool)$this->request->getParam('gitlab_token_remove', false);

		$cacheTtl = trim((string)$this->request->getParam('cache_ttl', ''));
		if ($cacheTtl === '') {
			$cacheTtl = (string)Configuration::DEFAULT_CACHE_TTL;
		}

		$commitsLimit = trim((string)$this->request->getParam('commits_limit', ''));
		if ($commitsLimit === '') {
			$commitsLimit = (string)Configuration::DEFAULT_COMMITS_LIMIT;
		}

		if ($baseUrl !== '' && !$this->isValidBaseUrl($baseUrl)) {
			return $this->redirectToSettings(AdminSettings::ERROR_INVALID_URL);
		}

		if (!ctype_digit($cacheTtl)
			|| (int)$cacheTtl < Configuration::MIN_CACHE_TTL
			|| (int)$cacheTtl > Configuration::MAX_CACHE_TTL
		) {
			return $this->redirectToSettings(AdminSettings::ERROR_INVALID_TTL);
		}

		if (!ctype_digit($commitsLimit)
			|| (int)$commitsLimit < Configuration::MIN_COMMITS_LIMIT
			|| (int)$commitsLimit > Configuration::MAX_COMMITS_LIMIT
		) {
			return $this->redirectToSettings(AdminSettings::ERROR_INVALID_LIMIT);
		}

		$this->configuration->setGitLabUrl($baseUrl);
		$this->configuration->setGitLabProject($project);
		$this->configuration->setGitLabBranch($branch);
		$this->configuration->setCacheTtl((int)$cacheTtl);
		$this->configuration->setCommitsLimit((int)$commitsLimit);

		if ($removeToken) {
			$this->configuration->setGitLabToken('');
		} elseif ($token !== '') {
			$this->configuration->setGitLabToken($token);
		}

		$this->clearCache();

		return $this->redirectToSettings();
	}

	/**
	 * Accepts "https://gitlab.example.com", with or without a trailing slash or
	 * the "/api/v4" suffix people tend to copy from the GitLab API docs.
	 */
	private function normalizeBaseUrl(string $url): string {
		$url = trim($url);
		if ($url === '') {
			return '';
		}

		$url = rtrim($url, '/');
		$withoutApiSuffix = preg_replace('#/api/v4$#i', '', $url);
		if (is_string($withoutApiSuffix)) {
			$url = $withoutApiSuffix;
		}

		return rtrim($url, '/');
	}

	private function isValidBaseUrl(string $url): bool {
		$scheme = parse_url($url, PHP_URL_SCHEME);
		if (!is_string($scheme) || ($scheme !== 'http' && $scheme !== 'https')) {
			return false;
		}

		return filter_var($url, FILTER_VALIDATE_URL) !== false;
	}

	/**
	 * A changed URL, project, branch, token or limit has to invalidate the cache,
	 * otherwise the administrator would keep seeing the old project for the TTL.
	 */
	private function clearCache(): void {
		try {
			$cache = $this->cacheFactory->isAvailable()
				? $this->cacheFactory->createDistributed(Configuration::APP_ID)
				: $this->cacheFactory->createLocal(Configuration::APP_ID);
			$cache->clear();
		} catch (Throwable $throwable) {
			$this->logger->warning(
				'Could not clear the GitLab commits cache: ' . $throwable->getMessage(),
				['app' => Configuration::APP_ID],
			);
		}
	}

	private function redirectToSettings(string $error = ''): RedirectResponse {
		$parameters = ['section' => 'additional'];
		if ($error === '') {
			$parameters['gitlab_commits_status'] = AdminSettings::STATUS_SAVED;
		} else {
			$parameters['gitlab_commits_status'] = AdminSettings::STATUS_ERROR;
			$parameters['gitlab_commits_error'] = $error;
		}

		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.AdminSettings.index', $parameters),
		);
	}
}
