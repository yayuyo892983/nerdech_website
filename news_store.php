<?php

const NERDECH_NEWS_SOURCE_FILE = __DIR__ . '/data/news-source.json';

function nerdech_ensure_data_dir(): void {
    $dir = __DIR__ . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Order allow,deny\nDeny from all\n");
    }
}

function nerdech_default_news(): array {
    return [
        [
            'id' => 'site_renewal_20260925',
            'date' => '2026-09-25',
            'display_date' => '2026.09.25',
            'title' => 'コーポレートサイトをリニューアルしました',
            'body' => 'Company Brain の紹介、事例、会社情報のページを追加しました。',
            'link_label' => '',
            'link_url' => '',
            'published' => true,
        ],
        [
            'id' => 'company_registration_20260430',
            'date' => '2026-04-30',
            'display_date' => '2026.04.30',
            'title' => '合同会社 nerdech を設立しました',
            'body' => 'AIコンサルティング・受託開発を担う法人として、合同会社 nerdech の登記を完了しました。',
            'link_label' => '',
            'link_url' => '',
            'published' => true,
        ],
    ];
}

function nerdech_format_news_date(string $date): string {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return str_replace('-', '.', $date);
    }
    if (preg_match('/^\d{4}-\d{2}$/', $date)) {
        return str_replace('-', '.', $date);
    }
    return $date;
}

function nerdech_news_sort_value(array $item): int {
    $date = (string) ($item['date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}$/', $date)) {
        $date .= '-01';
    }
    $timestamp = strtotime($date);
    return $timestamp === false ? 0 : $timestamp;
}

function nerdech_normalize_news_item(array $item): array {
    $date = trim((string) ($item['date'] ?? ''));
    return [
        'id' => (string) ($item['id'] ?? uniqid('news_', true)),
        'date' => $date,
        'display_date' => trim((string) ($item['display_date'] ?? '')) ?: nerdech_format_news_date($date),
        'title' => trim((string) ($item['title'] ?? '')),
        'body' => trim((string) ($item['body'] ?? '')),
        'link_label' => trim((string) ($item['link_label'] ?? '')),
        'link_url' => trim((string) ($item['link_url'] ?? '')),
        'published' => array_key_exists('published', $item) ? (bool) $item['published'] : true,
    ];
}

function nerdech_load_news(bool $includeDrafts = false): array {
    $items = nerdech_default_news();
    if (file_exists(NERDECH_NEWS_SOURCE_FILE)) {
        $decoded = json_decode((string) file_get_contents(NERDECH_NEWS_SOURCE_FILE), true);
        if (is_array($decoded)) {
            $items = $decoded;
        }
    }

    $items = array_values(array_filter($items, 'is_array'));
    $items = array_map(function (array $item, int $index): array {
        $normalized = nerdech_normalize_news_item($item);
        $normalized['_order'] = $index;
        return $normalized;
    }, $items, array_keys($items));

    if (!$includeDrafts) {
        $items = array_values(array_filter($items, fn($item) => !empty($item['published'])));
    }

    usort($items, function (array $a, array $b): int {
        $dateCompare = nerdech_news_sort_value($b) <=> nerdech_news_sort_value($a);
        if ($dateCompare !== 0) return $dateCompare;
        return ((int) ($a['_order'] ?? 0)) <=> ((int) ($b['_order'] ?? 0));
    });

    foreach ($items as &$item) {
        unset($item['_order']);
    }
    unset($item);

    return $items;
}

function nerdech_save_news(array $items): void {
    nerdech_ensure_data_dir();
    $items = array_map('nerdech_normalize_news_item', array_filter($items, 'is_array'));
    file_put_contents(NERDECH_NEWS_SOURCE_FILE, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}
