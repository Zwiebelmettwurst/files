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
        if (!empty($_POST['encrypted'])) $meta['encrypted'] = true;
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
            <div class="mb-3"><input type="text" class="form-control" id="noteLink" value="<?= htmlspecialchars($link) ?>" readonly></div>
            <script>
            const k = sessionStorage.getItem('noteKey');
            if (k) {
                const inp = document.getElementById('noteLink');
                if (inp) inp.value = inp.value + '#' + k;
                sessionStorage.removeItem('noteKey');
            }
            </script>
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
    if (!isset($_GET['view'])) {
        $base = '/note/' . rawurlencode($token);
        if ($password !== '') $base .= '/' . rawurlencode($password);
        $viewLink = $base . '?view=1';
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
            <div class="alert alert-info text-center">This note will self-destruct after you view it.</div>
            <div class="text-center mt-4">
                <a href="<?= htmlspecialchars($viewLink) ?>" id="viewBtn" class="btn btn-primary">Read and Destroy Note</a>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
            var l = document.getElementById('viewBtn');
            if(l) l.href += location.hash;
        });
        </script>
        </body>
        </html>
        <?php
        exit;
    }
    $meta = load_meta($token);
    $isEnc = !empty($meta['encrypted']);
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
        <pre id="note" class="form-control" readonly style="white-space:pre-wrap;"></pre>
        <script>
        const enc = <?= $isEnc ? 'true' : 'false' ?>;
        const data = <?= json_encode($note) ?>;
        const el = document.getElementById("note");
        if (!enc) {
            el.textContent = data;
        } else {
            const key = location.hash.slice(1);
            if (!key) {
                el.textContent = "Missing decryption key.";
            } else {
                try {
                    const keyBytes = Uint8Array.from(atob(key), c => c.charCodeAt(0));
                    crypto.subtle.importKey("raw", keyBytes, "AES-GCM", false, ["decrypt"]).then(k => {
                        const buf = Uint8Array.from(atob(data), c => c.charCodeAt(0));
                        const iv = buf.slice(0, 12);
                        const ct = buf.slice(12);
                        return crypto.subtle.decrypt({name: "AES-GCM", iv}, k, ct);
                    }).then(p => {
                        el.textContent = new TextDecoder().decode(p);
                    }).catch(() => {
                        el.textContent = "Decryption failed.";
                    });
                } catch (e) {
                    el.textContent = "Invalid key.";
                }
            }
        }
        </script>
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
        <input type="hidden" name="encrypted" value="0" id="encFlag">
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
    <script>
    document.addEventListener("DOMContentLoaded", function(){
        const form = document.querySelector("form");
        const textarea = form.querySelector("textarea[name=note]");
        const flag = document.getElementById("encFlag");
        let submitting = false;
        form.addEventListener("submit", async function(e){
            if(submitting) return;
            e.preventDefault();
            const text = textarea.value;
            const keyBytes = new Uint8Array(32);
            crypto.getRandomValues(keyBytes);
            const keyStr = btoa(String.fromCharCode(...keyBytes));
            sessionStorage.setItem("noteKey", keyStr);
            const key = await crypto.subtle.importKey("raw", keyBytes, "AES-GCM", false, ["encrypt"]);
            const iv = crypto.getRandomValues(new Uint8Array(12));
            const enc = await crypto.subtle.encrypt({name:"AES-GCM", iv}, key, new TextEncoder().encode(text));
            const out = new Uint8Array(iv.byteLength + enc.byteLength);
            out.set(iv,0);
            out.set(new Uint8Array(enc), iv.byteLength);
            textarea.value = btoa(String.fromCharCode(...out));
            flag.value = "1";
            submitting = true;
            form.submit();
        });
    });
    </script>
</div>
</body>
</html>
