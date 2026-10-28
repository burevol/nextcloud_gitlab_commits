<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 GitLab commits contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Minimal stand-ins for the Nextcloud server classes the app depends on, so the
 * app can be executed outside of a Nextcloud installation. Only the members the
 * app actually uses are implemented; signatures follow the Nextcloud 27 public
 * API.
 */

namespace Psr\Log {
	interface LoggerInterface {
		public function emergency($message, array $context = []): void;

		public function alert($message, array $context = []): void;

		public function critical($message, array $context = []): void;

		public function error($message, array $context = []): void;

		public function warning($message, array $context = []): void;

		public function notice($message, array $context = []): void;

		public function info($message, array $context = []): void;

		public function debug($message, array $context = []): void;

		public function log($level, $message, array $context = []): void;
	}
}

namespace OCP {
	interface IConfig {
		public function getAppValue(string $appName, string $key, string $default = ''): string;

		public function setAppValue(string $appName, string $key, string $value): void;

		public function deleteAppValue(string $appName, string $key): void;
	}

	interface ICache {
		public function get($key);

		public function set($key, $value, $ttl = 0);

		public function hasKey($key);

		public function remove($key);

		public function clear($prefix = ''): bool;
	}

	interface ICacheFactory {
		public function isAvailable(): bool;

		public function isLocalCacheAvailable(): bool;

		public function createDistributed(string $prefix = ''): ICache;

		public function createLocal(string $prefix = ''): ICache;
	}

	interface IL10N {
		public function t(string $text, $parameters = []): string;

		public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string;
	}

	interface IURLGenerator {
		public function linkToRoute(string $routeName, array $arguments = []): string;

		public function linkTo(string $appName, string $file, array $args = []): string;

		public function imagePath(string $appName, string $file): string;

		public function getAbsoluteURL(string $url): string;
	}

	interface IRequest {
		public function getParam(string $key, $default = null);
	}

	class Util {
		/** @var array<int, array{0: string, 1: string|null}> */
		private static array $styles = [];

		public static function addStyle($application, $file = null): void {
			self::$styles[] = [$application, $file];
		}

		/** @return array<int, array{0: string, 1: string|null}> */
		public static function getStyles(): array {
			return self::$styles;
		}

		public static function callRegister(): string {
			return 'test-request-token';
		}
	}
}

namespace OCP\AppFramework {
	use OCP\IRequest;

	class App {
		public function __construct(protected string $appName, protected array $urlParams = []) {
		}

		public function getAppName(): string {
			return $this->appName;
		}
	}

	class Controller {
		protected IRequest $request;

		public function __construct(protected string $appName, IRequest $request) {
			$this->request = $request;
		}
	}
}

namespace OCP\AppFramework\Http {
	class TemplateResponse {
		public function __construct(
			public string $appName,
			public string $templateName,
			public array $params = [],
			public string $renderAs = 'user',
			public int $statusCode = 200,
		) {
		}

		public function getParams(): array {
			return $this->params;
		}

		public function render(): string {
			return '';
		}
	}

	class RedirectResponse {
		public function __construct(private string $redirectUrl, private int $status = 303) {
		}

		public function getRedirectUrl(): string {
			return $this->redirectUrl;
		}

		public function getStatus(): int {
			return $this->status;
		}
	}
}

namespace OCP\AppFramework\Http\Attribute {
	use Attribute;

	#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
	class AuthorizedAdminSetting {
		public function __construct(protected string $settings) {
		}

		public function getSettings(): string {
			return $this->settings;
		}
	}
}

namespace OCP\AppFramework\Bootstrap {
	interface IRegistrationContext {
		public function registerDashboardWidget(string $widgetClass): void;

		public function registerSensitiveMethods(string $class, array $methods): void;
	}

	interface IBootContext {
	}

	interface IBootstrap {
		public function register(IRegistrationContext $context): void;

		public function boot(IBootContext $context): void;
	}
}

namespace OCP\Settings {
	interface ISettings {
		public function getForm();

		public function getSection();

		public function getPriority();
	}

	interface IDelegatedSettings extends ISettings {
		public function getName(): ?string;

		public function getAuthorizedAppConfig(): array;
	}
}

namespace OCP\Http\Client {
	interface IResponse {
		/**
		 * @return string|resource
		 */
		public function getBody();

		public function getStatusCode(): int;

		public function getHeader(string $key): string;

		public function getHeaders(): array;
	}

