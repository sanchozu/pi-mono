<?php
declare(strict_types=1);

require_once __DIR__ . '/OpenAICodexProvider.php';
require_once __DIR__ . '/GeminiCliProviderClient.php';
require_once __DIR__ . '/QwenCliProvider.php';
require_once __DIR__ . '/GithubCopilotProvider.php';
require_once __DIR__ . '/OpenCodeZenProvider.php';

/** @var array<int, array<string, mixed>> $logLines */
$logLines = [];
$logger = static function (string $level, string $message, array $context = []) use (&$logLines): void {
    $logLines[] = [
        'time' => date('H:i:s'),
        'level' => $level,
        'message' => $message,
        'context' => $context,
    ];
};

$providerKey = (string) ($_POST['provider'] ?? $_GET['provider'] ?? 'chatgpt');
$providers = [
    'chatgpt' => new OpenAICodexProvider(__DIR__ . '/state-chatgpt.json', $logger),
    'gemini' => new GeminiCliProviderClient(__DIR__ . '/state-gemini.json', 'gemini', $logger),
    'antigravity' => new GeminiCliProviderClient(__DIR__ . '/state-antigravity.json', 'antigravity', $logger),
    'qwen' => new QwenCliProvider(__DIR__ . '/state-qwen.json', $logger),
    'copilot' => new GithubCopilotProvider(__DIR__ . '/state-copilot.json', $logger),
    'opencode-zen' => new OpenCodeZenProvider(__DIR__ . '/state-opencode-zen.json', $logger),
];

$provider = $providers[$providerKey] ?? $providers['chatgpt'];
$error = null;
$responseText = '';
$history = [];

try {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create_flow') {
        $flow = $provider->createAuthorizationFlow();
        $logger('info', 'Сгенерирован OAuth flow', $flow);
    }

    if ($action === 'exchange_callback') {
        $callbackInput = (string) ($_POST['callback_input'] ?? '');
        $credentials = $provider->exchangeFromCallbackInput($callbackInput);
        $logger('info', 'Токены успешно сохранены', $credentials);
    }

    if ($action === 'set_model') {
        $provider->setCurrentModel((string) ($_POST['model'] ?? ''));
        $logger('info', 'Модель обновлена', ['model' => $provider->getCurrentModel()]);
    }

    if ($action === 'ask') {
        $prompt = (string) ($_POST['prompt'] ?? '');
        $result = $provider->chat($prompt);
        $responseText = $result->content;
        $history[] = ['prompt' => $prompt, 'answer' => $responseText, 'model' => $result->model];
    }

    if ($action === 'logout') {
        $provider->logout();
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $logger('error', 'Исключение в UI', ['exception' => $error]);
}

$status = $provider->getTokenStatus();
$models = $provider->listModels();
$flow = isset($flow) ? $flow : null;
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI OAuth Test UI</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <h1 class="h3 mb-3">AI Providers OAuth PKCE Test UI</h1>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error, ENT_QUOTES); ?></div>
    <?php endif; ?>

    <form method="post" class="mb-4">
        <input type="hidden" name="action" value="">

        <div class="row g-3 mb-3">
            <?php foreach ($providers as $key => $_provider): ?>
                <div class="col-md-4">
                    <label class="card p-3 <?php echo $providerKey === $key ? 'border-primary' : ''; ?>">
                        <input type="radio" name="provider" value="<?php echo htmlspecialchars($key, ENT_QUOTES); ?>" <?php echo $providerKey === $key ? 'checked' : ''; ?>>
                        <span class="fw-semibold text-capitalize"><?php echo htmlspecialchars($key, ENT_QUOTES); ?></span>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="d-flex gap-2 mb-3">
            <button class="btn btn-primary" onclick="this.form.action.value='create_flow'">1) Сгенерировать OAuth URL</button>
            <button class="btn btn-success" onclick="this.form.action.value='exchange_callback'">2) Обменять callback</button>
            <button class="btn btn-outline-danger" onclick="this.form.action.value='logout'">Logout</button>
        </div>

        <label class="form-label">Callback URL / code / query string</label>
        <input class="form-control mb-3" name="callback_input" placeholder="https://.../callback?code=...&state=...">

        <?php if ($flow !== null): ?>
            <div class="alert alert-info">
                <div><strong>OAuth URL:</strong> <a href="<?php echo htmlspecialchars((string) $flow['url'], ENT_QUOTES); ?>" target="_blank">Открыть авторизацию</a></div>
                <?php if (isset($flow['user_code'])): ?>
                    <div><strong>User code:</strong> <?php echo htmlspecialchars((string) $flow['user_code'], ENT_QUOTES); ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <h2 class="h5 mt-4">Выбор модели</h2>
        <div class="row g-2 mb-3">
            <?php foreach ($models as $model): ?>
                <div class="col-md-4">
                    <label class="card p-2 <?php echo $provider->getCurrentModel() === $model['id'] ? 'border-primary' : ''; ?>">
                        <input type="radio" name="model" value="<?php echo htmlspecialchars((string) $model['id'], ENT_QUOTES); ?>" <?php echo $provider->getCurrentModel() === $model['id'] ? 'checked' : ''; ?>>
                        <?php echo htmlspecialchars((string) $model['name'], ENT_QUOTES); ?>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
        <button class="btn btn-outline-primary mb-4" onclick="this.form.action.value='set_model'">Применить модель</button>

        <h2 class="h5">Запрос</h2>
        <textarea class="form-control mb-2" rows="4" name="prompt" placeholder="Введите prompt"></textarea>
        <div class="row g-2 mb-2">
            <div class="col-md-3">
                <input class="form-control" type="number" step="0.1" name="temperature" placeholder="temperature" value="0.2">
            </div>
            <div class="col-md-3">
                <input class="form-control" type="number" name="max_tokens" placeholder="max_tokens" value="1024">
            </div>
        </div>
        <button class="btn btn-dark" onclick="this.form.action.value='ask'">Отправить</button>
    </form>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card p-3">
                <h3 class="h6">Ответ</h3>
                <pre class="mb-0"><?php echo htmlspecialchars($responseText, ENT_QUOTES); ?></pre>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card p-3">
                <h3 class="h6">Статус токена</h3>
                <pre><?php echo htmlspecialchars(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?></pre>
                <h4 class="h6">Snapshot credentials</h4>
                <pre><?php echo htmlspecialchars(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?></pre>
            </div>
        </div>
    </div>

    <div class="card p-3 mt-3">
        <h3 class="h6">История запросов</h3>
        <table class="table table-sm">
            <thead><tr><th>Prompt</th><th>Ответ</th><th>Модель</th></tr></thead>
            <tbody>
            <?php foreach ($history as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['prompt'], ENT_QUOTES); ?></td>
                    <td><?php echo htmlspecialchars($item['answer'], ENT_QUOTES); ?></td>
                    <td><?php echo htmlspecialchars($item['model'], ENT_QUOTES); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="card p-3 mt-3">
        <h3 class="h6">Лог</h3>
        <pre class="mb-0"><?php echo htmlspecialchars(json_encode($logLines, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?></pre>
    </div>
</div>
</body>
</html>
