# gitlab_commits — виджет Dashboard «Последние коммиты GitLab»

Приложение Nextcloud, которое добавляет на панель управления виджет со списком
последних коммитов одного проекта локального GitLab. Сервер Nextcloud сам
запрашивает GitLab REST API v4; webhook и промежуточные сервисы не используются.

## Требования

* Nextcloud 27 или новее (виджет реализует `OCP\Dashboard\IAPIWidgetV2`).
* PHP 8.0+ (стандартные требования Nextcloud 27).
* GitLab (self-managed или gitlab.com) с доступом по HTTP(S) с сервера Nextcloud.
* Personal access token GitLab с областью `read_api`.

## Установка

```bash
# 1. Скопировать каталог приложения на сервер
#    (для Debian/Ubuntu обычно /var/www/nextcloud/custom_apps)
rsync -a gitlab_commits/ /var/www/nextcloud/custom_apps/gitlab_commits/

# 2. Включить приложение
sudo -u www-data php occ app:enable gitlab_commits

# 3. (необязательно) проверить синтаксис PHP и целостность подписи
find /var/www/nextcloud/custom_apps/gitlab_commits -name '*.php' -print0 \
  | xargs -0 -n1 php -l
sudo -u www-data php occ integrity:check-app gitlab_commits
```

Каталог `gitlab_commits` можно распаковать и в `apps/`, но `custom_apps/` не
затрагивается при обновлении Nextcloud — предпочтительный вариант.

## Создание Personal Access Token в GitLab

1. Открыть GitLab → аватар профиля → **Preferences** (Настройки) → **Access tokens**.
2. Имя: например `nextcloud-dashboard`.
3. Срок действия: по требованию вашей политики.
4. Scopes: только **`read_api`** (полный `api` не нужен).
5. Создать токен и сразу скопировать значение вида `glpat-xxxxxxxxxxxx`.

Токен даёт доступ ко всем проектам, которые видит пользователь-владелец токена.
Рекомендуется создать отдельного пользователя GitLab (`nextcloud-bot`) с ролью
`Reporter` только в нужном проекте и выпустить токен от его имени.

## Настройка

Администратор: **Администрирование → Дополнительные настройки → GitLab commits**
(раздел `additional`).

| Ключ `oc_appconfig` | Поле формы          | По умолчанию | Описание                                             |
|---------------------|---------------------|--------------|------------------------------------------------------|
| `gitlab_url`        | GitLab base URL     | —            | Например `https://gitlab.example.com`, без `/api/v4` |
| `gitlab_token`      | Personal access token | —          | Секрет, в браузер и логи не попадает                 |
| `gitlab_project`    | Project id or path  | —            | `23` или `group/subgroup/project`                    |
| `gitlab_branch`     | Branch              | `main`       | Ветка, по которой строятся коммиты (`ref_name`)      |
| `cache_ttl`         | Cache lifetime      | `60`         | Секунды, 1…86400                                     |
| `commits_limit`     | Number of commits   | `7`          | 1…30                                                 |

То же самое можно задать через `occ` (полезно для автоматизации развёртывания):

```bash
occ config:app:set gitlab_commits gitlab_url     --value="https://gitlab.example.com"
occ config:app:set gitlab_commits gitlab_token   --value="glpat-xxxxxxxxxxxx"
occ config:app:set gitlab_commits gitlab_project --value="group/subgroup/project"
occ config:app:set gitlab_commits gitlab_branch  --value="main"
occ config:app:set gitlab_commits cache_ttl      --value=60
occ config:app:set gitlab_commits commits_limit  --value=7
```

В Nextcloud 29+ для токена доступен флаг `--sensitive`:
`occ config:app:set gitlab_commits gitlab_token --sensitive --value="glpat-…"`.
На 27/28 такого флага нет — токен всё равно не выводится ни в интерфейс, ни в
API приложения, но помните, что `oc_appconfig` читается администратором сервера
и базой данных.

После сохранения формы кэш приложения очищается, поэтому новый проект или ветка
видны сразу, без ожидания TTL.

## Локальный GitLab по HTTP и `allow_local_remote_servers`

Nextcloud по умолчанию запрещает HTTP-клиенту обращаться к локальным и приватным
адресам (защита от SSRF). Если GitLab работает на том же сервере или в локальной
сети (`http://192.168.1.10`, `http://gitlab.local`, `http://127.0.0.1:8080`),
в `config/config.php` нужно добавить:

```php
'allow_local_remote_servers' => true,
```

