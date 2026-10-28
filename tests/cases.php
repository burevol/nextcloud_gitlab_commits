<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 GitLab commits contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Behaviour tests for the gitlab_commits app, executed with a stand-in for the
 * Nextcloud server (see stubs.php).
 */

use OCA\GitLabCommits\AppInfo\Application;
use OCA\GitLabCommits\Controller\SettingsController;
use OCA\GitLabCommits\Dashboard\GitLabCommitsWidget;
use OCA\GitLabCommits\Service\Configuration;
use OCA\GitLabCommits\Service\GitLabClient;
use OCA\GitLabCommits\Service\GitLabException;
use OCA\GitLabCommits\Settings\AdminSettings;

use Tests\ArrayL10N;
use Tests\ArrayLogger;
use Tests\Assert;
use Tests\FakeCacheFactory;
use Tests\FakeClient;
use Tests\FakeClientService;
use Tests\FakeConfig;
use Tests\FakeRegistrationContext;
use Tests\FakeRequest;
use Tests\FakeResponse;
use Tests\FakeUrlGenerator;

const TOKEN = 'glpat-SECRETTOKEN1234567890';

$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;

function test(string $name, callable $fn): void {
	try {
		$fn();
		$GLOBALS['passed']++;
		echo "PASS  $name\n";
	} catch (Throwable $exception) {
		$GLOBALS['failed']++;
		echo "FAIL  $name\n      " . $exception->getMessage() . "\n";
	}
}

function makeL10n(string $language = 'en'): ArrayL10N {
	return new ArrayL10N(APP_ROOT . '/l10n/' . $language . '.json', $language);
}

function baseConfigValues(array $overrides = []): array {
	return array_merge([
		'gitlab_url' => 'https://gitlab.example.com',
		'gitlab_token' => TOKEN,
		'gitlab_project' => 'group/subgroup/project',
		'gitlab_branch' => 'main',
		'cache_ttl' => '60',
		'commits_limit' => '7',
	], $overrides);
}

function makeConfig(array $overrides = []): FakeConfig {
	$config = new FakeConfig();
	$config->values['gitlab_commits'] = baseConfigValues($overrides);

	return $config;
}

/**
 * @return array{0: GitLabClient, 1: FakeClient, 2: FakeCacheFactory, 3: ArrayLogger, 4: FakeConfig}
 */
function makeClient(string $language = 'en', array $configOverrides = []): array {
	$config = makeConfig($configOverrides);
	$client = new FakeClient();
	$factory = new FakeCacheFactory();
	$logger = new ArrayLogger();

	$service = new GitLabClient(
		new FakeClientService($client),
		$factory,
		new Configuration($config),
		makeL10n($language),
		new FakeUrlGenerator(),
		$logger,
	);

	return [$service, $client, $factory, $logger, $config];
}

function commit(string $sha, string $message, string $author, string $date, string $webUrl = ''): array {
	return [
		'id' => $sha,
		'short_id' => substr($sha, 0, 8),
		'created_at' => $date,
		'title' => explode("\n", $message)[0],
		'message' => $message,
		'author_name' => $author,
		'author_email' => 'author@example.com',
		'authored_date' => $date,
		'committer_name' => 'GitLab',
		'committer_email' => 'noreply@example.com',
		'committed_date' => $date,
		'web_url' => $webUrl,
	];
}

const OLD_DATE = '2020-01-02T03:04:05.000+03:00';

function sampleCommits(): array {
	return [
		commit(
			'807413c17e0b3e31bdf6c8ebc147e368aa60c987',
			"Merge branch '636076-cleanup' into 'master' \n\nRemove feature flag and make flows GA\n\nSee merge request !1",
			'Bala Kumar',
			OLD_DATE,
			'https://gitlab.example.com/group/subgroup/project/-/commit/807413c17e0b3e31bdf6c8ebc147e368aa60c987',
		),
		commit(
			'0123456789abcdef0123456789abcdef01234567',
			'Fix the widget',
			'Jane Doe',
			OLD_DATE,
			'https://gitlab.example.com/group/subgroup/project/-/commit/0123456789abcdef0123456789abcdef01234567',
		),
	];
}

function jsonResponse(array $data, int $status = 200): FakeResponse {
	return new FakeResponse((string)json_encode($data), $status);
}

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