	interface IClient {
		public function get(string $uri, array $options = []): IResponse;
	}

	interface IClientService {
		public function newClient(): IClient;
	}
}

namespace OCP\Dashboard {
	interface IWidget {
		public function getId(): string;

		public function getTitle(): string;

		public function getOrder(): int;

		public function getIconClass(): string;

		public function getUrl(): ?string;

		public function load(): void;
	}

	interface IAPIWidgetV2 extends IWidget {
		public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): \OCP\Dashboard\Model\WidgetItems;
	}

	interface IIconWidget extends IWidget {
		public function getIconUrl(): string;
	}

	interface IButtonWidget extends IWidget {
		public function getWidgetButtons(string $userId): array;
	}
}

namespace OCP\Dashboard\Model {
	class WidgetItem implements \JsonSerializable {
		public function __construct(
			private string $title = '',
			private string $subtitle = '',
			private string $link = '',
			private string $iconUrl = '',
			private string $sinceId = '',
			private string $overlayIconUrl = '',
		) {
		}

		public function getTitle(): string {
			return $this->title;
		}

		public function getSubtitle(): string {
			return $this->subtitle;
		}

		public function getLink(): string {
			return $this->link;
		}

		public function getIconUrl(): string {
			return $this->iconUrl;
		}

		public function getSinceId(): string {
			return $this->sinceId;
		}

		public function getOverlayIconUrl(): string {
			return $this->overlayIconUrl;
		}

		public function jsonSerialize(): array {
			return [
				'subtitle' => $this->subtitle,
				'title' => $this->title,
				'link' => $this->link,
				'iconUrl' => $this->iconUrl,
				'overlayIconUrl' => $this->overlayIconUrl,
				'sinceId' => $this->sinceId,
			];
		}
	}

	class WidgetItems implements \JsonSerializable {
		public function __construct(
			private array $items = [],
			private string $emptyContentMessage = '',
			private string $halfEmptyContentMessage = '',
		) {
		}

		/** @return WidgetItem[] */
		public function getItems(): array {
			return $this->items;
		}

		public function getEmptyContentMessage(): string {
			return $this->emptyContentMessage;
		}

		public function getHalfEmptyContentMessage(): string {
			return $this->halfEmptyContentMessage;
		}

		public function jsonSerialize(): array {
			return [
				'items' => array_map(static fn (WidgetItem $item): array => $item->jsonSerialize(), $this->items),
				'emptyContentMessage' => $this->emptyContentMessage,
				'halfEmptyContentMessage' => $this->halfEmptyContentMessage,
			];
		}
	}

	class WidgetButton {
		public const TYPE_NEW = 'new';
		public const TYPE_MORE = 'more';
		public const TYPE_SETUP = 'setup';

		public function __construct(
			private string $type,
			private string $link,
			private string $text,
		) {
		}

		public function getType(): string {
			return $this->type;
		}

		public function getLink(): string {
			return $this->link;
		}

		public function getText(): string {
			return $this->text;
		}
	}
}

namespace Tests {
	use OCP\Http\Client\IClient;
	use OCP\Http\Client\IClientService;
	use OCP\Http\Client\IResponse;
	use OCP\ICache;
	use OCP\ICacheFactory;
	use OCP\IConfig;
	use OCP\IL10N;
	use OCP\IRequest;
	use OCP\IURLGenerator;
	use Psr\Log\LoggerInterface;

	class Assert {
		public static function same($expected, $actual, string $message = ''): void {
			if ($expected !== $actual) {
				throw new \RuntimeException(
					($message !== '' ? $message . ': ' : '') .
					'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true),
				);
			}
		}

		public static function true($condition, string $message = ''): void {
			if ($condition !== true) {
				throw new \RuntimeException($message !== '' ? $message : 'assertion failed');
			}
		}

		public static function false($condition, string $message = ''): void {
			self::true($condition === false, $message);
		}

		public static function contains(string $needle, string $haystack, string $message = ''): void {
			if (strpos($haystack, $needle) === false) {
				throw new \RuntimeException(
					($message !== '' ? $message . ': ' : '') .
					'expected to find ' . var_export($needle, true) . ' in ' . var_export($haystack, true),
				);
			}
		}

		public static function notContains(string $needle, string $haystack, string $message = ''): void {
			if (strpos($haystack, $needle) !== false) {
				throw new \RuntimeException(
					($message !== '' ? $message . ': ' : '') .
					'did not expect ' . var_export($needle, true) . ' in ' . var_export($haystack, true),
				);
			}
		}
	}

