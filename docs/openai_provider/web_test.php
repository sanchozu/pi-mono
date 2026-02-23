<?php

declare(strict_types=1);

require_once __DIR__ . '/OpenAICodexProvider.php';

use OpenAIProvider\OpenAICodexProvider;

$provider = new OpenAICodexProvider(__DIR__ . '/state.json');
$message = '';
$error = '';
$result = null;

$action = $_POST['action'] ?? '';

try {
    if ($action === 'create_flow') {
        $result = $provider->createAuthorizationFlow();
        $message = 'OAuth ссылка сгенерирована.';
    }

    if ($action === 'exchange_callback') {
        $callbackInput = trim((string) ($_POST['callback_input'] ?? ''));
        $result = $provider->exchangeFromCallbackInput($callbackInput);
        $message = 'Токены успешно получены и сохранены.';
    }

    if ($action === 'refresh_token') {
        $result = $provider->refreshAccessToken();
        $message = 'Токены успешно обновлены.';
    }

    if ($action === 'set_model') {
        $model = trim((string) ($_POST['model'] ?? ''));
        $provider->setCurrentModel($model);
        $message = 'Текущая модель сохранена.';
        $result = ['current_model' => $provider->getCurrentModel()];
    }

    if ($action === 'list_models') {
        $result = ['models' => $provider->listModels()];
        $message = 'Список моделей получен.';
    }

    if ($action === 'create_response') {
        $prompt = trim((string) ($_POST['prompt'] ?? ''));
        if ($prompt === '') {
            throw new RuntimeException('Введите текст запроса.');
        }
        $model = trim((string) ($_POST['model_for_request'] ?? ''));
        $result = $provider->createResponse([
            ['role' => 'user', 'content' => $prompt],
        ], $model !== '' ? $model : null);
        $message = 'Ответ получен.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$storedState = $provider->getStoredState();
$currentModel = $provider->getCurrentModel();

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>OpenAI Codex Provider Test UI</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 24px; max-width: 1100px; }
    h1, h2 { margin-bottom: 8px; }
    .card { border: 1px solid #ccc; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
    label { display: block; margin-bottom: 6px; font-weight: bold; }
    input[type=text], textarea { width: 100%; box-sizing: border-box; padding: 8px; }
    textarea { min-height: 100px; }
    button { padding: 8px 12px; margin-right: 8px; }
    pre { background: #111; color: #eee; padding: 12px; overflow: auto; border-radius: 8px; }
    .ok { color: #006400; }
    .err { color: #8b0000; }
  </style>
</head>
<body>
  <h1>OpenAI Codex Provider Test UI</h1>
  <p>Файл состояния: <code><?= h(__DIR__ . '/state.json') ?></code></p>

  <?php if ($message !== ''): ?>
    <p class="ok"><strong><?= h($message) ?></strong></p>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <p class="err"><strong>Ошибка:</strong> <?= h($error) ?></p>
  <?php endif; ?>

  <div class="card">
    <h2>1) OAuth flow</h2>
    <form method="post">
      <input type="hidden" name="action" value="create_flow">
      <button type="submit">Сгенерировать OAuth URL</button>
    </form>

    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="action" value="exchange_callback">
      <label for="callback_input">Callback URL / query / code</label>
      <textarea id="callback_input" name="callback_input" placeholder="http://localhost:1455/auth/callback?code=...&state=..."></textarea>
      <button type="submit">Разобрать и обменять code на токены</button>
    </form>
  </div>

  <div class="card">
    <h2>2) Токены и модель</h2>
    <form method="post">
      <input type="hidden" name="action" value="refresh_token">
      <button type="submit">Обновить токен (refresh)</button>
    </form>

    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="action" value="set_model">
      <label for="model">Текущая модель</label>
      <input id="model" type="text" name="model" value="<?= h($currentModel) ?>">
      <button type="submit">Сохранить модель</button>
    </form>

    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="action" value="list_models">
      <button type="submit">Получить список моделей</button>
    </form>
  </div>

  <div class="card">
    <h2>3) Запрос к модели</h2>
    <form method="post">
      <input type="hidden" name="action" value="create_response">
      <label for="model_for_request">Модель для запроса (необязательно)</label>
      <input id="model_for_request" type="text" name="model_for_request" placeholder="Оставьте пустым для current_model">
      <label for="prompt" style="margin-top:10px;">Prompt</label>
      <textarea id="prompt" name="prompt" placeholder="Напишите запрос..."></textarea>
      <button type="submit">Отправить запрос</button>
    </form>
  </div>

  <div class="card">
    <h2>Последний результат операции</h2>
    <pre><?= h(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: 'null') ?></pre>
  </div>

  <div class="card">
    <h2>Текущее состояние (state.json)</h2>
    <pre><?= h(json_encode($storedState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}') ?></pre>
  </div>
</body>
</html>
