<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

$baseDir = __DIR__ . '/uploads/';
if (!file_exists($baseDir)) mkdir($baseDir, 0777, true);

function clean_token($t) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $t ?? '');
}
function token_dir($token) {
    global $baseDir;
    return $baseDir . $token . '/';
}
function token_meta($token) {
    return token_dir($token) . 'files.json';
}
function hash_pw($pw) { return hash('sha256', $pw); }
function load_meta($token) {
    $f = token_meta($token);
    return file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
}
function save_meta($token, $meta) {
    file_put_contents(token_meta($token), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
function check_pw($token, $pw) {
    $meta = load_meta($token);
    if (empty($meta['password'])) return true;
    return hash_pw($pw) === $meta['password'];
}
function token_expired($token) {
    $meta = load_meta($token);
    if (!empty($meta['expiry']) && $meta['expiry'] !== 'never') {
        $exp = strtotime($meta['expiry']);
        if ($exp && time() > $exp) return true;
    }
    return false;
}
function delete_token_dir_if_empty($token) {
    $dir = token_dir($token);
    if (is_dir($dir) && !glob($dir . '*')) rmdir($dir);
}

$method = $_SERVER['REQUEST_METHOD'];
$error = '';

if ($method === 'POST') {
    $token = clean_token($_POST['token'] ?? '');
    if (!$token) $token = bin2hex(random_bytes(4));
    $dir = token_dir($token);
    if (!file_exists($dir)) mkdir($dir, 0777, true);
    $note = trim($_POST['note'] ?? '');
    if ($note === '') {
        $error = 'Note empty';
    } else {
        file_put_contents($dir . 'note.txt', $note);
        $meta = load_meta($token);
        if (!empty($_POST['password'])) $meta['password'] = hash_pw($_POST['password']);
        if (isset($_POST['expiry'])) {
            $exp = null;
            switch ($_POST['expiry']) {
                case '1h': $exp = time() + 3600; break;
                case '12h': $exp = time() + 43200; break;
                case '1d': $exp = time() + 86400; break;
                case '3d': $exp = time() + 259200; break;
                case 'never': $exp = null; break;
            }
            $meta['expiry'] = $exp ? date('Y-m-d H:i', $exp) : 'never';
        }
        save_meta($token, $meta);
        $pwSeg = empty($_POST['password']) ? '' : '/' . rawurlencode($_POST['password']);
        $link = '/note/' . rawurlencode($token) . $pwSeg;
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Note Created</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        </head>
        <body>
        <div class="container" style="max-width:600px;margin-top:2em;">
            <div class="alert alert-success">Note created!</div>
            <div class="mb-3"><input type="text" class="form-control" value="<?= htmlspecialchars($link) ?>" readonly></div>
            <div class="text-center"><a href="/note" class="btn btn-secondary">New note</a></div>
        </div>
        </body>
        </html>
        <?php
        exit;
    }
}

$token = clean_token($_GET['token'] ?? '');
$password = $_GET['password'] ?? '';
$noteFile = $token ? token_dir($token) . 'note.txt' : '';

if ($token) {
    if (!file_exists($noteFile) || token_expired($token) || !check_pw($token, $password)) {
        $msg = 'Note not found or wrong password.';
        if (token_expired($token)) $msg = 'Note expired.';
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Error</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        </head>
        <body>
        <div class="container" style="max-width:600px;margin-top:2em;">
            <div class="alert alert-danger text-center"><?= htmlspecialchars($msg) ?></div>
        </div>
        </body>
        </html>
        <?php
        exit;
    }
    $note = file_get_contents($noteFile);
    unlink($noteFile);
    if (file_exists(token_meta($token))) unlink(token_meta($token));
    delete_token_dir_if_empty($token);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>View Note</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body>
    <div class="container" style="max-width:600px;margin-top:2em;">
        <h2 class="mb-4 text-primary text-center">Your Note</h2>
        <pre class="form-control" readonly style="white-space:pre-wrap;"><?= htmlspecialchars($note) ?></pre>
        <div class="alert alert-warning mt-3 text-center">This note has been destroyed.</div>
    </div>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Note</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container my-5" style="max-width:600px;">
    <h2 class="mb-4 text-primary text-center">Create Self-Destructing Note</h2>
    <?php if ($error): ?>
    <div class="alert alert-danger text-center"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <div class="mb-3">
            <textarea name="note" class="form-control" rows="6" required></textarea>
        </div>
        <div class="mb-3 d-flex justify-content-center flex-wrap gap-3">
            <div class="d-flex align-items-center">
                <label for="token" class="form-label mb-0 me-2">Token</label>
                <input type="text" class="form-control form-control-sm" id="token" name="token" placeholder="optional" style="width:150px;">
            </div>
            <div class="d-flex align-items-center">
                <label for="password" class="form-label mb-0 me-2">Password</label>
                <input type="password" class="form-control form-control-sm" id="password" name="password" placeholder="optional" style="width:150px;">
            </div>
            <div class="d-flex align-items-center">
                <label for="expiry" class="form-label mb-0 me-2">Expires</label>
                <select class="form-select form-select-sm" id="expiry" name="expiry" style="width:100px;">
                    <option value="3d">3 days</option>
                    <option value="1d">1 day</option>
                    <option value="12h">12 hours</option>
                    <option value="1h">1 hour</option>
                    <option value="never">Never</option>
                </select>
            </div>
        </div>
        <div class="text-center">
            <button type="submit" class="btn btn-primary">Create Note</button>
        </div>
    </form>
</div>
</body>
</html>
