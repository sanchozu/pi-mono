<?php
declare(strict_types=1);

/**
 * Унифицированный ответ модели.
 */
final class ModelResponse
{
    /** @param array<int, array<string, mixed>> $raw */
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly string $content,
        public readonly array $raw = [],
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
    ) {
    }
}

/**
 * Базовая абстракция для AI-провайдеров с OAuth/PKCE.
 */
abstract class AbstractAIProvider
{
    protected const TOKEN_REFRESH_BUFFER_SECONDS = 60;

    /** @var callable|null */
    protected $logger;

    /** @var array<string, mixed> */
    protected array $state = [];

    /** @var array<int, array<string, string>> */
    protected array $history = [];

    public function __construct(
        protected readonly string $storagePath,
        ?callable $logger = null,
    ) {
        $this->logger = $logger;
        $this->loadState();
    }

    abstract public function createAuthorizationFlow(): array;

    abstract public function exchangeFromCallbackInput(string $input): array;

    abstract public function refreshAccessToken(): array;

    abstract public function listModels(): array;

    abstract public function createResponse(array $messages, ?string $model = null): ModelResponse;

    abstract public function createResponseStream(array $messages, callable $onEvent, ?string $model = null): void;

    public function isAuthenticated(): bool
    {
        $access = $this->state['credentials']['access'] ?? '';
        $expires = (int) ($this->state['credentials']['expires'] ?? 0);

        return $access !== '' && $expires > (time() + self::TOKEN_REFRESH_BUFFER_SECONDS);
    }

    public function getValidAccessToken(): string
    {
        $this->ensureValidToken();
        $access = (string) ($this->state['credentials']['access'] ?? '');
        if ($access === '') {
            throw new RuntimeException('Токен доступа отсутствует. Выполните OAuth авторизацию.');
        }

        return $access;
    }

    public function setCurrentModel(string $modelId): void
    {
        $this->state['current_model'] = $modelId;
        $this->saveState();
    }

    public function getCurrentModel(): string
    {
        return (string) ($this->state['current_model'] ?? '');
    }

    public function chat(string $message): ModelResponse
    {
        $this->history[] = ['role' => 'user', 'content' => $message];
        $response = $this->createResponse($this->history, $this->getCurrentModel());
        $this->history[] = ['role' => 'assistant', 'content' => $response->content];

        return $response;
    }

    public function logout(): void
    {
        $this->state['credentials'] = [
            'access' => '',
            'refresh' => '',
            'expires' => 0,
            'accountId' => null,
        ];
        $this->saveState();
        $this->log('info', 'Пользователь вышел из сессии OAuth.');
    }