test('configuration defaults and clamping', function (): void {
	$config = new FakeConfig();
	$configuration = new Configuration($config);

	Assert::same('main', $configuration->getGitLabBranch());
	Assert::same(60, $configuration->getCacheTtl());
	Assert::same(7, $configuration->getCommitsLimit());
	Assert::false($configuration->isConfigured());
	Assert::same(null, $configuration->getProjectWebUrl());

	$config->values['gitlab_commits'] = [
		'gitlab_url' => 'https://gitlab.example.com/',
		'gitlab_token' => TOKEN,
		'gitlab_project' => 'group/project',
		'gitlab_branch' => 'dev',
		'cache_ttl' => '999999',
		'commits_limit' => '0',
	];

	Assert::same('https://gitlab.example.com', $configuration->getGitLabUrl());
	Assert::true($configuration->isConfigured());
	Assert::same('dev', $configuration->getGitLabBranch());
	Assert::same(Configuration::MAX_CACHE_TTL, $configuration->getCacheTtl());
	Assert::same(Configuration::MIN_COMMITS_LIMIT, $configuration->getCommitsLimit());
	Assert::same('https://gitlab.example.com/group/project', $configuration->getProjectWebUrl());

	$config->values['gitlab_commits']['gitlab_branch'] = '   ';
	Assert::same('main', $configuration->getGitLabBranch());

	$config->values['gitlab_commits']['gitlab_project'] = 'group%2Fsub%2Fproject';
	Assert::same('https://gitlab.example.com/group/sub/project', $configuration->getProjectWebUrl());

	$config->values['gitlab_commits']['gitlab_project'] = '42';
	Assert::same(null, $configuration->getProjectWebUrl());
});

test('configuration is not configured without a token', function (): void {
	$configuration = new Configuration(makeConfig(['gitlab_token' => '']));

	Assert::false($configuration->isConfigured());
	Assert::false($configuration->hasGitLabToken());
});

// ---------------------------------------------------------------------------
// GitLabClient: request shape, normalization, caching, errors
// ---------------------------------------------------------------------------

test('client sends a GitLab API v4 request with ref_name and private token', function (): void {
	[$service, $client] = makeClient();
	$client->queueResponse(jsonResponse(sampleCommits()));

	$service->getLatestCommits(7);

	$request = $client->requests[0];
	Assert::same(
		'https://gitlab.example.com/api/v4/projects/group%2Fsubgroup%2Fproject/repository/commits',
		$request['uri'],
	);
	Assert::same(7, $request['options']['query']['per_page']);
	Assert::same('main', $request['options']['query']['ref_name']);
	Assert::same(TOKEN, $request['options']['headers']['PRIVATE-TOKEN']);
	Assert::same('application/json', $request['options']['headers']['Accept']);
	Assert::same(10, $request['options']['timeout']);
	Assert::same(5, $request['options']['connect_timeout']);
});

test('client normalizes commits into widget items', function (): void {
	[$service, $client] = makeClient();
	$client->queueResponse(jsonResponse(sampleCommits()));

	$items = $service->getLatestCommits(7);

	Assert::same(2, count($items));
	Assert::same("Merge branch '636076-cleanup' into 'master'", $items[0]['title']);
	Assert::contains('Bala Kumar', $items[0]['subtitle']);
	Assert::contains('807413c1', $items[0]['subtitle']);
	Assert::contains('ago', $items[0]['subtitle']);
	Assert::same(
		'https://gitlab.example.com/group/subgroup/project/-/commit/807413c17e0b3e31bdf6c8ebc147e368aa60c987',
		$items[0]['link'],
	);
	Assert::same(OLD_DATE, $items[0]['date']);
	Assert::same(
		'https://cloud.example.com/custom_apps/gitlab_commits/img/gitlab.svg',
		$items[0]['iconUrl'],
	);
	Assert::same('Fix the widget', $items[1]['title']);
});

test('client builds the commit link from the project path when web_url is missing', function (): void {
	[$service, $client] = makeClient();
	$commits = sampleCommits();
	unset($commits[0]['web_url']);
	$client->queueResponse(jsonResponse($commits));

	$items = $service->getLatestCommits(7);

	Assert::same(
		'https://gitlab.example.com/group/subgroup/project/-/commit/807413c17e0b3e31bdf6c8ebc147e368aa60c987',
		$items[0]['link'],
	);
});