	class FakeConfig implements IConfig {
		/** @var array<string, array<string, string>> */
		public array $values = [];

		public function getAppValue(string $appName, string $key, string $default = ''): string {
			return $this->values[$appName][$key] ?? $default;
		}

		public function setAppValue(string $appName, string $key, string $value): void {
			$this->values[$appName][$key] = $value;
		}

		public function deleteAppValue(string $appName, string $key): void {
			unset($this->values[$appName][$key]);
		}
	}

	class FakeCache implements ICache {
		/** @var array<string, mixed> */
		public array $store = [];
		/** @var array<string, int> */
		public array $ttls = [];
		public int $getCount = 0;
		public int $setCount = 0;
		public int $clearCount = 0;

		public function __construct(public string $prefix = '') {
		}

		public function get($key) {
			$this->getCount++;

			return array_key_exists($key, $this->store) ? $this->store[$key] : null;
		}

		public function set($key, $value, $ttl = 0) {
			$this->setCount++;
			$this->store[$key] = $value;
			$this->ttls[$key] = (int)$ttl;

			return true;
		}

		public function hasKey($key) {
			return array_key_exists($key, $this->store);
		}

		public function remove($key) {
			unset($this->store[$key]);

			return true;
		}

		public function clear($prefix = ''): bool {
			$this->clearCount++;

			return true;
		}

		public function keysMatching(string $prefix): array {
			return array_values(array_filter(
				array_keys($this->store),
				static fn (string $key): bool => strpos($key, $prefix) === 0,
			));
		}
	}

	class FakeCacheFactory implements ICacheFactory {
		/** @var array<string, FakeCache> */
		public array $caches = [];

		public function __construct(private bool $distributedAvailable = true) {
		}

		public function isAvailable(): bool {
			return $this->distributedAvailable;
		}

		public function isLocalCacheAvailable(): bool {
			return true;
		}

		public function createDistributed(string $prefix = ''): ICache {
			return $this->getCache($prefix);
		}

		public function createLocal(string $prefix = ''): ICache {
			return $this->getCache($prefix);
		}

		public function getCache(string $prefix): FakeCache {
			if (!isset($this->caches[$prefix])) {
				$this->caches[$prefix] = new FakeCache($prefix);
			}

			return $this->caches[$prefix];
		}
	}

	class FakeResponse implements IResponse {
		public function __construct(private string $body, private int $status = 200) {
		}

		public function getBody() {
			return $this->body;
		}

		public function getStatusCode(): int {
			return $this->status;
		}

		public function getHeader(string $key): string {
			return '';
		}

		public function getHeaders(): array {
			return [];
		}
	}

	class FakeClient implements IClient {
		/** @var array<int, array{uri: string, options: array}> */
		public array $requests = [];

		/** @var array<int, IResponse|\Throwable> */
		public array $queue = [];

		public function queueResponse(IResponse $response): void {
			$this->queue[] = $response;
		}

		public function queueFailure(\Throwable $exception): void {
			$this->queue[] = $exception;
		}

		public function get(string $uri, array $options = []): IResponse {
			$this->requests[] = ['uri' => $uri, 'options' => $options];

			if ($this->queue === []) {
				throw new \RuntimeException('FakeClient: no queued response');
			}

			$next = array_shift($this->queue);
			if ($next instanceof \Throwable) {
				throw $next;
			}

			return $next;
		}
	}

	class FakeClientService implements IClientService {
		public function __construct(public FakeClient $client) {
		}

		public function newClient(): IClient {
			return $this->client;
		}
	}

	class FakeUrlGenerator implements IURLGenerator {
		public function linkToRoute(string $routeName, array $arguments = []): string {
			if ($routeName === 'settings.AdminSettings.index') {
				$section = $arguments['section'] ?? 'server';
				unset($arguments['section']);
				$query = $arguments === [] ? '' : '?' . http_build_query($arguments);

				return '/settings/admin/' . $section . $query;
			}

			$query = $arguments === [] ? '' : '?' . http_build_query($arguments);

			return '/route/' . $routeName . $query;
		}

		public function linkTo(string $appName, string $file, array $args = []): string {
			return '/apps/' . $appName . '/' . $file;
		}

		public function imagePath(string $appName, string $file): string {
			return '/custom_apps/' . $appName . '/img/' . $file;
		}

