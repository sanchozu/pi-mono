<?php
declare(strict_types=1);

require_once __DIR__ . '/AIProvider.php';

/**
 * Провайдер Qwen CLI с Device Code Flow + PKCE.
 */
final class QwenCliProvider extends AbstractAIProvider
{
    private const DEVICE_CODE_URL = 'https://chat.qwen.ai/api/v1/oauth2/device/code';
    private const TOKEN_URL = 'https://chat.qwen.ai/api/v1/oauth2/token';
    private const CLIENT_ID = 'f0304373b74a44d2b584a3fb70ca9e56';
    private const SCOPE = 'openid profile email model.completion';
    private const BASE_URL = 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions';

    public function createAuthorizationFlow(): array
    {
        return $this->createDeviceFlow();
    }

    public function createDeviceFlow(): array
    {
        $pkce = $this->generatePKCE();
        $body = http_build_query([
            'client_id' => self::CLIENT_ID,
            'scope' => self::SCOPE,
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => 'S256',
        ]);

        $ch = curl_init(self::DEVICE_CODE_URL);
        if ($ch === false) {
            throw new RuntimeException('Не удалось создать HTTP запрос device code.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => $body,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Device flow request error: ' . $error);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || $status >= 400) {
            throw new RuntimeException('Device flow request failed: ' . $raw);
        }

        $this->state['oauth'] = [
            'device_code' => $decoded['device_code'] ?? '',
            'verifier' => $pkce['verifier'],
            'expires_at' => time() + (int) ($decoded['expires_in'] ?? 300),
        ];
        $this->saveState();

        return $decoded + $pkce;
    }

    public function exchangeFromCallbackInput(string $input): array
    {
        $deviceCode = trim($input) !== '' ? trim($input) : (string) ($this->state['oauth']['device_code'] ?? '');
        if ($deviceCode === '') {
            throw new RuntimeException('device_code отсутствует для polling.');
        }

        return $this->pollForToken($deviceCode, (string) ($this->state['oauth']['verifier'] ?? ''));
    }

    public function pollForToken(string $deviceCode, string $verifier): array
    {
        $deadline = (int) ($this->state['oauth']['expires_at'] ?? (time() + 300));
        $interval = 2;

        while (time() < $deadline) {
            $token = $this->requestToken([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
                'client_id' => self::CLIENT_ID,
                'device_code' => $deviceCode,
                'code_verifier' => $verifier,
            ]);

            if (($token['access_token'] ?? '') !== '') {
                return $this->saveCredentials([
                    'access_token' => $token['access_token'],
                    'refresh_token' => $token['refresh_token'] ?? '',
                    'expires' => time() + (int) ($token['expires_in'] ?? 3600) - 300,
                    'accountId' => $token['resource_url'] ?? null,
                ]);
            }

            $error = (string) ($token['error'] ?? '');
            if ($error === 'authorization_pending') {
                sleep($interval);
                continue;
            }

            if ($error === 'slow_down') {
                $interval = min($interval + 5, 10);
                sleep($interval);
                continue;
            }

            if ($error === 'expired_token') {
                throw new RuntimeException('Device code истёк, перезапустите авторизацию.');
            }

            if ($error !== '') {
                throw new RuntimeException('OAuth ошибка: ' . $error . ' ' . (string) ($token['error_description'] ?? ''));
            }

            sleep($interval);
        }

        throw new RuntimeException('Время ожидания подтверждения истекло.');
    }

    public function refreshAccessToken(): array
    {
        $token = $this->requestToken([
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => (string) ($this->state['credentials']['refresh'] ?? ''),
        ]);

        return $this->saveCredentials([
            'access_token' => $token['access_token'] ?? '',
            'refresh_token' => $token['refresh_token'] ?? ($this->state['credentials']['refresh'] ?? ''),
            'expires' => time() + (int) ($token['expires_in'] ?? 3600) - 300,
        ]);
    }

    public function listModels(): array
    {
        return [
            ['id' => 'qwen3-coder-plus', 'name' => 'Qwen3 Coder Plus'],
            ['id' => 'qwen3-coder-flash', 'name' => 'Qwen3 Coder Flash'],
            ['id' => 'qwen3-vl-plus', 'name' => 'Qwen3 VL Plus'],
        ];
    }

    public function createResponse(array $messages, ?string $model = null): ModelResponse
    {
        $response = $this->httpJson('POST', self::BASE_URL, [
            'model' => $model ?: $this->getCurrentModel() ?: 'qwen3-coder-plus',
            'messages' => $messages,
            'temperature' => 0.2,
        ], ['Authorization: Bearer ' . $this->getValidAccessToken()]);

        return new ModelResponse(
            provider: 'qwen-cli',
            model: (string) ($response['model'] ?? 'qwen3-coder-plus'),
            content: (string) ($response['choices'][0]['message']['content'] ?? ''),
            raw: $response,
        );
    }

    public function createResponseStream(array $messages, callable $onEvent, ?string $model = null): void
    {
        $response = $this->createResponse($messages, $model);
        $onEvent(['type' => 'text', 'delta' => $response->content]);
        $onEvent(['type' => 'stop']);
    }

    private function requestToken(array $payload): array
    {
        $ch = curl_init(self::TOKEN_URL);
        if ($ch === false) {
            throw new RuntimeException('Не удалось инициализировать token request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => http_build_query($payload),
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Token request error: ' . $error);
        }

        curl_close($ch);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Невалидный JSON token response: ' . $raw);
        }

        return $decoded;
    }
}
