<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 GitLab commits contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GitLabCommits\Service;

use DateTimeImmutable;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the latest commits of the configured GitLab project through the
 * GitLab REST API v4 and caches the result.
 *
 * Expected GitLab problems end up as a GitLabException carrying a translated
 * message. While a previous successful response is still cached, that response
 * is returned instead of an error, so a short GitLab outage does not empty the
 * widget.
 */
class GitLabClient {
	public const SHORT_SHA_LENGTH = 8;

	/** The fallback copy of the last successful result is kept for one day. */
	private const STALE_CACHE_TTL = 86400;

	private const REQUEST_TIMEOUT = 10;
	private const CONNECT_TIMEOUT = 5;

	public function __construct(
		private IClientService $clientService,
		private ICacheFactory $cacheFactory,
		private Configuration $configuration,
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Latest commits of the configured project and branch.
	 *
	 * @return array<int, array{title: string, subtitle: string, link: string, since: string, iconUrl: string, date: string}>
	 * @throws GitLabException when GitLab could not be queried and no cached result is available
	 */
	public function getLatestCommits(int $limit): array {
		$limit = max(Configuration::MIN_COMMITS_LIMIT, min($limit, Configuration::MAX_COMMITS_LIMIT));
		$cache = $this->getCache();
		$cacheKey = $this->getCacheKey($limit);

		$commits = $cache->get($cacheKey);
		if (!is_array($commits)) {
			try {
				$commits = $this->fetchCommits($limit);
			} catch (GitLabException $exception) {
				$commits = $cache->get($this->getStaleCacheKey($limit));
				if (!is_array($commits)) {
					throw $exception;
				}

				$this->logger->info(
					'Serving the last known GitLab commits because GitLab is currently unavailable.',
					['app' => Configuration::APP_ID],
				);
			}

			$cache->set($cacheKey, $commits, $this->configuration->getCacheTtl());
			$cache->set($this->getStaleCacheKey($limit), $commits, self::STALE_CACHE_TTL);
		}

		$items = [];
		foreach ($commits as $commit) {
			if (is_array($commit)) {
				$items[] = $this->toWidgetItem($commit);
			}
		}

		return $items;
	}

	/**
	 * @return array<int, array{title: string, author: string, sha: string, date: string, link: string, iconUrl: string}>
	 * @throws GitLabException
	 */
	private function fetchCommits(int $limit): array {
		$url = $this->buildCommitsUrl();
		if ($url === '') {
			throw new GitLabException($this->l10n->t('GitLab is not configured.'));
		}

		$query = ['per_page' => $limit];
		$branch = $this->configuration->getGitLabBranch();
		if ($branch !== '') {
			$query['ref_name'] = $branch;
		}

		$options = [
			'headers' => [
				'PRIVATE-TOKEN' => $this->configuration->getGitLabToken(),
				'Accept' => 'application/json',
			],
			'query' => $query,
			'timeout' => self::REQUEST_TIMEOUT,
			'connect_timeout' => self::CONNECT_TIMEOUT,
		];

		try {
			$response = $this->clientService->newClient()->get($url, $options);
		} catch (Throwable $throwable) {
			$this->logger->error(
				'Could not reach GitLab: ' . $throwable->getMessage(),
				[
					'app' => Configuration::APP_ID,
					'exception' => $throwable,
				],
			);

			throw new GitLabException(
				$this->l10n->t('Could not reach GitLab. Please check the base URL and the network connection.'),
			);
		}

		$statusCode = $response->getStatusCode();
		if ($statusCode === 401 || $statusCode === 403) {
			$this->logger->warning(
				'GitLab rejected the request with HTTP status ' . $statusCode . '.',
				['app' => Configuration::APP_ID],
			);

			throw new GitLabException(
				$this->l10n->t('GitLab rejected the request. Please check the personal access token.'),
			);
		}

		if ($statusCode >= 400) {
			$this->logger->error(
				'GitLab request failed with HTTP status ' . $statusCode . '.',
				['app' => Configuration::APP_ID],
			);

			throw new GitLabException(
				$this->l10n->t('GitLab returned the HTTP status {status}.', ['status' => (string)$statusCode]),
			);
		}

		$body = $response->getBody();
		if (is_resource($body)) {
			$body = stream_get_contents($body);
		}

		try {
			$decoded = json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $throwable) {
			$this->logger->error(
				'GitLab returned a response that is not valid JSON.',
				[
					'app' => Configuration::APP_ID,
					'exception' => $throwable,
				],
			);

			throw new GitLabException($this->l10n->t('GitLab returned an unexpected response.'));
		}

		if (!is_array($decoded)) {
			throw new GitLabException($this->l10n->t('GitLab returned an unexpected response.'));
		}

		if (isset($decoded['message']) && !isset($decoded[0])) {
			// GitLab answered with an error object, e.g. {"message": "404 Project Not Found"}.
			$this->logger->error(
				'GitLab returned an error: ' . (is_string($decoded['message']) ? $decoded['message'] : 'unknown error'),
				['app' => Configuration::APP_ID],
			);

			throw new GitLabException($this->l10n->t('GitLab returned an unexpected response.'));
		}

		$commits = [];
		foreach ($decoded as $commit) {
			if (!is_array($commit)) {
				continue;
			}

			$normalized = $this->normalizeCommit($commit);
			if ($normalized !== null) {
				$commits[] = $normalized;
			}
		}

		return array_slice($commits, 0, $limit);
	}

	/**
	 * Reduces one GitLab commit object to the values the widget needs.
	 *
	 * The relative age is intentionally not cached: it is calculated in
	 * toWidgetItem(), so a cached commit does not keep showing "5 minutes ago"
	 * an hour later.
	 *
	 * @param array<string, mixed> $commit
	 * @return array{title: string, author: string, sha: string, date: string, link: string, iconUrl: string}|null
	 */
	private function normalizeCommit(array $commit): ?array {
		$sha = is_string($commit['id'] ?? null) ? $commit['id'] : '';
		if ($sha === '') {
			return null;
		}

		$title = $this->firstLine(is_string($commit['message'] ?? null) ? $commit['message'] : '');
		if ($title === '') {
			$title = is_string($commit['title'] ?? null) ? trim($commit['title']) : '';
		}
		if ($title === '') {
			$title = $this->l10n->t('(no commit message)');
		}

		$author = is_string($commit['author_name'] ?? null) ? trim($commit['author_name']) : '';
		if ($author === '') {
			$author = is_string($commit['committer_name'] ?? null) ? trim($commit['committer_name']) : '';
		}

		$date = is_string($commit['committed_date'] ?? null) ? $commit['committed_date'] : '';
		if ($date === '' && is_string($commit['created_at'] ?? null)) {
			$date = $commit['created_at'];
		}

		$avatarUrl = is_string($commit['author_avatar_url'] ?? null) ? trim($commit['author_avatar_url']) : '';

		return [
			'title' => $title,
			'author' => $author,
			'sha' => substr($sha, 0, self::SHORT_SHA_LENGTH),
			'date' => $date,
			'link' => $this->buildCommitLink($sha, $commit),
			'iconUrl' => $avatarUrl !== '' ? $avatarUrl : $this->getFallbackIconUrl(),
		];
	}

	/**
	 * @param array<string, mixed> $commit
	 * @return array{title: string, subtitle: string, link: string, since: string, iconUrl: string, date: string}
	 */
	private function toWidgetItem(array $commit): array {
		$title = is_string($commit['title'] ?? null) ? $commit['title'] : '';
		$author = is_string($commit['author'] ?? null) ? $commit['author'] : '';
		$sha = is_string($commit['sha'] ?? null) ? $commit['sha'] : '';
		$date = is_string($commit['date'] ?? null) ? $commit['date'] : '';
		$relative = $this->formatRelativeTime($date);

		$subtitleParts = [];
		if ($author !== '') {
			$subtitleParts[] = $author;
		}
		if ($sha !== '') {
			$subtitleParts[] = $sha;
		}
		if ($relative !== '') {
			$subtitleParts[] = $relative;
		}

		return [
			'title' => $title,
			'subtitle' => implode(' · ', $subtitleParts),
			'link' => is_string($commit['link'] ?? null) ? $commit['link'] : '',
			'since' => $relative,
			'iconUrl' => is_string($commit['iconUrl'] ?? null) ? $commit['iconUrl'] : '',
			'date' => $date,
		];
	}

	private function firstLine(string $message): string {
		$position = strpos($message, "\n");

		return trim($position === false ? $message : substr($message, 0, $position));
	}

	private function formatRelativeTime(string $isoDate): string {
		if ($isoDate === '') {
			return '';
		}

		try {
			$date = new DateTimeImmutable($isoDate);
		} catch (Throwable) {
			return '';
		}

		$seconds = (new DateTimeImmutable('now'))->getTimestamp() - $date->getTimestamp();
		if ($seconds < 0) {
			$seconds = 0;
		}

		if ($seconds < 60) {
			return $this->l10n->t('just now');
		}
		if ($seconds < 3600) {
			return $this->l10n->n('%n minute ago', '%n minutes ago', intdiv($seconds, 60));
		}
		if ($seconds < 86400) {
			return $this->l10n->n('%n hour ago', '%n hours ago', intdiv($seconds, 3600));
		}

		return $this->l10n->n('%n day ago', '%n days ago', intdiv($seconds, 86400));
	}

	/**
	 * GET {base_url}/api/v4/projects/{id}/repository/commits
	 */
	private function buildCommitsUrl(): string {
		$baseUrl = $this->configuration->getGitLabUrl();
		$project = $this->configuration->getGitLabProject();
		if ($baseUrl === '' || $project === '') {
			return '';
		}

		// A project may be configured by id ("23") or by path, which GitLab
		// expects URL encoded ("group%2Fproject").
		$project = rawurldecode($project);
		$projectId = ctype_digit($project) ? $project : rawurlencode($project);

		return $baseUrl . '/api/v4/projects/' . $projectId . '/repository/commits';
	}

	/**
	 * @param array<string, mixed> $commit
	 */
	private function buildCommitLink(string $sha, array $commit): string {
		$webUrl = is_string($commit['web_url'] ?? null) ? trim($commit['web_url']) : '';
		if ($webUrl !== '') {
			return $webUrl;
		}

		$baseUrl = $this->configuration->getGitLabUrl();
		$project = $this->configuration->getGitLabProject();
		if ($baseUrl === '' || $sha === '' || $project === '' || ctype_digit($project)) {
			return $baseUrl;
		}

		return $baseUrl . '/' . ltrim(rawurldecode($project), '/') . '/-/commit/' . $sha;
	}

	private function getFallbackIconUrl(): string {
		try {
			return $this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->imagePath(Configuration::APP_ID, 'gitlab.svg'),
			);
		} catch (Throwable) {
			return '';
		}
	}

	private function getCacheKey(int $limit): string {
		return 'commits_' . $this->getCacheKeySuffix($limit);
	}

	private function getStaleCacheKey(int $limit): string {
		return 'commits_stale_' . $this->getCacheKeySuffix($limit);
	}

	/**
	 * The cache key has to change whenever the project or the branch changes,
	 * otherwise a branch switch would show the commits of the old branch until
	 * the cached entry expires.
	 */
	private function getCacheKeySuffix(int $limit): string {
		return md5(implode('|', [
			$this->configuration->getGitLabUrl(),
			$this->configuration->getGitLabProject(),
			$this->configuration->getGitLabBranch(),
			(string)$limit,
		]));
	}

	private function getCache(): ICache {
		if ($this->cacheFactory->isAvailable()) {
			return $this->cacheFactory->createDistributed(Configuration::APP_ID);
		}

		return $this->cacheFactory->createLocal(Configuration::APP_ID);
	}
}