		public function getAbsoluteURL(string $url): string {
			return 'https://cloud.example.com' . $url;
		}
	}

	class FakeRequest implements IRequest {
		/** @var array<string, mixed> */
		public array $params = [];

		public function __construct(array $params = []) {
			$this->params = $params;
		}

		public function getParam(string $key, $default = null) {
			return array_key_exists($key, $this->params) ? $this->params[$key] : $default;
		}
	}

	/**
	 * Translation catalogue that behaves like Nextcloud's L10N: "{placeholder}"
	 * substitution for t() and the "_singular_::_plural_" lookup for n().
	 */
	class ArrayL10N implements IL10N {
		/** @var array<string, string|array<int, string>> */
		private array $translations = [];
		private string $pluralRule = 'nplurals=2; plural=(n != 1);';
		private int $nplurals = 2;
		private string $language;

		public function __construct(string $jsonPath, string $language) {
			$data = json_decode((string)file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
			$this->translations = $data['translations'][''];
			$this->pluralRule = $data['pluralForm'];
			$this->nplurals = (int)preg_replace('/^.*nplurals=(\d+).*$/s', '$1', $this->pluralRule);
			$this->language = $language;
		}

		public function getLanguage(): string {
			return $this->language;
		}

		public function has(string $text): bool {
			return isset($this->translations[$text]);
		}

		public function t(string $text, $parameters = []): string {
			$translated = $this->translations[$text] ?? $text;
			if (!is_string($translated)) {
				$translated = $text;
			}

			$map = [];
			if (is_array($parameters)) {
				foreach ($parameters as $key => $value) {
					$map['{' . $key . '}'] = (string)$value;
				}
			}

			return strtr($translated, $map);
		}

		public function n(string $text_singular, string $text_plural, int $count, array $parameters = []): string {
			$key = '_' . $text_singular . '_::_' . $text_plural . '_';
			$translated = $this->translations[$key] ?? $key;

			if (is_array($translated)) {
				$index = $this->pluralIndex($count);
				$translated = $translated[$index] ?? $translated[0];
			}

			return str_replace('%n', (string)$count, (string)$translated);
		}

		private function pluralIndex(int $n): int {
			if (strpos($this->pluralRule, 'n%10==1') !== false) {
				if ($n % 10 === 1 && $n % 100 !== 11) {
					return 0;
				}
				if ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20)) {
					return 1;
				}

				return 2;
			}

			return $n === 1 ? 0 : 1;
		}
	}

	class ArrayLogger implements LoggerInterface {
		/** @var array<int, array{level: string, message: string, context: array}> */
		public array $records = [];

		private function record(string $level, $message, array $context): void {
			$this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
		}

		public function emergency($message, array $context = []): void {
			$this->record('emergency', $message, $context);
		}

		public function alert($message, array $context = []): void {
			$this->record('alert', $message, $context);
		}

		public function critical($message, array $context = []): void {
			$this->record('critical', $message, $context);
		}

		public function error($message, array $context = []): void {
			$this->record('error', $message, $context);
		}

		public function warning($message, array $context = []): void {
			$this->record('warning', $message, $context);
		}

		public function notice($message, array $context = []): void {
			$this->record('notice', $message, $context);
		}

		public function info($message, array $context = []): void {
			$this->record('info', $message, $context);
		}

		public function debug($message, array $context = []): void {
			$this->record('debug', $message, $context);
		}

		public function log($level, $message, array $context = []): void {
			$this->record((string)$level, $message, $context);
		}

		/** @return string[] */
		public function messagesAtLevel(string $level): array {
			$messages = [];
			foreach ($this->records as $record) {
				if ($record['level'] === $level) {
					$messages[] = $record['message'];
				}
			}

			return $messages;
		}

		public function allMessages(): string {
			$all = '';
			foreach ($this->records as $record) {
				$all .= $record['message'] . "\n";
				foreach ($record['context'] as $key => $value) {
					if (is_string($value)) {
						$all .= $key . '=' . $value . "\n";
					}
				}
			}

			return $all;
		}
	}

	class FakeRegistrationContext implements \OCP\AppFramework\Bootstrap\IRegistrationContext {
		public array $widgets = [];
		public array $sensitive = [];

		public function registerDashboardWidget(string $widgetClass): void {
			$this->widgets[] = $widgetClass;
		}

		public function registerSensitiveMethods(string $class, array $methods): void {
			$this->sensitive[] = ['class' => $class, 'methods' => $methods];
		}
	}
}