test('client supports a numeric project id', function (): void {
	[$service, $client] = makeClient('en', ['gitlab_project' => '42']);
	$commits = sampleCommits();
	unset($commits[0]['web_url']);
	$client->queueResponse(jsonResponse($commits));

	$items = $service->getLatestCommits(7);

	Assert::same('https://gitlab.example.com/api/v4/projects/42/repository/commits', $client->requests[0]['uri']);
	Assert::same('https://gitlab.example.com', $items[0]['link']);
});

test('client caches the result and does not request GitLab twice', function (): void {
	[$service, $client, $factory] = makeClient();
	$client->queueResponse(jsonResponse(sampleCommits()));

	$first = $service->getLatestCommits(7);
	$second = $service->getLatestCommits(7);

	Assert::same(1, count($client->requests));
	Assert::same($first, $second);

	$cache = $factory->getCache('gitlab_commits');
	Assert::same(2, $cache->setCount);
	foreach ($cache->ttls as $key => $ttl) {
		if (strpos((string)$key, 'commits_stale_') === 0) {
			Assert::same(86400, $ttl, 'stale copy ttl');
		} else {
			Assert::same(60, $ttl, 'cache ttl from the configuration');
		}
	}
});

test('client uses the configured cache ttl', function (): void {
	[$service, $client, $factory] = makeClient('en', ['cache_ttl' => '120']);
	$client->queueResponse(jsonResponse(sampleCommits()));

	$service->getLatestCommits(7);

	$cache = $factory->getCache('gitlab_commits');
	foreach ($cache->ttls as $key => $ttl) {
		if (strpos((string)$key, 'commits_stale_') !== 0) {
			Assert::same(120, $ttl);
		}
	}
});

test('client returns the stale copy when GitLab stops answering', function (): void {
	[$service, $client, $factory, $logger] = makeClient();
	$client->queueResponse(jsonResponse(sampleCommits()));
	$first = $service->getLatestCommits(7);

	// Simulate the TTL expiry of the primary entry, keeping the stale copy.
	$cache = $factory->getCache('gitlab_commits');
	foreach ($cache->keysMatching('commits_') as $key) {
		if (strpos($key, 'commits_stale_') !== 0) {
			$cache->remove($key);
		}
	}

	$client->queueFailure(new RuntimeException('cURL error 28: Operation timed out'));
	$second = $service->getLatestCommits(7);

	Assert::same(2, count($client->requests));
	Assert::same($first, $second);
	Assert::same(1, count($logger->messagesAtLevel('info')));
	Assert::contains('Serving the last known', $logger->messagesAtLevel('info')[0]);
});

test('client never leaks the token into the log', function (): void {
	[$service, $client, , $logger] = makeClient();
	$client->queueFailure(new RuntimeException('cURL error 28: Operation timed out'));

	try {
		$service->getLatestCommits(7);
		Assert::true(false, 'expected a GitLabException');
	} catch (GitLabException $exception) {
		Assert::notContains(TOKEN, $exception->getMessage());
	}

	Assert::notContains(TOKEN, $logger->allMessages());
	Assert::notContains(TOKEN, (string)json_encode($logger->records));
});

test('client maps 401 to a token error and does not cache it', function (): void {
	[$service, $client, $factory, $logger] = makeClient();
	$client->queueResponse(new FakeResponse('{"message":"401 Unauthorized"}', 401));

	try {
		$service->getLatestCommits(7);
		Assert::true(false, 'expected a GitLabException');
	} catch (GitLabException $exception) {
		Assert::contains('personal access token', $exception->getUserMessage());
	}

	$cache = $factory->getCache('gitlab_commits');
	Assert::same([], $cache->keysMatching('commits_'));
	Assert::contains('401', $logger->messagesAtLevel('warning')[0]);
	Assert::notContains(TOKEN, (string)json_encode($logger->records));
});

test('client maps 403 to a token error', function (): void {
	[$service, $client] = makeClient();
	$client->queueResponse(new FakeResponse('{"message":"403 Forbidden"}', 403));

	try {
		$service->getLatestCommits(7);
		Assert::true(false, 'expected a GitLabException');
	} catch (GitLabException $exception) {
		Assert::contains('personal access token', $exception->getUserMessage());
	}
});

test('client maps other HTTP errors to a status message', function (): void {
	[$service, $client] = makeClient();
	$client->queueResponse(new FakeResponse('', 500));

	try {
		$service->getLatestCommits(7);
		Assert::true(false, 'expected a GitLabException');
	} catch (GitLabException $exception) {
		Assert::contains('500', $exception->getUserMessage());
	}
});

