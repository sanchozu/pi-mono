# OpenAI Codex Provider (ChatGPT Plus/Pro) для PHP 8.5

## Что разобрано в репозитории

Ниже — сжатая схема того, как это устроено в `pi-mono`:

1. **OAuth URL** собирается с PKCE и параметрами:
   - `response_type=code`
   - `client_id=app_EMoamEEZ73f0CkXaXp7hrann`
   - `redirect_uri=http://localhost:1455/auth/callback`
   - `scope=openid profile email offline_access`
   - `code_challenge` + `code_challenge_method=S256`
   - `state`
   - `id_token_add_organizations=true`
   - `codex_cli_simplified_flow=true`
   - `originator=pi`

2. После получения `code` выполняется обмен в `https://auth.openai.com/oauth/token` (`grant_type=authorization_code`).

3. Из `access_token` (JWT) извлекается `chatgpt_account_id` из claim `https://api.openai.com/auth`.

4. В local storage сохраняются:
   - `access`
   - `refresh`
   - `expires`
   - `accountId`
   - служебные поля (текущее состояние OAuth / выбранная модель / session id).

5. При каждом запросе:
   - если `expires` истек — выполняется refresh (`grant_type=refresh_token`),
   - для запроса в `chatgpt.com/backend-api/codex/responses` используются заголовки:
     - `Authorization: Bearer <access>`
     - `chatgpt-account-id: <accountId>`
     - `OpenAI-Beta: responses=experimental`
     - `originator: pi`

6. Выбор модели в `pi` хранится отдельно (в `settings.json`), а OAuth-токены — в `auth.json`.

---

## Что реализовано здесь

Создан PHP-класс:

- `docs/openai_provider/OpenAICodexProvider.php`

Он умеет:

- запускать OAuth flow (генерация ссылки + PKCE),
- обменивать `code` на токены,
- принимать вставленный callback URL/строку и автоматически извлекать `code` + `state`,
- обновлять токены,
- безопасно сохранять состояние (JSON файл + права 0600),
- хранить выбранную текущую модель,
- выполнять обычный запрос к текущей модели,
- выполнять streaming-запрос (SSE) с callback-обработчиком,
- отдавать известный список моделей и пытаться запросить список с сервера.

---

## Формат локального файла состояния

Пример `state.json`:

```json
{
  "credentials": {
    "access": "...",
    "refresh": "...",
    "expires": 1767000000,
    "accountId": "user-..."
  },
  "oauth": {
    "state": "...",
    "verifier": "...",
    "challenge": "...",
    "created_at": 1766990000
  },
  "current_model": "gpt-5.3-codex",
  "session_id": "optional-session-id",
  "updated_at": "2026-01-01T12:00:00+00:00"
}
```

---

## Быстрый старт

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/OpenAICodexProvider.php';

use OpenAIProvider\OpenAICodexProvider;

$provider = new OpenAICodexProvider(
    storagePath: __DIR__ . '/state.json',
    originator: 'pi',
    baseUrl: 'https://chatgpt.com/backend-api'
);

// 1) Сгенерировать OAuth URL
$flow = $provider->createAuthorizationFlow();
echo "Откройте URL:\n" . $flow['url'] . "\n\n";

// 2) После редиректа скопируйте code и state вручную
$code = trim((string) readline('Введите code: '));
$state = trim((string) readline('Введите state: '));

$credentials = $provider->exchangeAuthorizationCode($code, $state);
print_r($credentials);

// 3) Выбрать и сохранить модель
$provider->setCurrentModel('gpt-5.3-codex');

echo "Текущая модель: " . $provider->getCurrentModel() . PHP_EOL;

// 4) Отправить запрос
$response = $provider->createResponse([
    ['role' => 'user', 'content' => 'Сделай краткий обзор OAuth PKCE flow.']
]);

print_r($response);
```

---

## Обновление токенов

Можно обновлять вручную:

```php
$newCreds = $provider->refreshAccessToken();
print_r($newCreds);
```

Либо автоматически через:

```php
$accessToken = $provider->getValidAccessToken(); // сам обновит при необходимости
```

---

## Получение списка моделей

```php
$models = $provider->listModels();
print_r($models);
```

Поведение:

- сначала попытка запроса с сервера,
- при ошибке fallback на локально известные модели (`listKnownModels()`).

---

## Streaming (SSE)

```php
$provider->createResponseStream(
    messages: [
        ['role' => 'user', 'content' => 'Напиши 5 пунктов про хранение OAuth токенов.']
    ],
    onEvent: function (array $event): void {
        // Тут можно собирать response.output_text.delta и т.д.
        echo json_encode($event, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
);
```

---

## Авторизация без локального callback HTTP-сервера

Теперь можно вообще не поднимать локальный HTTP-сервер:

1. Сгенерируйте ссылку через `createAuthorizationFlow()`.
2. Откройте её в браузере и завершите логин.
3. Скопируйте целиком callback URL из адресной строки браузера.
4. Передайте его в `exchangeFromCallbackInput()` — метод сам распарсит `code`/`state` и сохранит токены.

Пример:

```php
$flow = $provider->createAuthorizationFlow();
echo $flow['url'] . PHP_EOL;

$callbackUrl = trim((string) readline('Вставьте callback URL: '));
$credentials = $provider->exchangeFromCallbackInput($callbackUrl);
print_r($credentials);
```

Поддерживаемые форматы input:

- полный URL: `http://localhost:1455/auth/callback?code=...&state=...`
- query-строка: `code=...&state=...`
- `code#state`
- просто `code`

---

## Тестовый веб-интерфейс

Добавлен скрипт: `docs/openai_provider/web_test.php`

Функциональность:

- генерация OAuth URL,
- обмен callback input на токены,
- refresh токена,
- выбор и сохранение текущей модели,
- получение списка моделей,
- отправка запроса и просмотр ответа,
- просмотр текущего состояния `state.json`.

Запуск:

```bash
cd docs/openai_provider
php -S 127.0.0.1:8080
```

Далее откройте: `http://127.0.0.1:8080/web_test.php`

---

## Практические замечания

1. Этот flow зависит от конкретных endpoint и заголовков, используемых в `pi`/Codex.
2. `chatgpt.com/backend-api` и формат событий могут измениться.
3. Для production:
   - добавьте retry/backoff,
   - логируйте refresh-ошибки,
   - храните state в защищенном месте,
   - не печатайте токены в логи.
4. Если хотите строго повторить логику `pi`, можно добавить:
   - авто-поднятие локального callback HTTP-сервера (`127.0.0.1:1455`),
   - WebSocket transport,
   - session connection reuse.