Без этого виджет покажет «Не удалось связаться с GitLab». Для GitLab во внешней
сети с валидным TLS-сертификатом дополнительная настройка не требуется.

## Кэширование

* Результат запроса к GitLab кэшируется на `cache_ttl` секунд ключом, который
  включает базовый URL, проект, ветку и лимит (смена любого из них даёт новый
  ключ).
* Используется распределённый кэш (`ICacheFactory::createDistributed()`), если
  он настроен в `config.php` (`memcache.distributed`), иначе локальный
  (`createLocal()`), иначе `NullCache` (кэша нет). Чтобы TTL действительно
  работал, включите APCu или Redis, например строкой в `config.php`:
  `'memcache.local' => '\OC\Memcache\APCu',` (для нескольких серверов —
  `'memcache.distributed' => '\OC\Memcache\Redis',`). Без настроенного memcache
  Nextcloud создаёт `NullCache` и виджет запрашивает GitLab при каждом открытии
  Dashboard.
* Копия последнего успешного результата хранится 24 часа и отдаётся, если GitLab
  недоступен, — короткий сбой не оставляет виджет пустым.
* Ошибки в кэш не записываются: неудачный ответ не «залипает» на TTL.

## Поведение при ошибках

| Ситуация                                | Что видит пользователь                                                              |
|-----------------------------------------|-------------------------------------------------------------------------------------|
| URL, токен или проект не заданы         | «GitLab ещё не настроен. Администратор может указать параметры подключения в настройках.» |
| GitLab недоступен (timeout, DNS, 5xx)   | «Не удалось связаться с GitLab…» либо «GitLab вернул HTTP-статус N.»                 |
| 401/403 от GitLab                       | «GitLab отклонил запрос. Проверьте personal access token.»                          |
| Ответ невалидный JSON / объект ошибки   | «GitLab вернул неожиданный ответ.»                                                  |
| Коммитов нет                            | «Нет коммитов»                                                                      |

Виджет не бросает исключения наружу: любая проблема заканчивается сообщением в
пустом состоянии виджета, остальные виджеты Dashboard работают как обычно.
Ошибки пишутся в `nextcloud.log` через `Psr\Log\LoggerInterface` с уровнем
`warning`/`error`; токен в лог не попадает (в контекст передаются только статус
и текст ошибки HTTP-клиента, сам токен живёт исключительно в заголовке запроса).

## Проверка после установки (критерии приёмки)

```bash
# 1. GitLab отвечает на запрос так, как его делает виджет
curl -sS --header "PRIVATE-TOKEN: glpat-…" \
  "https://gitlab.example.com/api/v4/projects/group%2Fsubgroup%2Fproject/repository/commits?per_page=7&ref_name=main" \
  | head -n 20

# 2. Приложение включено, настройки на месте
occ app:list | grep gitlab_commits
occ config:app:get gitlab_commits gitlab_url

# 3. Виджет отдаёт элементы (нужна авторизованная сессия/токен пользователя)
curl -sS -u user:app-password \
  "https://cloud.example.com/ocs/v2.php/apps/dashboard/api/v2/widget-items?widgets[]=gitlab_commits" \
  -H "OCS-APIRequest: true"

# 4. Токен не утёк в ответ API и в лог
curl -sS -u user:app-password -H "OCS-APIRequest: true" \
  "https://cloud.example.com/ocs/v2.php/apps/dashboard/api/v2/widget-items?widgets[]=gitlab_commits" \
  | grep -c "glpat-"          # ожидается 0
grep -c "glpat-" /var/www/nextcloud/data/nextcloud.log   # ожидается 0

# 5. TTL работает: два запроса подряд в течение cache_ttl дают один запрос к GitLab
#    (считайте запросы в access-логе GitLab или в логе nginx GitLab)
```

Остальные пункты ТЗ проверяются в интерфейсе: смена ветки в настройках меняет
коммиты в виджете, неверный токен даёт сообщение об ошибке, Dashboard продолжает
работать при выключенном GitLab.

## Тесты

Логика приложения покрыта поведенческими тестами (43 сценария: форма запроса к
GitLab, нормализация коммитов, кэш и TTL, отдача устаревших данных при сбое,
401/403, timeout, невалидный JSON, лимиты, виджет, админ-форма, сохранение
настроек, переводы en/ru). Тесты лежат **вне** каталога приложения, используют
заглушки публичного API Nextcloud 27 (`php-tests/stubs.php`) и не требуют
установленного Nextcloud.

