<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 GitLab commits contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GitLabCommits\Settings;

use OCA\GitLabCommits\Service\Configuration;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * Administration form for the GitLab connection.
 *
 * The form is rendered server side and posts to the settings controller, so it
 * works without any JavaScript. The token is never rendered: the form only
 * learns whether a token is stored.
 */
class AdminSettings implements IDelegatedSettings {
	public const STATUS_SAVED = 'saved';
	public const STATUS_ERROR = 'error';

	public const ERROR_INVALID_URL = 'invalid_url';
	public const ERROR_INVALID_TTL = 'invalid_ttl';
	public const ERROR_INVALID_LIMIT = 'invalid_limit';

	public function __construct(
		private IL10N $l10n,
		private Configuration $configuration,
		private IURLGenerator $urlGenerator,
		private IRequest $request,
	) {
	}

	public function getForm(): TemplateResponse {
		Util::addStyle(Configuration::APP_ID, 'settings');

		return new TemplateResponse(
			Configuration::APP_ID,
			'settings/admin',
			$this->getFormParameters(),
			'',
		);
	}

	public function getSection(): string {
		return 'additional';
	}

	public function getPriority(): int {
		return 50;
	}

	public function getName(): ?string {
		return $this->l10n->t('GitLab commits');
	}

	/**
	 * @return array<string, string[]>
	 */
	public function getAuthorizedAppConfig(): array {
		return [
			Configuration::APP_ID => [
				'/gitlab_url/',
				'/gitlab_token/',
				'/gitlab_project/',
				'/gitlab_branch/',
				'/cache_ttl/',
				'/commits_limit/',
			],
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getFormParameters(): array {
		$status = (string)$this->request->getParam('gitlab_commits_status', '');
		$error = (string)$this->request->getParam('gitlab_commits_error', '');

		return [
			'gitlab_url' => $this->configuration->getGitLabUrl(),
			'gitlab_project' => $this->configuration->getGitLabProject(),
			'gitlab_branch' => $this->configuration->getGitLabBranch(),
			'cache_ttl' => $this->configuration->getCacheTtl(),
			'commits_limit' => $this->configuration->getCommitsLimit(),
			'token_is_set' => $this->configuration->hasGitLabToken(),
			'token_placeholder' => $this->configuration->hasGitLabToken()
				? $this->l10n->t('A token is stored, leave the field empty to keep it')
				: $this->l10n->t('glpat-...'),
			'min_cache_ttl' => Configuration::MIN_CACHE_TTL,
			'max_cache_ttl' => Configuration::MAX_CACHE_TTL,
			'min_commits_limit' => Configuration::MIN_COMMITS_LIMIT,
			'max_commits_limit' => Configuration::MAX_COMMITS_LIMIT,
			'action' => $this->urlGenerator->linkToRoute(Configuration::APP_ID . '.settings.save'),
			'requesttoken' => Util::callRegister(),
			'success_message' => $status === self::STATUS_SAVED ? $this->l10n->t('Settings saved.') : '',
			'error_message' => $status === self::STATUS_ERROR ? $this->getErrorMessage($error) : '',
		];
	}

	private function getErrorMessage(string $error): string {
		return match ($error) {
			self::ERROR_INVALID_URL => $this->l10n->t('Enter a valid base URL, starting with http:// or https://.'),
			self::ERROR_INVALID_TTL => $this->l10n->t(
				'The cache lifetime has to be between {min} and {max} seconds.',
				[
					'min' => (string)Configuration::MIN_CACHE_TTL,
					'max' => (string)Configuration::MAX_CACHE_TTL,
				],
			),
			self::ERROR_INVALID_LIMIT => $this->l10n->t(
				'The number of commits has to be between {min} and {max}.',
				[
					'min' => (string)Configuration::MIN_COMMITS_LIMIT,
					'max' => (string)Configuration::MAX_COMMITS_LIMIT,
				],
			),
			default => $this->l10n->t('The settings could not be saved.'),
		};
	}
}