test('client maps an unreachable GitLab to a network message', function (): void {
	[$service, $client, , $logger] = makeClient();
	$client->queueFailure(new RuntimeException('Could not resolve host: gitlab.example.com'));

	try {
		$service->getLatestCommits(7);
		Assert::true(false, 'expected a GitLabException');
	} catch (GitLabException $exception) {
		Assert::contains('Could not reach GitLab', $exception->getUserMessage());
	}

	Assert::same(1, count($logger->messagesAtLevel('error')));
});

test('client maps invalid JSON to an unexpected response message', function (): void {
	[$service, $client, , $logger] = makeClient();
	$client->queueResponse(new FakeResponse('<html>502 Bad Gateway</html>'));

	try {
		$service->getLatestCommits(7);
		Assert::true(false, 'expected a GitLabException');
	} catch (GitLabException $exception) {
		Assert::contains('unexpected response', $exception->getUserMessage());
	}

	Assert::contains('not valid JSON', $logger->messagesAtLevel('error')[0]);
});

test('client maps a GitLab error object to an unexpected response message', function (): void {
	[$service, $client, , $logger] = makeClient();
	$client->queueResponse(new FakeResponse('{"message":"404 Project Not Found"}'));

	try {
		$service->getLatestCommits(7);
		Assert::true(false, 'expected a GitLabException');
	} catch (GitLabException $exception) {
		Assert::contains('unexpected response', $exception->getUserMessage());
	}

	Assert::contains('404 Project Not Found', $logger->messagesAtLevel('error')[0]);
});

test('client caches per branch and refetches after a branch change', function (): void {
	[$service, $client, , , $config] = makeClient();
	$client->queueResponse(jsonResponse(sampleCommits()));
	$client->queueResponse(jsonResponse([sampleCommits()[1]]));

	$service->getLatestCommits(7);
	$config->values['gitlab_commits']['gitlab_branch'] = 'develop';
	$items = $service->getLatestCommits(7);

	Assert::same(2, count($client->requests));
	Assert::same('develop', $client->requests[1]['options']['query']['ref_name']);
	Assert::same(1, count($items));
});

test('client clamps the limit and keeps the empty result', function (): void {
	[$service, $client] = makeClient();
	$client->queueResponse(jsonResponse([]));

	$items = $service->getLatestCommits(1000);

	Assert::same([], $items);
	Assert::same(Configuration::MAX_COMMITS_LIMIT, $client->requests[0]['options']['query']['per_page']);
});

test('client ignores malformed commit entries', function (): void {
	[$service, $client] = makeClient();
	$client->queueResponse(jsonResponse([['id' => ''], 'garbage', sampleCommits()[1]]));

	$items = $service->getLatestCommits(7);

	Assert::same(1, count($items));
});

// ---------------------------------------------------------------------------
// Dashboard widget
// ---------------------------------------------------------------------------

test('widget reports a missing configuration without calling GitLab', function (): void {
	$client = new FakeClient();
	$widget = new GitLabCommitsWidget(
		makeL10n(),
		new GitLabClient(
			new FakeClientService($client),
			new FakeCacheFactory(),
			new Configuration(new FakeConfig()),
			makeL10n(),
			new FakeUrlGenerator(),
			new ArrayLogger(),
		),
		new Configuration(new FakeConfig()),
		new FakeUrlGenerator(),
	);

	$result = $widget->getItemsV2('alice');

	Assert::same([], $result->getItems());
	Assert::contains('not configured', $result->getEmptyContentMessage());
	Assert::same(0, count($client->requests));
});

test('widget maps commits to WidgetItem objects', function (): void {
	[$service, $client] = makeClient();
	$client->queueResponse(jsonResponse(sampleCommits()));
	$widget = new GitLabCommitsWidget(makeL10n(), $service, new Configuration(makeConfig()), new FakeUrlGenerator());

	$result = $widget->getItemsV2('alice', null, 7);

	$items = $result->getItems();
	Assert::same(2, count($items));
	Assert::same("Merge branch '636076-cleanup' into 'master'", $items[0]->getTitle());
	Assert::contains('Bala Kumar', $items[0]->getSubtitle());
	Assert::same(OLD_DATE, $items[0]->getSinceId());
	Assert::same(
		'https://cloud.example.com/custom_apps/gitlab_commits/img/gitlab.svg',
		$items[0]->getIconUrl(),
	);
	Assert::contains('/-/commit/807413c1', $items[0]->getLink());
	Assert::same('', $result->getEmptyContentMessage());
});

