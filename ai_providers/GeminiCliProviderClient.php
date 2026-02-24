<?php
declare(strict_types=1);

require_once __DIR__ . '/AIProvider.php';

/**
 * Провайдер Google Cloud Code Assist (Gemini CLI) и Antigravity.
 */
final class GeminiCliProviderClient extends AbstractAIProvider
{
    private const GEMINI_AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const GEMINI_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const GEMINI_API_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct(
        string $storagePath,
        private readonly string $provider = 'gemini',
        ?callable $logger = null,
    ) {
        parent::__construct($storagePath, $logger);
    }

    public function createAuthorizationFlow(): array
    {
        $pkce = $this->generatePKCE();
        $this->state['oauth'] = ['state' => $pkce['state'], 'verifier' => $pkce['verifier']];
        $this->saveState();

        $scope = $this->provider === 'antigravity'
            ? 'openid profile email https://www.googleapis.com/auth/cloud-platform'
            : 'openid profile email https://www.googleapis.com/auth/generative-language.retriever';

        $query = http_build_query([
            'client_id' => getenv('GEMINI_OAUTH_CLIENT_ID') ?: 'gemini-cli-client',
            'redirect_uri' => 'urn:ietf:wg:oauth:2.0:oob',
            'response_type' => 'code',
            'scope' => $scope,
            'access_type' => 'offline',
            'code_challenge' => $pkce['challenge'],
            'code_challenge_method' => 'S256',
            'state' => $pkce['state'],
        ]);

        return ['url' => self::GEMINI_AUTH_URL . '?' . $query] + $pkce;
    }

    public function exchangeFromCallbackInput(string $input): array
    {
        $parsed = $this->parseCallbackInput($input);
        $expectedState = (string) ($this->state['oauth']['state'] ?? '');
        if ($expectedState !== '' && $parsed['state'] !== null && $parsed['state'] !== $expectedState) {
            throw new RuntimeException('OAuth state не совпадает.');
        }

        $code = $parsed['code'] !== '' ? $parsed['code'] : trim($input);
        if ($code === '') {
            throw new RuntimeException('Authorization code не найден.');
        }

        $token = $this->httpJson('POST', self::GEMINI_TOKEN_URL, [
            'client_id' => getenv('GEMINI_OAUTH_CLIENT_ID') ?: 'gemini-cli-client',
            'client_secret' => getenv('GEMINI_OAUTH_CLIENT_SECRET') ?: '',
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'urn:ietf:wg:oauth:2.0:oob',
            'code_verifier' => (string) ($this->state['oauth']['verifier'] ?? ''),
        ]);

        return $this->saveCredentials([
            'access_token' => $token['access_token'] ?? '',
            'refresh_token' => $token['refresh_token'] ?? '',
            'expires' => time() + (int) ($token['expires_in'] ?? 3600),
            'accountId' => $token['id_token'] ?? null,
        ]);
    }

    public function refreshAccessToken(): array
    {
        $token = $this->httpJson('POST', self::GEMINI_TOKEN_URL, [
            'client_id' => getenv('GEMINI_OAUTH_CLIENT_ID') ?: 'gemini-cli-client',
            'client_secret' => getenv('GEMINI_OAUTH_CLIENT_SECRET') ?: '',
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) ($this->state['credentials']['refresh'] ?? ''),
        ]);

        return $this->saveCredentials([
            'access_token' => $token['access_token'] ?? '',
            'refresh_token' => $this->state['credentials']['refresh'] ?? '',
            'expires' => time() + (int) ($token['expires_in'] ?? 3600),
        ]);
    }

    public function listModels(): array
    {
        if ($this->provider === 'antigravity') {
            return [
                ['id' => 'gemini-3-pro', 'name' => 'Gemini 3 Pro'],
                ['id' => 'claude-sonnet-4', 'name' => 'Claude Sonnet 4'],
                ['id' => 'gpt-oss-120b', 'name' => 'GPT-OSS 120B'],
            ];
        }

        return [
            ['id' => 'gemini-2.5-pro', 'name' => 'Gemini 2.5 Pro'],
            ['id' => 'gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash'],
        ];
    }

    public function createResponse(array $messages, ?string $model = null): ModelResponse
    {
        $resolvedModel = $model ?: $this->getCurrentModel() ?: $this->listModels()[0]['id'];
        $apiModelUrl = self::GEMINI_API_URL . '/' . $resolvedModel . ':generateContent';

        $contents = [];
        foreach ($messages as $message) {
            $contents[] = [
                'role' => (($message['role'] ?? 'user') === 'assistant') ? 'model' : 'user',
                'parts' => [['text' => (string) ($message['content'] ?? '')]],
            ];
        }

        $response = $this->httpJson('POST', $apiModelUrl, [
            'contents' => $contents,
            'generationConfig' => ['temperature' => 0.3],
        ], ['Authorization: Bearer ' . $this->getValidAccessToken()]);

        $text = (string) ($response['candidates'][0]['content']['parts'][0]['text'] ?? '');

        return new ModelResponse(
            provider: $this->provider,
            model: $resolvedModel,
            content: $text,
            raw: $response,
            inputTokens: isset($response['usageMetadata']['promptTokenCount']) ? (int) $response['usageMetadata']['promptTokenCount'] : null,
            outputTokens: isset($response['usageMetadata']['candidatesTokenCount']) ? (int) $response['usageMetadata']['candidatesTokenCount'] : null,
        );
    }

    public function createResponseStream(array $messages, callable $onEvent, ?string $model = null): void
    {
        $response = $this->createResponse($messages, $model);
        $onEvent(['type' => 'text', 'delta' => $response->content]);
        $onEvent(['type' => 'stop']);
    }
}
