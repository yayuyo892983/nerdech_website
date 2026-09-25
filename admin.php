<?php
session_start();

if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'admin.php') {
    header('Location: /admin/');
    exit;
}

require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/news_store.php';

const CONTACTS_FILE = __DIR__ . '/data/contacts.json';
const AUTH_FILE = __DIR__ . '/data/admin_auth.php';
const ADMIN_HOME = '/admin/';

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirect_admin(array $params = []): void {
    $url = ADMIN_HOME;
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pull_flash(): ?array {
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        flash('error', 'セッションが切れました。もう一度操作してください。');
        redirect_admin();
    }
}

function load_contacts(): array {
    if (!file_exists(CONTACTS_FILE)) return [];
    $data = json_decode((string) file_get_contents(CONTACTS_FILE), true);
    return is_array($data) ? $data : [];
}

function save_contacts(array $contacts): void {
    nerdech_ensure_data_dir();
    file_put_contents(CONTACTS_FILE, json_encode(array_values($contacts), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function load_password_hash(): ?string {
    if (!file_exists(AUTH_FILE)) return null;
    $data = include AUTH_FILE;
    if (!is_array($data)) return null;
    $hash = $data['password_hash'] ?? null;
    return is_string($hash) && $hash !== '' ? $hash : null;
}

function verify_admin_password(string $password): bool {
    $hash = load_password_hash();
    if ($hash) {
        return password_verify($password, $hash);
    }

    $envPassword = $_ENV['ADMIN_PASSWORD'] ?? getenv('ADMIN_PASSWORD');
    $envPassword = is_string($envPassword) ? $envPassword : '';
    return is_string($envPassword) && $envPassword !== '' && hash_equals($envPassword, $password);
}

function save_admin_password(string $password): void {
    nerdech_ensure_data_dir();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $payload = "<?php\nreturn [\n    'password_hash' => " . var_export($hash, true) . ",\n];\n";
    file_put_contents(AUTH_FILE, $payload, LOCK_EX);
}

if (isset($_GET['logout'])) {
    session_destroy();
    redirect_admin();
}

$loginError = false;
if (empty($_SESSION['admin_logged_in']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'login') {
        $password = (string) ($_POST['password'] ?? '');
        if (verify_admin_password($password)) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            csrf_token();
            redirect_admin();
        }
        $loginError = true;
    }
}

$loggedIn = !empty($_SESSION['admin_logged_in']);
if ($loggedIn) {
    csrf_token();
}

if ($loggedIn && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_news') {
        $date = trim((string) ($_POST['date'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $linkLabel = trim((string) ($_POST['link_label'] ?? ''));
        $linkUrl = trim((string) ($_POST['link_url'] ?? ''));

        if ($date === '' || $title === '' || $body === '') {
            flash('error', 'date / title / body は必須です。');
            redirect_admin(['panel' => 'news']);
        }

        if (($linkLabel === '') !== ($linkUrl === '')) {
            flash('error', 'リンクは label と URL を両方入力してください。');
            redirect_admin(['panel' => 'news']);
        }

        if ($linkUrl !== '' && !filter_var($linkUrl, FILTER_VALIDATE_URL)) {
            flash('error', 'リンクURLの形式が正しくありません。');
            redirect_admin(['panel' => 'news']);
        }

        $news = nerdech_load_news(true);
        $news[] = [
            'id' => uniqid('news_', true),
            'date' => $date,
            'display_date' => nerdech_format_news_date($date),
            'title' => $title,
            'body' => $body,
            'link_label' => $linkLabel,
            'link_url' => $linkUrl,
            'published' => !empty($_POST['published']),
        ];
        nerdech_save_news($news);
        flash('success', 'ニュースを追加しました。');
        redirect_admin(['panel' => 'news']);
    }

    if ($action === 'update_news') {
        $id = (string) ($_POST['id'] ?? '');
        $date = trim((string) ($_POST['date'] ?? ''));
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $linkLabel = trim((string) ($_POST['link_label'] ?? ''));
        $linkUrl = trim((string) ($_POST['link_url'] ?? ''));

        if ($id === '' || $date === '' || $title === '' || $body === '') {
            flash('error', 'id / date / title / body は必須です。');
            redirect_admin(['panel' => 'news']);
        }

        if (($linkLabel === '') !== ($linkUrl === '')) {
            flash('error', 'リンクは label と URL を両方入力してください。');
            redirect_admin(['panel' => 'news']);
        }

        if ($linkUrl !== '' && !filter_var($linkUrl, FILTER_VALIDATE_URL)) {
            flash('error', 'リンクURLの形式が正しくありません。');
            redirect_admin(['panel' => 'news']);
        }

        $news = nerdech_load_news(true);
        $updated = false;
        foreach ($news as &$item) {
            if (($item['id'] ?? '') === $id) {
                $item = [
                    'id' => $id,
                    'date' => $date,
                    'display_date' => nerdech_format_news_date($date),
                    'title' => $title,
                    'body' => $body,
                    'link_label' => $linkLabel,
                    'link_url' => $linkUrl,
                    'published' => !empty($_POST['published']),
                ];
                $updated = true;
                break;
            }
        }
        unset($item);

        if (!$updated) {
            flash('error', '編集対象のニュースが見つかりません。');
            redirect_admin(['panel' => 'news']);
        }

        nerdech_save_news($news);
        flash('success', 'ニュースを更新しました。');
        redirect_admin(['panel' => 'news']);
    }

    if ($action === 'delete_news') {
        $id = (string) ($_POST['id'] ?? '');
        $news = array_values(array_filter(nerdech_load_news(true), fn($item) => ($item['id'] ?? '') !== $id));
        nerdech_save_news($news);
        flash('success', 'ニュースを削除しました。');
        redirect_admin(['panel' => 'news']);
    }

    if ($action === 'toggle_news') {
        $id = (string) ($_POST['id'] ?? '');
        $news = nerdech_load_news(true);
        foreach ($news as &$item) {
            if (($item['id'] ?? '') === $id) {
                $item['published'] = empty($item['published']);
                break;
            }
        }
        unset($item);
        nerdech_save_news($news);
        flash('success', 'ニュースの公開状態を更新しました。');
        redirect_admin(['panel' => 'news']);
    }

    if ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        if (!verify_admin_password($current)) {
            flash('error', '現在のパスワードが正しくありません。');
            redirect_admin(['panel' => 'settings']);
        }
        if (strlen($new) < 8) {
            flash('error', '新しいパスワードは8文字以上にしてください。');
            redirect_admin(['panel' => 'settings']);
        }
        if ($new !== $confirm) {
            flash('error', '新しいパスワードが一致していません。');
            redirect_admin(['panel' => 'settings']);
        }

        save_admin_password($new);
        flash('success', 'パスワードを変更しました。');
        redirect_admin(['panel' => 'settings']);
    }

    if ($action === 'mark_contact_read') {
        $id = (string) ($_POST['id'] ?? '');
        $contacts = load_contacts();
        foreach ($contacts as &$contact) {
            if (($contact['id'] ?? '') === $id) {
                $contact['read'] = true;
            }
        }
        unset($contact);
        save_contacts($contacts);
        redirect_admin(['panel' => 'inquiries']);
    }

    if ($action === 'mark_all_contacts_read') {
        $contacts = load_contacts();
        foreach ($contacts as &$contact) {
            $contact['read'] = true;
        }
        unset($contact);
        save_contacts($contacts);
        redirect_admin(['panel' => 'inquiries']);
    }

    if ($action === 'delete_contact') {
        $id = (string) ($_POST['id'] ?? '');
        $contacts = array_values(array_filter(load_contacts(), fn($contact) => ($contact['id'] ?? '') !== $id));
        save_contacts($contacts);
        redirect_admin(['panel' => 'inquiries']);
    }
}

$flash = pull_flash();
$newsItems = $loggedIn ? nerdech_load_news(true) : [];
$contacts = $loggedIn ? array_reverse(load_contacts()) : [];
$totalContacts = count($contacts);
$unreadContacts = count(array_filter($contacts, fn($contact) => empty($contact['read'])));
$publishedNews = count(array_filter($newsItems, fn($item) => !empty($item['published'])));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>admin | nerdech</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { min-height: 100vh; font-family: 'DM Mono', monospace; background: #f4f5f7; color: #101114; font-size: 13px; }
button, input, textarea { font: inherit; }
.login-wrap { min-height: 100vh; display: grid; place-items: center; background: #0a0a0a; padding: 24px; }
.login-box { width: min(360px, 100%); padding: 44px 36px; background: #141414; border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; }
.login-logo { margin-bottom: 34px; font-size: 13px; letter-spacing: 0.1em; color: rgba(255,255,255,0.4); }
.login-logo span { color: #fff; }
.label { display: block; margin-bottom: 8px; font-size: 10px; letter-spacing: 0.15em; color: #717887; text-transform: uppercase; }
.login-box .label { color: rgba(255,255,255,0.35); }
.input, .textarea { width: 100%; border: 1px solid #d8dde6; border-radius: 6px; background: #fff; color: #111827; padding: 11px 13px; outline: none; }
.textarea { min-height: 110px; line-height: 1.7; resize: vertical; }
.login-box .input { background: rgba(255,255,255,0.06); border-color: rgba(255,255,255,0.12); color: #fff; margin-bottom: 18px; }
.input:focus, .textarea:focus { border-color: #111827; }
.login-error, .flash--error { color: #dc2626; }
.login-error { margin-bottom: 12px; font-size: 11px; letter-spacing: 0.05em; }
.btn { display: inline-flex; align-items: center; justify-content: center; min-height: 38px; padding: 0 16px; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #111827; text-decoration: none; cursor: pointer; transition: border-color 0.15s, background 0.15s, color 0.15s; }
.btn:hover { border-color: #111827; }
.btn--dark { width: 100%; background: #fff; color: #0a0a0a; border-color: #fff; }
.btn--primary { background: #111827; border-color: #111827; color: #fff; }
.btn--danger { color: #dc2626; border-color: #fecaca; }
.btn--danger:hover { background: #fef2f2; border-color: #ef4444; }
.btn--ghost { background: transparent; }
.admin-header { position: sticky; top: 0; z-index: 10; height: 60px; padding: 0 28px; background: #0a0a0a; color: #fff; display: flex; align-items: center; justify-content: space-between; }
.admin-header__logo { letter-spacing: 0.1em; }
.admin-header__nav { display: flex; align-items: center; gap: 18px; }
.admin-header__nav a { color: rgba(255,255,255,0.5); text-decoration: none; font-size: 11px; letter-spacing: 0.1em; }
.admin-header__nav a:hover { color: #fff; }
.admin-main { max-width: 1180px; margin: 0 auto; padding: 34px 24px 72px; }
.stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin-bottom: 24px; }
.stat, .panel { background: #fff; border: 1px solid #e2e7ef; border-radius: 8px; }
.stat { padding: 18px 20px; }
.stat__label { margin-bottom: 9px; color: #8b94a5; font-size: 10px; letter-spacing: 0.15em; text-transform: uppercase; }
.stat__value { font-size: 32px; font-weight: 500; line-height: 1; }
.grid { display: grid; grid-template-columns: minmax(0, 0.9fr) minmax(0, 1.1fr); gap: 18px; align-items: start; }
.panel { overflow: hidden; margin-bottom: 18px; }
.panel__head { padding: 16px 20px; border-bottom: 1px solid #e8edf4; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.panel__title { font-size: 11px; font-weight: 400; color: #8b94a5; letter-spacing: 0.15em; text-transform: uppercase; }
.panel__body { padding: 20px; }
.form-grid { display: grid; gap: 14px; }
.form-row { display: grid; gap: 8px; }
.form-split { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.check { display: flex; align-items: center; gap: 8px; color: #4b5563; font-size: 12px; }
.flash { margin-bottom: 18px; padding: 12px 14px; border-radius: 6px; background: #fff; border: 1px solid #e5e7eb; }
.flash--success { color: #047857; border-color: #a7f3d0; background: #ecfdf5; }
.list { display: grid; }
.list-item { display: grid; grid-template-columns: 112px minmax(0, 1fr) auto; gap: 16px; padding: 16px 20px; border-top: 1px solid #edf1f6; }
.list-item:first-child { border-top: none; }
.list-date { color: #7b8494; font-size: 11px; letter-spacing: 0.12em; padding-top: 4px; }
.list-title { font-weight: 500; line-height: 1.5; }
.list-body { margin-top: 6px; color: #4b5563; line-height: 1.7; }
.list-link { display: inline-block; margin-top: 7px; color: #2563eb; text-decoration: none; font-size: 11px; }
.edit-news { margin-top: 14px; }
.edit-news summary { width: fit-content; list-style: none; }
.edit-news summary::-webkit-details-marker { display: none; }
.edit-news__body { margin-top: 12px; padding: 14px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; }
.badge { display: inline-flex; align-items: center; height: 20px; padding: 0 8px; border-radius: 999px; font-size: 10px; letter-spacing: 0.08em; background: #eef2ff; color: #4338ca; }
.badge--muted { background: #f3f4f6; color: #6b7280; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; }
.actions form { display: inline; }
.empty { padding: 54px 20px; color: #9ca3af; text-align: center; letter-spacing: 0.05em; }
.inquiry-meta { color: #6b7280; font-size: 11px; line-height: 1.8; }
.inquiry-message { margin-top: 8px; color: #374151; line-height: 1.7; white-space: pre-wrap; }
@media (max-width: 820px) {
    .admin-header { height: auto; padding: 18px 20px; align-items: flex-start; gap: 14px; }
    .admin-header__nav { flex-wrap: wrap; justify-content: flex-end; }
    .admin-main { padding: 24px 16px 56px; }
    .stats, .grid, .form-split, .list-item { grid-template-columns: 1fr; }
    .list-item { gap: 10px; }
    .actions { justify-content: flex-start; }
}
</style>
</head>
<body>

<?php if (!$loggedIn): ?>
<div class="login-wrap">
    <div class="login-box">
        <p class="login-logo"><span>nerdech</span>&nbsp;/ admin</p>
        <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="login">
            <label class="label" for="password">password</label>
            <?php if ($loginError): ?>
            <p class="login-error">パスワードが正しくありません</p>
            <?php endif; ?>
            <input class="input" type="password" id="password" name="password" placeholder="password" autofocus required>
            <button class="btn btn--dark" type="submit">sign in →</button>
        </form>
    </div>
</div>
<?php else: ?>
<header class="admin-header">
    <div class="admin-header__logo">nerdech&nbsp;/&nbsp;admin</div>
    <nav class="admin-header__nav" aria-label="管理メニュー">
        <a href="#news">news</a>
        <a href="#settings">password</a>
        <a href="#inquiries">inquiries</a>
        <a href="<?= ADMIN_HOME ?>?logout=1">logout →</a>
    </nav>
</header>

<main class="admin-main">
    <?php if ($flash): ?>
    <p class="flash flash--<?= e((string) $flash['type']) ?>"><?= e((string) $flash['message']) ?></p>
    <?php endif; ?>

    <section class="stats" aria-label="概要">
        <div class="stat">
            <p class="stat__label">news</p>
            <p class="stat__value"><?= count($newsItems) ?></p>
        </div>
        <div class="stat">
            <p class="stat__label">published</p>
            <p class="stat__value"><?= $publishedNews ?></p>
        </div>
        <div class="stat">
            <p class="stat__label">inquiries</p>
            <p class="stat__value"><?= $totalContacts ?></p>
        </div>
        <div class="stat">
            <p class="stat__label">unread</p>
            <p class="stat__value"><?= $unreadContacts ?></p>
        </div>
    </section>

    <div class="grid">
        <section id="news" class="panel">
            <div class="panel__head">
                <h1 class="panel__title">add news</h1>
            </div>
            <div class="panel__body">
                <form class="form-grid" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="add_news">
                    <div class="form-row">
                        <label class="label" for="date">date</label>
                        <input class="input" id="date" name="date" type="text" placeholder="2026-05-07 or 2026-05" required>
                    </div>
                    <div class="form-row">
                        <label class="label" for="title">title</label>
                        <input class="input" id="title" name="title" type="text" required>
                    </div>
                    <div class="form-row">
                        <label class="label" for="body">body</label>
                        <textarea class="textarea" id="body" name="body" required></textarea>
                    </div>
                    <div class="form-split">
                        <div class="form-row">
                            <label class="label" for="link_label">link label</label>
                            <input class="input" id="link_label" name="link_label" type="text" placeholder="official result →">
                        </div>
                        <div class="form-row">
                            <label class="label" for="link_url">link url</label>
                            <input class="input" id="link_url" name="link_url" type="url" placeholder="https://...">
                        </div>
                    </div>
                    <label class="check">
                        <input type="checkbox" name="published" value="1" checked>
                        公開する
                    </label>
                    <button class="btn btn--primary" type="submit">add news →</button>
                </form>
            </div>
        </section>

        <section id="settings" class="panel">
            <div class="panel__head">
                <h2 class="panel__title">change password</h2>
            </div>
            <div class="panel__body">
                <form class="form-grid" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-row">
                        <label class="label" for="current_password">current password</label>
                        <input class="input" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                    </div>
                    <div class="form-row">
                        <label class="label" for="new_password">new password</label>
                        <input class="input" id="new_password" name="new_password" type="password" autocomplete="new-password" required>
                    </div>
                    <div class="form-row">
                        <label class="label" for="new_password_confirm">confirm</label>
                        <input class="input" id="new_password_confirm" name="new_password_confirm" type="password" autocomplete="new-password" required>
                    </div>
                    <button class="btn btn--primary" type="submit">update password →</button>
                </form>
            </div>
        </section>
    </div>

    <section class="panel">
        <div class="panel__head">
            <h2 class="panel__title">news list</h2>
            <span class="badge"><?= $publishedNews ?> published</span>
        </div>
        <?php if (!$newsItems): ?>
        <p class="empty">ニュースはまだありません</p>
        <?php else: ?>
        <div class="list">
            <?php foreach ($newsItems as $item): ?>
            <article class="list-item">
                <div class="list-date"><?= e((string) ($item['display_date'] ?? $item['date'] ?? '')) ?></div>
                <div>
                    <p class="list-title"><?= e((string) ($item['title'] ?? '')) ?></p>
                    <p class="list-body"><?= e((string) ($item['body'] ?? '')) ?></p>
                    <?php if (!empty($item['link_url']) && !empty($item['link_label'])): ?>
                    <a class="list-link" href="<?= e((string) $item['link_url']) ?>" target="_blank" rel="noopener"><?= e((string) $item['link_label']) ?></a>
                    <?php endif; ?>
                    <details class="edit-news">
                        <summary class="btn btn--ghost">edit</summary>
                        <div class="edit-news__body">
                            <form class="form-grid" method="post">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="update_news">
                                <input type="hidden" name="id" value="<?= e((string) ($item['id'] ?? '')) ?>">
                                <div class="form-row">
                                    <label class="label" for="edit-date-<?= e((string) ($item['id'] ?? '')) ?>">date</label>
                                    <input class="input" id="edit-date-<?= e((string) ($item['id'] ?? '')) ?>" name="date" type="text" value="<?= e((string) ($item['date'] ?? '')) ?>" required>
                                </div>
                                <div class="form-row">
                                    <label class="label" for="edit-title-<?= e((string) ($item['id'] ?? '')) ?>">title</label>
                                    <input class="input" id="edit-title-<?= e((string) ($item['id'] ?? '')) ?>" name="title" type="text" value="<?= e((string) ($item['title'] ?? '')) ?>" required>
                                </div>
                                <div class="form-row">
                                    <label class="label" for="edit-body-<?= e((string) ($item['id'] ?? '')) ?>">body</label>
                                    <textarea class="textarea" id="edit-body-<?= e((string) ($item['id'] ?? '')) ?>" name="body" required><?= e((string) ($item['body'] ?? '')) ?></textarea>
                                </div>
                                <div class="form-split">
                                    <div class="form-row">
                                        <label class="label" for="edit-link-label-<?= e((string) ($item['id'] ?? '')) ?>">link label</label>
                                        <input class="input" id="edit-link-label-<?= e((string) ($item['id'] ?? '')) ?>" name="link_label" type="text" value="<?= e((string) ($item['link_label'] ?? '')) ?>">
                                    </div>
                                    <div class="form-row">
                                        <label class="label" for="edit-link-url-<?= e((string) ($item['id'] ?? '')) ?>">link url</label>
                                        <input class="input" id="edit-link-url-<?= e((string) ($item['id'] ?? '')) ?>" name="link_url" type="url" value="<?= e((string) ($item['link_url'] ?? '')) ?>">
                                    </div>
                                </div>
                                <label class="check">
                                    <input type="checkbox" name="published" value="1" <?= !empty($item['published']) ? 'checked' : '' ?>>
                                    公開する
                                </label>
                                <button class="btn btn--primary" type="submit">save changes →</button>
                            </form>
                        </div>
                    </details>
                </div>
                <div class="actions">
                    <span class="badge<?= !empty($item['published']) ? '' : ' badge--muted' ?>"><?= !empty($item['published']) ? 'published' : 'draft' ?></span>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="toggle_news">
                        <input type="hidden" name="id" value="<?= e((string) ($item['id'] ?? '')) ?>">
                        <button class="btn btn--ghost" type="submit"><?= !empty($item['published']) ? 'hide' : 'publish' ?></button>
                    </form>
                    <form method="post" onsubmit="return confirm('このニュースを削除しますか？')">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_news">
                        <input type="hidden" name="id" value="<?= e((string) ($item['id'] ?? '')) ?>">
                        <button class="btn btn--danger" type="submit">delete</button>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <section id="inquiries" class="panel">
        <div class="panel__head">
            <h2 class="panel__title">inquiries</h2>
            <?php if ($unreadContacts > 0): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="mark_all_contacts_read">
                <button class="btn btn--ghost" type="submit">mark all read</button>
            </form>
            <?php endif; ?>
        </div>
        <?php if (!$contacts): ?>
        <p class="empty">お問い合わせはまだありません</p>
        <?php else: ?>
        <div class="list">
            <?php foreach ($contacts as $contact): ?>
            <article class="list-item">
                <div class="list-date"><?= e((string) ($contact['date'] ?? '')) ?></div>
                <div>
                    <p class="list-title"><?= e((string) ($contact['name'] ?? '')) ?></p>
                    <p class="inquiry-meta">
                        <a href="mailto:<?= e((string) ($contact['email'] ?? '')) ?>"><?= e((string) ($contact['email'] ?? '')) ?></a>
                    </p>
                    <p class="inquiry-message"><?= e((string) ($contact['message'] ?? '')) ?></p>
                </div>
                <div class="actions">
                    <span class="badge<?= empty($contact['read']) ? '' : ' badge--muted' ?>"><?= empty($contact['read']) ? 'unread' : 'read' ?></span>
                    <?php if (empty($contact['read'])): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="mark_contact_read">
                        <input type="hidden" name="id" value="<?= e((string) ($contact['id'] ?? '')) ?>">
                        <button class="btn btn--ghost" type="submit">read</button>
                    </form>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('このお問い合わせを削除しますか？')">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="delete_contact">
                        <input type="hidden" name="id" value="<?= e((string) ($contact['id'] ?? '')) ?>">
                        <button class="btn btn--danger" type="submit">delete</button>
                    </form>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>
</main>
<?php endif; ?>
</body>
</html>