test('widget caps the requested limit by the configured limit', function (): void {
	[$service, $client] = makeClient('en', ['commits_limit' => '5']);
	$client->queueResponse(jsonResponse(sampleCommits()));
	$widget = new GitLabCommitsWidget(
		makeL10n(),
		$service,
		new Configuration(makeConfig(['commits_limit' => '5'])),
		new FakeUrlGenerator(),
	);

	$widget->getItemsV2('alice', null, 30);
	Assert::same(5, $client->requests[0]['options']['query']['per_page']);
});

test('widget respects a smaller limit requested by the dashboard', function (): void {
	[$service, $client] = makeClient('en', ['commits_limit' => '7']);
	$client->queueResponse(jsonResponse(sampleCommits()));
	$widget = new GitLabCommitsWidget(makeL10n(), $service, new Configuration(makeConfig()), new FakeUrlGenerator());

	$widget->getItemsV2('alice', null, 3);
	Assert::same(3, $client->requests[0]['options']['query']['per_page']);
});

test('widget shows the GitLab error message instead of throwing', function (): void {
	[$service, $client] = makeClient();
	$client->queueResponse(new FakeResponse('{"message":"401 Unauthorized"}', 401));
	$widget = new GitLabCommitsWidget(makeL10n(), $service, new Configuration(makeConfig()), new FakeUrlGenerator());

	$result = $widget->getItemsV2('alice');

	Assert::same([], $result->getItems());
	Assert::contains('personal access token', $result->getEmptyContentMessage());
});

test('widget shows the empty state for a project without commits', function (): void {
	[$service, $client] = makeClient();
	$client->queueResponse(jsonResponse([]));
	$widget = new GitLabCommitsWidget(makeL10n(), $service, new Configuration(makeConfig()), new FakeUrlGenerator());

	$result = $widget->getItemsV2('alice');

	Assert::same([], $result->getItems());
	Assert::same('No commits', $result->getEmptyContentMessage());
});

test('widget ignores the sinceId parameter and returns the newest commits', function (): void {
	[$service, $client] = makeClient();
	$client->queueResponse(jsonResponse(sampleCommits()));
	$client->queueResponse(jsonResponse(sampleCommits()));
	$widget = new GitLabCommitsWidget(makeL10n(), $service, new Configuration(makeConfig()), new FakeUrlGenerator());

	$result = $widget->getItemsV2('alice', OLD_DATE, 7);

	Assert::same(2, count($result->getItems()));
});

test('widget metadata and buttons', function (): void {
	[$service] = makeClient();
	$widget = new GitLabCommitsWidget(makeL10n(), $service, new Configuration(makeConfig()), new FakeUrlGenerator());

	Assert::same('gitlab_commits', $widget->getId());
	Assert::same('Latest GitLab commits', $widget->getTitle());
	Assert::same(10, $widget->getOrder());
	Assert::same('icon-gitlab-commits', $widget->getIconClass());
	Assert::same('https://cloud.example.com/custom_apps/gitlab_commits/img/gitlab.svg', $widget->getIconUrl());
	Assert::same('https://gitlab.example.com/group/subgroup/project', $widget->getUrl());

	$buttons = $widget->getWidgetButtons('alice');
	Assert::same(1, count($buttons));
	Assert::same('more', $buttons[0]->getType());
	Assert::same('https://gitlab.example.com/group/subgroup/project/-/commits', $buttons[0]->getLink());
	Assert::same('All commits in GitLab', $buttons[0]->getText());
});

test('widget has no buttons when the project is configured by id', function (): void {
	[$service] = makeClient('en', ['gitlab_project' => '42']);
	$widget = new GitLabCommitsWidget(
		makeL10n(),
		$service,
		new Configuration(makeConfig(['gitlab_project' => '42'])),
		new FakeUrlGenerator(),
	);

	Assert::same([], $widget->getWidgetButtons('alice'));
	Assert::same(null, $widget->getUrl());
});

// ---------------------------------------------------------------------------
// Administration form
// ---------------------------------------------------------------------------

