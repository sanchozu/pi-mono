<?php
declare(strict_types=1);

require_once __DIR__ . '/AIProvider.php';

/**
 * Провайдер OpenCode Zen по мотивам anomalyco/opencode.
 */
final class OpenCodeZenProvider extends AbstractAIProvider
{
    private const AUTH_URL = 'https://opencode.ai/oauth/authorize';
    private const TOKEN_URL = 'https://opencode.ai/oauth/token';
    private const API_URL = 'https://opencode.ai/api/v1/chat/completions';

    public function createAuthorizationFlow(): array
    {
        $pkce = $this->generatePKCE();
        $this->state['oauth'] = ['state' => $pkce['state'], 'verifier' => $pkce['verifier']];
        $this->saveState();

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => getenv('OPENCODE_CLIENT_ID') ?: 'opencode-zen-cli',
            'redirect_uri' => 'https://opencode.ai/oauth/callback',
            'scope' => 'openid profile offline_access zen:chat',
            'state' => $pkce['state'],
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => 'S256',
        ]);

        return ['url' => self::AUTH_URL . '?' . $query] + $pkce;
    }

    public function exchangeFromCallbackInput(string $input): array
    {
        $parsed = $this->parseCallbackInput($input);
        if ($parsed['state'] !== (string) ($this->state['oauth']['state'] ?? '')) {
            throw new RuntimeException('State параметр не совпадает.');
        }

        $token = $this->httpJson('POST', self::TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'client_id' => getenv('OPENCODE_CLIENT_ID') ?: 'opencode-zen-cli',
            'client_secret' => getenv('OPENCODE_CLIENT_SECRET') ?: '',
            'code' => $parsed['code'],
            'redirect_uri' => 'https://opencode.ai/oauth/callback',
            'code_verifier' => (string) ($this->state['oauth']['verifier'] ?? ''),
        ]);

        return $this->saveCredentials([
            'access_token' => $token['access_token'] ?? '',
            'refresh_token' => $token['refresh_token'] ?? '',
            'expires' => time() + (int) ($token['expires_in'] ?? 3600),
            'accountId' => $token['user']['id'] ?? null,
        ]);
    }

    public function refreshAccessToken(): array
    {
        $token = $this->httpJson('POST', self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'client_id' => getenv('OPENCODE_CLIENT_ID') ?: 'opencode-zen-cli',
            'client_secret' => getenv('OPENCODE_CLIENT_SECRET') ?: '',
            'refresh_token' => (string) ($this->state['credentials']['refresh'] ?? ''),
        ]);

        return $this->saveCredentials([
            'access_token' => $token['access_token'] ?? '',
            'refresh_token' => $token['refresh_token'] ?? ($this->state['credentials']['refresh'] ?? ''),
            'expires' => time() + (int) ($token['expires_in'] ?? 3600),
        ]);
    }

    public function listModels(): array
    {
        return [
            ['id' => 'zen-coder', 'name' => 'OpenCode Zen Coder'],
            ['id' => 'zen-architect', 'name' => 'OpenCode Zen Architect'],
        ];
    }

    public function createResponse(array $messages, ?string $model = null): ModelResponse
    {
        $response = $this->httpJson('POST', self::API_URL, [
            'model' => $model ?: $this->getCurrentModel() ?: 'zen-coder',
            'messages' => $messages,
        ], ['Authorization: Bearer ' . $this->getValidAccessToken()]);

        return new ModelResponse(
            provider: 'opencode-zen',
            model: (string) ($response['model'] ?? 'zen-coder'),
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
}
