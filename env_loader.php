<?php
/**
 * .env ファイルを読み込み、$_ENV と getenv() に展開する
 * 直接アクセスは .htaccess で禁止してください
 */
function loadEnv(string $path): void {
    if (!file_exists($path)) return;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        // コメント行・空行をスキップ
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;

        [$key, $val] = array_map('trim', explode('=', $line, 2));
        if ($key === '') continue;

        $_ENV[$key] = $val;
        putenv("{$key}={$val}");
    }
}

loadEnv(__DIR__ . '/.env');
