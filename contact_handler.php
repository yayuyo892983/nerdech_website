<?php
require_once __DIR__ . '/env_loader.php';

$admin_email = $_ENV['ADMIN_EMAIL'] ?? '';

// データ保存先
$data_file = __DIR__ . '/data/contacts.json';

// POST以外はリダイレクト
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contact.html');
    exit;
}

// 入力値の取得・サニタイズ
$name    = htmlspecialchars(trim($_POST['name']    ?? ''), ENT_QUOTES, 'UTF-8');
$email   = trim($_POST['email']   ?? '');
$message = htmlspecialchars(trim($_POST['message'] ?? ''), ENT_QUOTES, 'UTF-8');

// バリデーション
if (empty($name) || empty($message) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: contact.html?error=1');
    exit;
}

$email_safe = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

// データディレクトリの作成と保護
$data_dir = dirname($data_file);
if (!is_dir($data_dir)) {
    mkdir($data_dir, 0755, true);
    file_put_contents($data_dir . '/.htaccess', "Deny from all\n");
}

// 既存データの読み込み
$contacts = [];
if (file_exists($data_file)) {
    $contacts = json_decode(file_get_contents($data_file), true) ?? [];
}

// 新規エントリの追加
$contacts[] = [
    'id'      => uniqid('', true),
    'date'    => date('Y-m-d H:i:s'),
    'name'    => $name,
    'email'   => $email_safe,
    'message' => $message,
    'read'    => false,
];

file_put_contents($data_file, json_encode($contacts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// メール送信
mb_language('Japanese');
mb_internal_encoding('UTF-8');

$subject = '【nerdech】お問い合わせが届きました';
$body    = "お問い合わせが届きました。\r\n\r\n"
         . "お名前  : {$name}\r\n"
         . "メール  : {$email}\r\n\r\n"
         . "メッセージ:\r\n{$message}\r\n";

mb_send_mail(
    $admin_email,
    $subject,
    $body,
    "From: noreply@nerdech.com\r\nReply-To: {$email}"
);

header('Location: contact.html?sent=1');
exit;