Набор был прогнан на настоящем PHP 8.0, 8.3 и 8.5 (PHP в WebAssembly) с
`error_reporting(E_ALL)`: 43 passed, 0 failed, без warning/notice/deprecated.

```bash
# вариант 1: родной PHP 8.0+ на машине
APP_ROOT=./gitlab_commits TEST_ROOT=./php-tests php php-tests/run.php

# вариант 2: PHP в WebAssembly (Node.js, без установки PHP)
cd .phpwasm
npm install php-wasm
node run-tests.mjs .. 8.0
```

## Структура приложения

```
gitlab_commits/
├── appinfo/
│   ├── info.xml                     метаданные, Nextcloud 27+, регистрация
│   │                                админ-настроек (<settings><admin>)
│   └── routes.php                   POST /apps/gitlab_commits/settings
├── css/
│   ├── dashboard.css                иконка заголовка виджета для NC 27
│   └── settings.css                 стили админ-формы
├── img/
│   ├── app.svg                      иконка приложения
│   └── gitlab.svg                   иконка виджета и fallback-аватар коммита
├── l10n/
│   ├── en.json
│   └── ru.json
├── lib/
│   ├── AppInfo/Application.php      регистрация виджета, загрузка CSS
│   ├── Controller/SettingsController.php  сохранение админ-формы
│   ├── Dashboard/GitLabCommitsWidget.php  IAPIWidgetV2 + IIconWidget + IButtonWidget
│   ├── Service/Configuration.php    типизированный доступ к oc_appconfig
│   ├── Service/GitLabClient.php     HTTP-запрос, кэш, обработка ошибок
│   ├── Service/GitLabException.php  ошибка с переведённым сообщением
│   └── Settings/AdminSettings.php   админ-форма (IDelegatedSettings)
├── templates/
│   └── settings/admin.php
└── README.md
```

## Отклонения от ТЗ и известные ограничения

1. **Поле `since` у элемента виджета.** В `OCP\Dashboard\Model\WidgetItem`
   (Nextcloud 27+, включая актуальные версии) нет поля `since` — есть
   `title`, `subtitle`, `link`, `iconUrl`, `overlayIconUrl` и `sinceId`
   (идентификатор для дедупликации, а не время). Стандартный рендеринг
   Dashboard время не выводит вообще. Поэтому относительное время коммита
   («2 часа назад») добавляется в конец `subtitle` — единственного видимого
   текстового поля под заголовком, — а `sinceId` заполняется ISO-датой коммита,
   как того ждёт API. Кастомный фронтенд для этого не нужен, что соответствует
   п. 10 ТЗ.
2. **Логирование** — `Psr\Log\LoggerInterface` (тот же логгер Nextcloud, что и
   `ILogger`; `ILogger` считается устаревающим интерфейсом).
3. **Миграция не нужна**: собственных таблиц приложение не создаёт, все
   настройки лежат в `oc_appconfig`, поэтому `lib/Migration/` отсутствует.
4. **Аватар автора.** GitLab REST API v4 не отдаёт аватар в объекте коммита
   (`GET /repository/commits` возвращает только имя и e-mail автора). Если в
   ответе появится `author_avatar_url`, он используется; иначе показывается
   иконка GitLab (`img/gitlab.svg`), как и предусмотрено ТЗ fallback-вариантом.
5. **Добавленные классы** сверх минимальной структуры ТЗ:
   `Controller/SettingsController.php` (сохранение формы через обычный POST с
   CSRF-токеном, без JavaScript), `Service/Configuration.php` (типизированные
   настройки и валидация диапазонов), `Service/GitLabException.php`
   (переведённое сообщение об ошибке).
6. **Ограничения по ТЗ:** один GitLab-инстанс, один проект и одна ветка на всё
   приложение (не per-user); обновление только по TTL, без webhook; аутентификация
   только Personal Access Token; виджет доступен всем авторизованным пользователям,
   менять настройки может только администратор (или делегированный администратор
   раздела `additional`).
7. **Иконка** `img/gitlab.svg` и `img/app.svg` — оригинальная абстрактная графика
   в фирменном оранжевом цвете GitLab (`#fc6d26`), а не официальный логотип
   GitLab (товарный знак GitLab Inc.).
8. **Публикация в App Store** не планируется: в `info.xml` нет обязательных для
   магазина элементов `<bugs>` и `<screenshot>`. Для локальной установки они не
   нужны.

## Лицензия

AGPL-3.0-or-later, как и Nextcloud. Полный текст — в файле [LICENSE](LICENSE).
