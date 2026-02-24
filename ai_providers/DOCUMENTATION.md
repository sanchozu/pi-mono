# DOCUMENTATION

## 1) Общая архитектура

Все провайдеры наследуются от `AbstractAIProvider` и используют единый lifecycle:

1. `createAuthorizationFlow()` — формирование OAuth URL (или Device Flow).
2. `exchangeFromCallbackInput()` — обмен callback/code на токены.
3. `ensureValidToken()` / `refreshAccessToken()` — поддержание валидного access token.
4. `createResponse()` / `createResponseStream()` — выполнение AI-запроса.

## 2) ASCII-диаграммы OAuth

### ChatGPT / Gemini / Copilot / OpenCode (Authorization Code + PKCE)

```text
Пользователь -> Клиент: createAuthorizationFlow()
Клиент -> OAuth Server: /authorize?code_challenge=...
OAuth Server -> Пользователь: login + consent
Пользователь -> Клиент: callback URL с code
Клиент -> OAuth Server: /token (code + code_verifier)
OAuth Server -> Клиент: access_token + refresh_token
Клиент -> AI API: Bearer access_token
```

### Qwen CLI (Device Code + PKCE)

```text
Клиент -> Qwen OAuth: POST /device/code (code_challenge)
Qwen OAuth -> Клиент: device_code + user_code + verification_uri
Пользователь -> Browser: подтверждение device code
Клиент -> Qwen OAuth: POST /token (device_code + code_verifier) [polling]
Qwen OAuth -> Клиент: access_token + refresh_token
```

## 3) Endpoints и параметры

| Провайдер | Авторизация | Токены | API |
|---|---|---|---|
| ChatGPT | `https://auth.openai.com/oauth/authorize` | `https://auth.openai.com/oauth/token` | `https://api.openai.com/v1/chat/completions` |
| Gemini | `https://accounts.google.com/o/oauth2/v2/auth` | `https://oauth2.googleapis.com/token` | `https://generativelanguage.googleapis.com/v1beta/models/*:generateContent` |
| Antigravity | Google OAuth | Google token | провайдер-зависимый endpoint |
| Qwen | `POST /oauth2/device/code` | `POST /oauth2/token` | `https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions` |
| Copilot | `https://github.com/login/oauth/authorize` | `https://github.com/login/oauth/access_token` | `https://api.githubcopilot.com/chat/completions` |
| OpenCode Zen | `https://opencode.ai/oauth/authorize` | `https://opencode.ai/oauth/token` | `https://opencode.ai/api/v1/chat/completions` |

## 4) Структура state.json

```json
{
  "credentials": {
    "access": "...",
    "refresh": "...",
    "expires": 1730000000,
    "accountId": "user-123"
  },
  "current_model": "gpt-5",
  "session_id": "abcd1234"
}
```

- Запись выполняется атомарно через `LOCK_EX`.
- После записи применяются права `0600`.

## 5) Примеры кода

```php
$provider = new QwenCliProvider(__DIR__ . '/state-qwen.json');
$flow = $provider->createAuthorizationFlow();
print_r($flow); // verification_uri, user_code, device_code

// После подтверждения в браузере:
$provider->exchangeFromCallbackInput($flow['device_code']);
$response = $provider->chat('Объясни OAuth 2.0 + PKCE простыми словами');
echo $response->content;
```

```php
$provider = new OpenAICodexProvider(__DIR__ . '/state-openai.json');
$oauth = $provider->createAuthorizationFlow();
// Открыть $oauth['url'], затем вставить callback URL из браузера
$provider->exchangeFromCallbackInput($callbackUrl);
$result = $provider->createResponse([
    ['role' => 'user', 'content' => 'Сгенерируй пример JWT payload']
]);
```

## 6) Таблица ошибок

| Исключение | Причина | Действие |
|---|---|---|
| `RuntimeException: Проверка state не пройдена` | CSRF или неверный callback | Перезапустить flow |
| `RuntimeException: Device code истёк` | Пользователь не подтвердил вовремя | Создать новый device flow |
| `RuntimeException: refresh token отсутствует` | Сессия неполная/повреждена | Полный re-login |
| `RuntimeException: HTTP 401/403` | Токен недействителен/нет scope | Refresh или re-consent |
| `RuntimeException: JSON невалиден` | Некорректный ответ API | Логировать raw response и повторить |
