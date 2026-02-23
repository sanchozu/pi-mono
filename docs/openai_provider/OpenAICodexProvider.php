<?php

declare(strict_types=1);

namespace OpenAIProvider;

use RuntimeException;

/**
 * Клиент для OAuth-подписки ChatGPT Plus/Pro (Codex Subscription)
 * и вызовов chatgpt.com/backend-api/codex/responses.
 *
 * Важно:
 * - Это не официальный SDK OpenAI.
 * - Форматы и эндпоинты могут измениться без обратной совместимости.
 */
final class OpenAICodexProvider
{
    private const CLIENT_ID = 'app_EMoamEEZ73f0CkXaXp7hrann';
    private const AUTHORIZE_URL = 'https://auth.openai.com/oauth/authorize';
    private const TOKEN_URL = 'https://auth.openai.com/oauth/token';
    private const REDIRECT_URI = 'http://localhost:1455/auth/callback';
    private const SCOPE = 'openid profile email offline_access';
    private const JWT_CLAIM_PATH = 'https://api.openai.com/auth';
    private const DEFAULT_BASE_URL = 'https://chatgpt.com/backend-api';

    /** @var array<string,mixed> */
    private array $state = [
        'credentials' => null,
        'oauth' => null,
        'current_model' => 'gpt-5.3-codex',
        'session_id' => null,
        'updated_at' => null,
    ];

    public function __construct(
        private readonly string $storagePath,
        private readonly string $originator = 'pi',
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {
        $this->loadState();
    }

    /**
     * Генерирует URL авторизации + PKCE параметры.
     *
     * @return array{url:string,state:string,verifier:string,challenge:string}
     */
    public function createAuthorizationFlow(): array
    {
        $verifier = $this->base64UrlEncode(random_bytes(32));
        $challenge = $this->base64UrlEncode(hash('sha256', $verifier, true));
        $state = bin2hex(random_bytes(16));

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => self::SCOPE,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
            'id_token_add_organizations' => 'true',
            'codex_cli_simplified_flow' => 'true',
            'originator' => $this->originator,
        ], '', '&', PHP_QUERY_RFC3986);

        $this->state['oauth'] = [
            'state' => $state,
            'verifier' => $verifier,
            'challenge' => $challenge,
            'created_at' => time(),
        ];
        $this->touchAndSave();

