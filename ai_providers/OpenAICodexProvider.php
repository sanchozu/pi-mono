<?php
declare(strict_types=1);

require_once __DIR__ . '/AIProvider.php';

/**
 * Провайдер ChatGPT Plus/Pro через OAuth PKCE.
 */
final class OpenAICodexProvider extends AbstractAIProvider
{
    private const AUTH_URL = 'https://auth.openai.com/oauth/authorize';
    private const TOKEN_URL = 'https://auth.openai.com/oauth/token';
    private const API_URL = 'https://api.openai.com/v1/chat/completions';
    private const CLIENT_ID = 'openai-chatgpt-cli';
    private const REDIRECT_URI = 'https://oauth.openai.com/callback';

    public function createAuthorizationFlow(): array
    {
        $pkce = $this->generatePKCE();
        $this->state['oauth'] = [
            'state' => $pkce['state'],
            'verifier' => $pkce['verifier'],
            'created_at' => time(),
        ];
        $this->saveState();

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => self::CLIENT_ID,
            'redirect_uri' => self::REDIRECT_URI,
            'scope' => 'openid profile email offline_access',
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => 'S256',
            'state' => $pkce['state'],
        ]);

        return ['url' => self::AUTH_URL . '?' . $query] + $pkce;
    }

    public function exchangeFromCallbackInput(string $input): array
    {
        $parsed = $this->parseCallbackInput($input);
        if ($parsed['error']) {
            throw new RuntimeException('OAuth ошибка: ' . $parsed['error'] . ' ' . ($parsed['error_description'] ?? ''));
        }

        $expectedState = (string) ($this->state['oauth']['state'] ?? '');
        if ($expectedState === '' || $parsed['state'] !== $expectedState) {
            throw new RuntimeException('Проверка state не пройдена.');
        }

        $verifier = (string) ($this->state['oauth']['verifier'] ?? '');
        if ($verifier === '') {
            throw new RuntimeException('code_verifier не найден в состоянии.');
        }

        $token = $this->httpJson('POST', self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'client_id' => self::CLIENT_ID,
            'code' => $parsed['code'],
            'redirect_uri' => self::REDIRECT_URI,
            'code_verifier' => $verifier,
        ]);

        $payload = $this->decodeJwtPayload((string) ($token['id_token'] ?? ''));
        $accountId = $payload['sub'] ?? null;

        return $this->saveCredentials([
            'access_token' => $token['access_token'] ?? '',
            'refresh_token' => $token['refresh_token'] ?? '',
            'expires' => time() + (int) ($token['expires_in'] ?? 3600),
            'accountId' => $accountId,
        ]);
    }

    public function refreshAccessToken(): array
    {
        $refreshToken = (string) ($this->state['credentials']['refresh'] ?? '');
        $token = $this->httpJson('POST', self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'client_id' => self::CLIENT_ID,
            'refresh_token' => $refreshToken,
        ]);

        return $this->saveCredentials([
            'access_token' => $token['access_token'] ?? '',
            'refresh_token' => $token['refresh_token'] ?? $refreshToken,
            'expires' => time() + (int) ($token['expires_in'] ?? 3600),
        ]);
    }

    public function listModels(): array
    {
        return [
            ['id' => 'gpt-5', 'name' => 'GPT-5'],
            ['id' => 'gpt-4.1', 'name' => 'GPT-4.1'],
            ['id' => 'o4-mini', 'name' => 'o4-mini'],
        ];
    }

    public function createResponse(array $messages, ?string $model = null): ModelResponse
    {
        $response = $this->httpJson('POST', self::API_URL, [
            'model' => $model ?: $this->getCurrentModel() ?: 'gpt-5',
            'messages' => $messages,
            'temperature' => 0.2,
        ], [
            'Authorization: Bearer ' . $this->getValidAccessToken(),
        ]);

        $content = (string) ($response['choices'][0]['message']['content'] ?? '');

        return new ModelResponse(
            provider: 'chatgpt-plus-pro',
            model: (string) ($response['model'] ?? ($model ?: 'gpt-5')),
            content: $content,
            raw: $response,
            inputTokens: isset($response['usage']['prompt_tokens']) ? (int) $response['usage']['prompt_tokens'] : null,
            outputTokens: isset($response['usage']['completion_tokens']) ? (int) $response['usage']['completion_tokens'] : null,
        );
    }

    public function createResponseStream(array $messages, callable $onEvent, ?string $model = null): void
    {
        $response = $this->createResponse($messages, $model);
        $onEvent(['type' => 'text', 'delta' => $response->content]);
        $onEvent(['type' => 'stop']);
    }
}
