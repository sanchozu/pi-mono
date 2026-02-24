# AI Providers (PHP 8.5)

Набор PHP-классов для OAuth PKCE интеграций AI-провайдеров:
- ChatGPT Plus/Pro
- Google Cloud Code Assist (Gemini CLI)
- Antigravity
- Qwen CLI
- GitHub Copilot
- OpenCode Zen

## Быстрый старт

```bash
cd ai_providers
php -S 127.0.0.1:8080 test_ui.php
```

Откройте `http://127.0.0.1:8080` и выполните OAuth flow через UI.

## Файлы

- `AIProvider.php` — базовая абстракция и общие утилиты
- `OpenAICodexProvider.php` — ChatGPT OAuth PKCE
- `GeminiCliProviderClient.php` — Gemini + Antigravity
- `QwenCliProvider.php` — Device Code Flow
- `GithubCopilotProvider.php` — Copilot OAuth
- `OpenCodeZenProvider.php` — OpenCode Zen OAuth
- `test_ui.php` — веб-интерфейс для ручного тестирования
- `DOCUMENTATION.md` — подробная документация