test('admin form exposes every setting but never the token', function (): void {
	$settings = new AdminSettings(
		makeL10n(),
		new Configuration(makeConfig()),
		new FakeUrlGenerator(),
		new FakeRequest(),
	);

	$response = $settings->getForm();
	$params = $response->getParams();

	Assert::same('gitlab_commits', $response->appName);
	Assert::same('settings/admin', $response->templateName);
	Assert::same('https://gitlab.example.com', $params['gitlab_url']);
	Assert::same('group/subgroup/project', $params['gitlab_project']);
	Assert::same('main', $params['gitlab_branch']);
	Assert::same(60, $params['cache_ttl']);
	Assert::same(7, $params['commits_limit']);
	Assert::same(true, $params['token_is_set']);
	Assert::same('test-request-token', $params['requesttoken']);
	Assert::contains('gitlab_commits', $params['action']);
	Assert::notContains(TOKEN, (string)json_encode($params));

	Assert::same('additional', $settings->getSection());
	Assert::same(50, $settings->getPriority());
	Assert::same('GitLab commits', $settings->getName());
	Assert::same(6, count($settings->getAuthorizedAppConfig()['gitlab_commits']));
});

test('admin form shows the save result of a redirect', function (): void {
	$settings = new AdminSettings(
		makeL10n(),
		new Configuration(makeConfig()),
		new FakeUrlGenerator(),
		new FakeRequest(['gitlab_commits_status' => 'saved']),
	);
	Assert::same('Settings saved.', $settings->getForm()->getParams()['success_message']);

	$settings = new AdminSettings(
		makeL10n(),
		new Configuration(makeConfig()),
		new FakeUrlGenerator(),
		new FakeRequest([
			'gitlab_commits_status' => 'error',
			'gitlab_commits_error' => 'invalid_url',
		]),
	);
	$params = $settings->getForm()->getParams();
	Assert::same('', $params['success_message']);
	Assert::contains('valid base URL', $params['error_message']);
});

test('admin form placeholder depends on the stored token', function (): void {
	$settings = new AdminSettings(
		makeL10n(),
		new Configuration(makeConfig(['gitlab_token' => ''])),
		new FakeUrlGenerator(),
		new FakeRequest(),
	);
	$params = $settings->getForm()->getParams();

	Assert::false($params['token_is_set']);
	Assert::same('glpat-...', $params['token_placeholder']);
});

test('admin form loads its stylesheet', function (): void {
	$settings = new AdminSettings(
		makeL10n(),
		new Configuration(makeConfig()),
		new FakeUrlGenerator(),
		new FakeRequest(),
	);
	$settings->getForm();

	Assert::true(in_array(['gitlab_commits', 'settings'], \OCP\Util::getStyles(), true), 'settings css');
});

// ---------------------------------------------------------------------------
// Settings controller
// ---------------------------------------------------------------------------

function makeController(FakeRequest $request, array $configOverrides = [], ?FakeCacheFactory $factory = null): array {
	$config = makeConfig($configOverrides);
	$factory = $factory ?? new FakeCacheFactory();
	$logger = new ArrayLogger();
	$controller = new SettingsController(
		'gitlab_commits',
		$request,
		new Configuration($config),
		new FakeUrlGenerator(),
		$factory,
		$logger,
	);

	return [$controller, $config, $factory, $logger];
}

test('controller stores valid values and clears the cache', function (): void {
	[$controller, $config, $factory] = makeController(new FakeRequest([
		'gitlab_url' => 'https://gitlab.example.com/api/v4/',
		'gitlab_project' => 'group/project',
		'gitlab_branch' => '',
		'gitlab_token' => '',
		'cache_ttl' => '120',
		'commits_limit' => '5',
	]));

	$response = $controller->save();

	Assert::same('/settings/admin/additional?gitlab_commits_status=saved', $response->getRedirectUrl());
	Assert::same('https://gitlab.example.com', $config->values['gitlab_commits']['gitlab_url']);
	Assert::same('group/project', $config->values['gitlab_commits']['gitlab_project']);
	Assert::same('main', $config->values['gitlab_commits']['gitlab_branch']);
	Assert::same('120', $config->values['gitlab_commits']['cache_ttl']);
	Assert::same('5', $config->values['gitlab_commits']['commits_limit']);
	Assert::same(TOKEN, $config->values['gitlab_commits']['gitlab_token']);
	Assert::same(1, $factory->getCache('gitlab_commits')->clearCount);
});

