<?php
declare(strict_types=1);

require_once __DIR__ . '/AIProvider.php';

/**
 * Провайдер GitHub Copilot с OAuth PKCE.
 */
final class GithubCopilotProvider extends AbstractAIProvider
{
    private const AUTH_URL = 'https://github.com/login/oauth/authorize';
    private const TOKEN_URL = 'https://github.com/login/oauth/access_token';
    private const API_URL = 'https://api.githubcopilot.com/chat/completions';

    public function createAuthorizationFlow(): array
    {
        $pkce = $this->generatePKCE();
        $this->state['oauth'] = ['state' => $pkce['state'], 'verifier' => $pkce['verifier']];
        $this->saveState();

        $query = http_build_query([
            'client_id' => getenv('GITHUB_COPILOT_CLIENT_ID') ?: 'copilot-chat',
            'redirect_uri' => 'https://github.com/login/oauth/callback',
            'scope' => 'read:user user:email',
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
            throw new RuntimeException('Проверка state не пройдена.');
        }

        $token = $this->httpJson('POST', self::TOKEN_URL, [
            'client_id' => getenv('GITHUB_COPILOT_CLIENT_ID') ?: 'copilot-chat',
            'client_secret' => getenv('GITHUB_COPILOT_CLIENT_SECRET') ?: '',
            'code' => $parsed['code'],
            'redirect_uri' => 'https://github.com/login/oauth/callback',
            'code_verifier' => (string) ($this->state['oauth']['verifier'] ?? ''),
        ], ['Accept: application/json']);

        return $this->saveCredentials([
            'access_token' => $token['access_token'] ?? '',
            'refresh_token' => '',
            'expires' => time() + 3600,
            'accountId' => null,
        ]);
    }

    public function refreshAccessToken(): array
    {
        throw new RuntimeException('GitHub Copilot token refresh не поддерживается этим примером.');
    }

    public function listModels(): array
    {
        return [
            ['id' => 'gpt-4o-copilot', 'name' => 'GPT-4o Copilot'],
            ['id' => 'claude-sonnet-4-copilot', 'name' => 'Claude Sonnet 4 Copilot'],
        ];
    }

    public function createResponse(array $messages, ?string $model = null): ModelResponse
    {
        $response = $this->httpJson('POST', self::API_URL, [
            'model' => $model ?: $this->getCurrentModel() ?: 'gpt-4o-copilot',
            'messages' => $messages,
        ], ['Authorization: Bearer ' . $this->getValidAccessToken()]);

        return new ModelResponse(
            provider: 'github-copilot',
            model: (string) ($response['model'] ?? 'gpt-4o-copilot'),
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