        return [
            'url' => self::AUTHORIZE_URL . '?' . $query,
            'state' => $state,
            'verifier' => $verifier,
            'challenge' => $challenge,
        ];
    }

    /**
     * Обменивает authorization code на access/refresh токены.
     */
    public function exchangeAuthorizationCode(string $authorizationCode, ?string $state = null): array
    {
        $oauthState = $this->state['oauth'];
        if (!is_array($oauthState) || empty($oauthState['verifier'])) {
            throw new RuntimeException('Нет сохраненного PKCE verifier. Сначала вызовите createAuthorizationFlow().');
        }

        if ($state !== null && isset($oauthState['state']) && $state !== $oauthState['state']) {
            throw new RuntimeException('State mismatch: возможна CSRF-атака или устаревшая ссылка.');
        }

        $token = $this->requestToken([
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'code' => $authorizationCode,
            'code_verifier' => (string) $oauthState['verifier'],
            'redirect_uri' => self::REDIRECT_URI,
        ]);

        $credentials = $this->buildCredentialsFromTokenResponse($token);
        $this->state['credentials'] = $credentials;
        $this->touchAndSave();

        return $credentials;
    }

    /**
     * Разбирает callback input (URL/строка query/code#state/code)
     * и выполняет обмен authorization code на токены.
     *
     * Примеры поддерживаемого input:
     * - http://localhost:1455/auth/callback?code=...&state=...
     * - code=...&state=...
     * - <code>#<state>
     * - <code>
     */
    public function exchangeFromCallbackInput(string $callbackInput): array
    {
        $parsed = $this->parseAuthorizationInput($callbackInput);
        $code = $parsed['code'] ?? null;
        $state = $parsed['state'] ?? null;

        if (!is_string($code) || $code === '') {
            throw new RuntimeException('Не удалось извлечь authorization code из callback input.');
        }

        return $this->exchangeAuthorizationCode($code, $state);
    }

    /**
     * Разбор callback input без выполнения token exchange.
     *
     * @return array{code:?string,state:?string}
     */
    public function parseAuthorizationInput(string $input): array
    {
        $value = trim($input);
        if ($value === '') {
            return ['code' => null, 'state' => null];
        }

        // 1) Полный URL callback
        $query = (string) (parse_url($value, PHP_URL_QUERY) ?? '');
        if ($query !== '') {
            parse_str($query, $params);
            $code = isset($params['code']) && is_string($params['code']) ? trim($params['code']) : null;
            $state = isset($params['state']) && is_string($params['state']) ? trim($params['state']) : null;
            return [
                'code' => $code !== '' ? $code : null,
                'state' => $state !== '' ? $state : null,
            ];
        }

        // 2) Формат code#state
        if (str_contains($value, '#')) {
            [$codePart, $statePart] = explode('#', $value, 2);
            $codePart = trim($codePart);
            $statePart = trim($statePart);
            return [
                'code' => $codePart !== '' ? $codePart : null,
                'state' => $statePart !== '' ? $statePart : null,
            ];
        }

        // 3) Query string без URL: code=...&state=...
        if (str_contains($value, 'code=')) {
            parse_str($value, $params);
            $code = isset($params['code']) && is_string($params['code']) ? trim($params['code']) : null;
            $state = isset($params['state']) && is_string($params['state']) ? trim($params['state']) : null;
            return [
                'code' => $code !== '' ? $code : null,
                'state' => $state !== '' ? $state : null,
            ];
        }

        // 4) Просто code
        return ['code' => $value, 'state' => null];
    }

    /**
     * Принудительное обновление access token по refresh token.
     */
    public function refreshAccessToken(): array
    {
        $credentials = $this->requireCredentials();

        $token = $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $credentials['refresh'],
            'client_id' => self::CLIENT_ID,
        ]);

        $newCredentials = $this->buildCredentialsFromTokenResponse($token);
        $this->state['credentials'] = $newCredentials;
        $this->touchAndSave();

        return $newCredentials;
    }

    /**
     * Возвращает валидный access token (обновляет при необходимости).
     */
    public function getValidAccessToken(int $skewSeconds = 60): string
    {
        $credentials = $this->requireCredentials();
        $expires = (int) ($credentials['expires'] ?? 0);

        if (time() + $skewSeconds >= $expires) {
            $credentials = $this->refreshAccessToken();
        }

        return (string) $credentials['access'];
    }

    /** @return array<int,string> */
    public function listKnownModels(): array
    {
        return [
            'gpt-5.1',
            'gpt-5.1-codex-max',
            'gpt-5.1-codex-mini',
            'gpt-5.2',
            'gpt-5.2-codex',
            'gpt-5.3-codex',
            'gpt-5.3-codex-spark',
        ];
    }

    /**
     * Пытается получить модели с сервера. При ошибке возвращает локальный список.
     *
     * @return array<int,string>
     */
    public function listModels(): array
    {
        $token = $this->getValidAccessToken();
        $accountId = $this->extractAccountIdFromJwt($token);

        // Не гарантируется сервером. Если endpoint изменится, используем fallback.
        $url = rtrim($this->baseUrl, '/') . '/models';

        try {
            $response = $this->requestJson('GET', $url, null, [
                'Authorization: Bearer ' . $token,
                'chatgpt-account-id: ' . $accountId,
                'originator: ' . $this->originator,
                'accept: application/json',
            ]);

            $models = [];
            $items = $response['data'] ?? $response['models'] ?? [];
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (is_array($item) && isset($item['id']) && is_string($item['id'])) {
                        $models[] = $item['id'];
                    }
                }
            }

            return $models !== [] ? array_values(array_unique($models)) : $this->listKnownModels();
        } catch (RuntimeException) {
            return $this->listKnownModels();
        }
    }

    public function setCurrentModel(string $model): void
    {
        if ($model === '') {
            throw new RuntimeException('Model id не может быть пустым.');
        }

        $this->state['current_model'] = $model;
        $this->touchAndSave();
    }

    public function getCurrentModel(): string
    {
        $model = $this->state['current_model'] ?? null;
        return is_string($model) && $model !== '' ? $model : 'gpt-5.3-codex';
    }

    public function setSessionId(?string $sessionId): void
    {
        $this->state['session_id'] = $sessionId;
        $this->touchAndSave();
    }

    public function getSessionId(): ?string
    {
        $sessionId = $this->state['session_id'] ?? null;
        return is_string($sessionId) && $sessionId !== '' ? $sessionId : null;
    }

    /**
     * Обычный (не streaming) запрос к текущей модели.
     *
     * @param array<int,array<string,mixed>> $messages
     * @return array<string,mixed>
     */
    public function createResponse(array $messages, ?string $model = null): array
    {
        $token = $this->getValidAccessToken();
        $accountId = $this->extractAccountIdFromJwt($token);
        $endpoint = rtrim($this->baseUrl, '/') . '/codex/responses';

        $payload = [
            'model' => $model ?? $this->getCurrentModel(),
            'stream' => false,
            'store' => false,
            'input' => $this->convertMessagesToInput($messages),
            'reasoning' => [
                'effort' => 'medium',
                'summary' => 'auto',
            ],
            'text' => [
                'verbosity' => 'medium',
            ],
        ];

        $headers = [
            'Authorization: Bearer ' . $token,
            'chatgpt-account-id: ' . $accountId,
            'OpenAI-Beta: responses=experimental',
            'originator: ' . $this->originator,
            'accept: application/json',
            'content-type: application/json',
        ];

        $sessionId = $this->getSessionId();
        if ($sessionId !== null) {
            $headers[] = 'session_id: ' . $sessionId;
        }

        return $this->requestJson('POST', $endpoint, $payload, $headers);
    }

    /**
     * Streaming-запрос (SSE) с обработчиком событий.
     *
     * @param callable(array<string,mixed>):void $onEvent
     * @param array<int,array<string,mixed>> $messages
     */
    public function createResponseStream(array $messages, callable $onEvent, ?string $model = null): void
    {
        $token = $this->getValidAccessToken();
        $accountId = $this->extractAccountIdFromJwt($token);
        $endpoint = rtrim($this->baseUrl, '/') . '/codex/responses';

        $payload = [
            'model' => $model ?? $this->getCurrentModel(),
            'stream' => true,
            'store' => false,
            'input' => $this->convertMessagesToInput($messages),
        ];

        $headers = [
            'Authorization: Bearer ' . $token,
            'chatgpt-account-id: ' . $accountId,
            'OpenAI-Beta: responses=experimental',
            'originator: ' . $this->originator,
            'accept: text/event-stream',
            'content-type: application/json',
        ];

        $sessionId = $this->getSessionId();
        if ($sessionId !== null) {
            $headers[] = 'session_id: ' . $sessionId;
        }

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new RuntimeException('Не удалось создать CURL handle.');
        }

        $buffer = '';

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$buffer, $onEvent): int {
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n\n")) !== false) {

                    $eventBlock = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);

                    $dataLines = [];
                    foreach (explode("\n", $eventBlock) as $line) {
                        if (str_starts_with($line, 'data:')) {
                            $dataLines[] = trim(substr($line, 5));
                        }
                    }

                    if ($dataLines === []) {
                        continue;
                    }

                    $data = trim(implode("\n", $dataLines));
                    if ($data === '' || $data === '[DONE]') {
                        continue;
                    }

                    $parsed = json_decode($data, true);
                    if (is_array($parsed)) {
                        $onEvent($parsed);
                    }
                }

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        if ($ok === false) {
            $error = curl_error($ch);
            $code = curl_errno($ch);
            curl_close($ch);
            throw new RuntimeException('Ошибка streaming-запроса: [' . $code . '] ' . $error);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('Streaming-запрос завершился HTTP ' . $httpCode . '.');
        }
    }

    public function getStoredState(): array
    {
        return $this->state;
    }

    /** @return array<string,mixed> */
    private function requestToken(array $payload): array
    {
        $response = $this->requestForm(self::TOKEN_URL, $payload);

        if (!isset($response['access_token'], $response['refresh_token'], $response['expires_in'])) {
            throw new RuntimeException('Некорректный token response: отсутствуют access_token/refresh_token/expires_in.');
        }

        return $response;
    }

    /** @return array<string,mixed> */
    private function buildCredentialsFromTokenResponse(array $tokenResponse): array
    {
        $access = (string) $tokenResponse['access_token'];
        $refresh = (string) $tokenResponse['refresh_token'];
        $expiresIn = (int) $tokenResponse['expires_in'];

        return [
            'access' => $access,
            'refresh' => $refresh,
            'expires' => time() + $expiresIn,
            'accountId' => $this->extractAccountIdFromJwt($access),
        ];
    }

    /** @return array<string,mixed> */
    private function requestForm(string $url, array $payload): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Не удалось создать CURL handle.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            $code = curl_errno($ch);
            curl_close($ch);
            throw new RuntimeException('Ошибка CURL: [' . $code . '] ' . $error);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Не удалось декодировать JSON ответ: ' . $raw);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = (string) ($decoded['error_description'] ?? $decoded['error'] ?? 'HTTP ' . $httpCode);
            throw new RuntimeException('OAuth token request failed: ' . $message);
        }

        return $decoded;
    }

    /** @return array<string,mixed> */
    private function requestJson(string $method, string $url, ?array $payload, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Не удалось создать CURL handle.');
        }

        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
        ];

        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
        }

        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            $code = curl_errno($ch);
            curl_close($ch);
            throw new RuntimeException('Ошибка CURL: [' . $code . '] ' . $error);
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Не удалось декодировать JSON ответ: ' . $raw);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = (string) ($decoded['error']['message'] ?? $decoded['message'] ?? 'HTTP ' . $httpCode);
            throw new RuntimeException('HTTP request failed: ' . $message);
        }

        return $decoded;
    }

    /** @return array<string,mixed> */
    private function requireCredentials(): array
    {
        $credentials = $this->state['credentials'] ?? null;
        if (!is_array($credentials)) {
            throw new RuntimeException('Нет OAuth credentials. Выполните авторизацию.');
        }

        foreach (['access', 'refresh', 'expires'] as $field) {
            if (!array_key_exists($field, $credentials)) {
                throw new RuntimeException('Поврежденные credentials: отсутствует поле ' . $field);
            }
        }

        return $credentials;
    }

    private function extractAccountIdFromJwt(string $jwt): string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new RuntimeException('Некорректный JWT: ожидалось 3 части.');
        }

        $payloadJson = $this->base64UrlDecode($parts[1]);
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Не удалось декодировать payload JWT.');
        }

        $auth = $payload[self::JWT_CLAIM_PATH] ?? null;
        $accountId = is_array($auth) ? ($auth['chatgpt_account_id'] ?? null) : null;

        if (!is_string($accountId) || $accountId === '') {
            throw new RuntimeException('В JWT нет chatgpt_account_id.');
        }

        return $accountId;
    }

    /** @param array<int,array<string,mixed>> $messages */
    private function convertMessagesToInput(array $messages): array
    {
        $input = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');
            $content = $message['content'] ?? '';

            if (is_string($content)) {
                $input[] = [
                    'role' => $role,
                    'content' => [
                        ['type' => 'input_text', 'text' => $content],
                    ],
                ];
                continue;
            }

            if (is_array($content)) {
                $input[] = [
                    'role' => $role,
                    'content' => $content,
                ];
                continue;
            }

            throw new RuntimeException('Неподдерживаемый формат сообщения content.');
        }

        return $input;
    }

    private function loadState(): void
    {
        if (!is_file($this->storagePath)) {
            return;
        }

        $raw = file_get_contents($this->storagePath);
        if ($raw === false || trim($raw) === '') {
            return;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Файл состояния поврежден: невалидный JSON.');
        }

        $this->state = array_replace($this->state, $decoded);
    }

    private function touchAndSave(): void
    {
        $this->state['updated_at'] = gmdate('c');
        $this->saveState();
    }

    private function saveState(): void
    {
        $dir = dirname($this->storagePath);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать директорию для state: ' . $dir);
        }

        $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($this->storagePath, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать state в ' . $this->storagePath);
        }

        @chmod($this->storagePath, 0600);
    }

    private function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new RuntimeException('Не удалось base64url-декодировать строку.');
        }

        return $decoded;
    }
}