test('controller stores a new token and can remove it', function (): void {
	[$controller, $config] = makeController(new FakeRequest([
		'gitlab_url' => 'https://gitlab.example.com',
		'gitlab_project' => 'group/project',
		'gitlab_token' => 'glpat-NEWTOKEN0987654321',
	]));
	$controller->save();
	Assert::same('glpat-NEWTOKEN0987654321', $config->values['gitlab_commits']['gitlab_token']);

	[$controller, $config] = makeController(new FakeRequest([
		'gitlab_url' => 'https://gitlab.example.com',
		'gitlab_project' => 'group/project',
		'gitlab_token' => '',
		'gitlab_token_remove' => '1',
	]));
	$controller->save();
	Assert::same('', $config->values['gitlab_commits']['gitlab_token']);
});

test('controller rejects an invalid base URL', function (): void {
	[$controller, $config] = makeController(new FakeRequest([
		'gitlab_url' => 'ftp://gitlab.example.com',
		'gitlab_project' => 'group/project',
	]));

	$response = $controller->save();

	Assert::same(
		'/settings/admin/additional?gitlab_commits_status=error&gitlab_commits_error=invalid_url',
		$response->getRedirectUrl(),
	);
	Assert::same('https://gitlab.example.com', $config->values['gitlab_commits']['gitlab_url']);
});

test('controller rejects out of range numeric settings', function (): void {
	[$controller, $config] = makeController(new FakeRequest([
		'gitlab_url' => 'https://gitlab.example.com',
		'gitlab_project' => 'group/project',
		'cache_ttl' => '0',
		'commits_limit' => '5',
	]));
	$response = $controller->save();
	Assert::contains('gitlab_commits_error=invalid_ttl', $response->getRedirectUrl());
	Assert::same('60', $config->values['gitlab_commits']['cache_ttl']);

	[$controller, $config] = makeController(new FakeRequest([
		'gitlab_url' => 'https://gitlab.example.com',
		'gitlab_project' => 'group/project',
		'cache_ttl' => '60',
		'commits_limit' => '99',
	]));
	$response = $controller->save();
	Assert::contains('gitlab_commits_error=invalid_limit', $response->getRedirectUrl());
	Assert::same('7', $config->values['gitlab_commits']['commits_limit']);
});

test('controller falls back to the defaults for empty numeric fields', function (): void {
	[$controller, $config] = makeController(new FakeRequest([
		'gitlab_url' => 'https://gitlab.example.com',
		'gitlab_project' => 'group/project',
		'cache_ttl' => '',
		'commits_limit' => '',
	]));

	$response = $controller->save();

	Assert::contains('gitlab_commits_status=saved', $response->getRedirectUrl());
	Assert::same('60', $config->values['gitlab_commits']['cache_ttl']);
	Assert::same('7', $config->values['gitlab_commits']['commits_limit']);
});

// ---------------------------------------------------------------------------
// Application registration
// ---------------------------------------------------------------------------

test('application registers the widget and the sensitive methods', function (): void {
	$context = new FakeRegistrationContext();
	$application = new Application();
	$application->register($context);

	Assert::same([GitLabCommitsWidget::class], $context->widgets);
	Assert::same(1, count($context->sensitive));
	Assert::same(Configuration::class, $context->sensitive[0]['class']);
	Assert::true(in_array('getGitLabToken', $context->sensitive[0]['methods'], true));

	$application->boot(new class implements \OCP\AppFramework\Bootstrap\IBootContext {
	});

	Assert::true(
		in_array(['gitlab_commits', 'dashboard'], \OCP\Util::getStyles(), true),
		'dashboard css is registered',
	);
});

// ---------------------------------------------------------------------------
// Translations
// ---------------------------------------------------------------------------

test('english messages are translated', function (): void {
	$l10n = makeL10n('en');

	Assert::same('Latest GitLab commits', $l10n->t('Latest GitLab commits'));
	Assert::same('1 minute ago', $l10n->n('%n minute ago', '%n minutes ago', 1));
	Assert::same('3 minutes ago', $l10n->n('%n minute ago', '%n minutes ago', 3));
	Assert::same('2 hours ago', $l10n->n('%n hour ago', '%n hours ago', 2));
	Assert::same('5 days ago', $l10n->n('%n day ago', '%n days ago', 5));
	Assert::contains('500', $l10n->t('GitLab returned the HTTP status {status}.', ['status' => '500']));
});