    /** @return array<string, mixed> */
    public function getTokenStatus(): array
    {
        $credentials = $this->state['credentials'] ?? [];
        $expires = (int) ($credentials['expires'] ?? 0);
        $remaining = $expires - time();

        return [
            'authenticated' => $this->isAuthenticated(),
            'expires_at' => $expires,
            'expires_in_seconds' => max(0, $remaining),
            'access_masked' => $this->maskToken((string) ($credentials['access'] ?? '')),
            'refresh_masked' => $this->maskToken((string) ($credentials['refresh'] ?? '')),
            'account_id' => $credentials['accountId'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function ensureValidToken(): array
    {
        if ($this->isAuthenticated()) {
            return $this->state['credentials'] ?? [];
        }

        $refresh = (string) ($this->state['credentials']['refresh'] ?? '');
        if ($refresh === '') {
            throw new RuntimeException('Требуется повторная авторизация: refresh token отсутствует.');
        }

        return $this->refreshAccessToken();
    }

    /**
     * Поддерживает URL callback, query string и формат "code=...&state=...".
     *
     * @return array{code:string,state:?string,error:?string,error_description:?string}
     */
    public function parseCallbackInput(string $input): array
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            throw new InvalidArgumentException('Callback input пуст.');
        }

        $query = '';
        if (str_contains($trimmed, '://')) {
            $parsed = parse_url($trimmed);
            $query = (string) ($parsed['query'] ?? '');
        } elseif (str_contains($trimmed, '?')) {
            $parts = explode('?', $trimmed, 2);
            $query = $parts[1] ?? '';
        } else {
            $query = ltrim($trimmed, '?');
        }

        parse_str($query, $params);

        return [
            'code' => (string) ($params['code'] ?? ''),
            'state' => isset($params['state']) ? (string) $params['state'] : null,
            'error' => isset($params['error']) ? (string) $params['error'] : null,
            'error_description' => isset($params['error_description']) ? (string) $params['error_description'] : null,
        ];
    }

    /** @return array{verifier:string,challenge:string,state:string} */
    protected function generatePKCE(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');

        return ['verifier' => $verifier, 'challenge' => $challenge, 'state' => $state];
    }

    /** @param array<string,mixed> $credentials */
    protected function saveCredentials(array $credentials): array
    {
        $this->state['credentials'] = [
            'access' => (string) ($credentials['access'] ?? $credentials['access_token'] ?? ''),
            'refresh' => (string) ($credentials['refresh'] ?? $credentials['refresh_token'] ?? ''),
            'expires' => (int) ($credentials['expires'] ?? (time() + (int) ($credentials['expires_in'] ?? 3600))),
            'accountId' => $credentials['accountId'] ?? $credentials['account_id'] ?? null,
        ];
        $this->saveState();

        return $this->state['credentials'];
    }

    protected function decodeJwtPayload(string $jwt): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return null;
        }

        $payload = strtr($parts[1], '-_', '+/');
        $payload .= str_repeat('=', (4 - (strlen($payload) % 4)) % 4);
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return null;
        }

        $json = json_decode($decoded, true);
        return is_array($json) ? $json : null;
    }

    protected function maskToken(string $token): string
    {
        if ($token === '') {
            return '(empty)';
        }
        if (strlen($token) <= 12) {
            return str_repeat('*', strlen($token));
        }

        return substr($token, 0, 6) . '...' . substr($token, -4);
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        if (is_callable($this->logger)) {
            ($this->logger)($level, $message, $context);
        }
    }

    protected function loadState(): void
    {
        if (!file_exists($this->storagePath)) {
            $this->state = [
                'credentials' => [
                    'access' => '',
                    'refresh' => '',
                    'expires' => 0,
                    'accountId' => null,
                ],
                'current_model' => '',
                'session_id' => bin2hex(random_bytes(8)),
            ];
            return;
        }

        $raw = file_get_contents($this->storagePath);
        if ($raw === false || $raw === '') {
            throw new RuntimeException('Не удалось прочитать state файл.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('State файл повреждён: JSON невалиден.');
        }

        $this->state = $decoded;
    }

    protected function saveState(): void
    {
        $dir = dirname($this->storagePath);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать директорию для state файла.');
        }

        $json = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Не удалось сериализовать state файл.');
        }

        if (file_put_contents($this->storagePath, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось сохранить state файл.');
        }

        chmod($this->storagePath, 0600);
    }

    /** @return array<string,mixed> */
    protected function httpJson(string $method, string $url, array $payload = [], array $headers = []): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Не удалось инициализировать HTTP клиент.');
        }

        $defaultHeaders = ['Accept: application/json'];
        if ($payload !== []) {
            $defaultHeaders[] = 'Content-Type: application/json';
        }

        $finalHeaders = array_merge($defaultHeaders, $headers);

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $finalHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        if ($payload !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP ошибка: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Ожидался JSON ответ. Получено: ' . $raw);
        }

        if ($status >= 400) {
            throw new RuntimeException('HTTP ' . $status . ': ' . json_encode($decoded, JSON_UNESCAPED_UNICODE));
        }

        return $decoded;
    }
}
