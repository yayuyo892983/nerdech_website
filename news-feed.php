<?php
require_once __DIR__ . '/news_store.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

echo json_encode([
    'news' => nerdech_load_news(false),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