test('russian messages are translated', function (): void {
	$l10n = makeL10n('ru');

	Assert::same('Последние коммиты GitLab', $l10n->t('Latest GitLab commits'));
	Assert::same('Нет коммитов', $l10n->t('No commits'));
	Assert::same('1 минуту назад', $l10n->n('%n minute ago', '%n minutes ago', 1));
	Assert::same('2 минуты назад', $l10n->n('%n minute ago', '%n minutes ago', 2));
	Assert::same('5 минут назад', $l10n->n('%n minute ago', '%n minutes ago', 5));
	Assert::same('21 минуту назад', $l10n->n('%n minute ago', '%n minutes ago', 21));
	Assert::same('3 часа назад', $l10n->n('%n hour ago', '%n hours ago', 3));
	Assert::same('11 часов назад', $l10n->n('%n hour ago', '%n hours ago', 11));
	Assert::same('4 дня назад', $l10n->n('%n day ago', '%n days ago', 4));
	Assert::contains('500', $l10n->t('GitLab returned the HTTP status {status}.', ['status' => '500']));
});

test('user facing messages have no english fallback left in russian', function (): void {
	$english = makeL10n('en');
	$russian = makeL10n('ru');

	$cases = [
		['GitLab is not configured.', null],
		['GitLab rejected the request. Please check the personal access token.', null],
		['GitLab returned an unexpected response.', null],
		['Could not reach GitLab. Please check the base URL and the network connection.', null],
		['GitLab returned the HTTP status {status}.', ['status' => '503']],
		['No commits', null],
		['just now', null],
		['All commits in GitLab', null],
		['Latest GitLab commits', null],
		['Settings saved.', null],
		['The cache lifetime has to be between {min} and {max} seconds.', ['min' => '1', 'max' => '86400']],
		['The number of commits has to be between {min} and {max}.', ['min' => '1', 'max' => '30']],
	];

	foreach ($cases as [$text, $parameters]) {
		$ru = $parameters === null ? $russian->t($text) : $russian->t($text, $parameters);
		Assert::true($russian->has($text), 'missing russian catalogue entry: ' . $text);
		Assert::true(preg_match('/[А-Яа-яЁё]/u', $ru) === 1, 'translation contains no russian text: ' . $text . ' => ' . $ru);
	}
});

test('widget messages are translated at runtime', function (): void {
	$english = makeL10n('en');
	$russian = makeL10n('ru');

	[$ruService, $ruClient] = makeClient('ru');
	$ruClient->queueResponse(jsonResponse([]));
	$ruWidget = new GitLabCommitsWidget($russian, $ruService, new Configuration(makeConfig()), new FakeUrlGenerator());
	Assert::same('Нет коммитов', $ruWidget->getItemsV2('alice')->getEmptyContentMessage());
	Assert::same('Все коммиты в GitLab', $ruWidget->getWidgetButtons('alice')[0]->getText());
	Assert::same('Последние коммиты GitLab', $ruWidget->getTitle());

	[$enService, $enClient] = makeClient('en');
	$enClient->queueResponse(jsonResponse([]));
	$enWidget = new GitLabCommitsWidget($english, $enService, new Configuration(makeConfig()), new FakeUrlGenerator());
	Assert::same('No commits', $enWidget->getItemsV2('alice')->getEmptyContentMessage());

	// The 401 message has to be translated as well, coming from the client.
	[$ruService, $ruClient] = makeClient('ru');
	$ruClient->queueResponse(new FakeResponse('{"message":"401"}', 401));
	$ruWidget = new GitLabCommitsWidget($russian, $ruService, new Configuration(makeConfig()), new FakeUrlGenerator());
	$message = $ruWidget->getItemsV2('alice')->getEmptyContentMessage();
	Assert::contains('personal access token', $message);
	Assert::contains('GitLab отклонил запрос', $message);
});

test('relative time is recalculated from the cached date', function (): void {
	[$service, $client] = makeClient();
	$recent = commit(
		'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
		'Just landed',
		'Alice',
		(new DateTimeImmutable('-5 seconds'))->format('c'),
		'https://gitlab.example.com/group/project/-/commit/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
	);
	$client->queueResponse(jsonResponse([$recent]));

	$items = $service->getLatestCommits(7);

	Assert::same('just now', $items[0]['since']);
	Assert::contains('· just now', $items[0]['subtitle']);
});

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

$failed = (int)$GLOBALS['failed'];
$passed = (int)$GLOBALS['passed'];
echo "\n$passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);
