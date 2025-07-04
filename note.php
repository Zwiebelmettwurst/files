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
function load_meta($token) {
    $f = token_meta($token);
    return file_exists($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
}
function save_meta($token, $meta) {
    file_put_contents(token_meta($token), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
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

function theme_init_script() {
    return "<script>(function(){const stored=localStorage.getItem('theme');const prefersDark=window.matchMedia('(prefers-color-scheme: dark)').matches;const theme=stored||(prefersDark?'dark':'light');document.documentElement.setAttribute('data-bs-theme',theme);})();</script>";
}

function theme_toggle_script() {
    return "<script>const btn=document.getElementById('themeToggle');function applyTheme(t){document.documentElement.setAttribute('data-bs-theme',t);btn.innerHTML=t==='dark'?'<i class=\"bi bi-sun-fill\"></i>':'<i class=\"bi bi-moon-fill\"></i>';}document.addEventListener('DOMContentLoaded',function(){applyTheme(document.documentElement.getAttribute('data-bs-theme'));btn.addEventListener('click',function(){const c=document.documentElement.getAttribute('data-bs-theme');const n=c==='dark'?'light':'dark';localStorage.setItem('theme',n);applyTheme(n);});});</script>";
}


function page_header($title, $icons = true) {
    ?>
    <!DOCTYPE html>
    <html lang="en" data-bs-theme="light">
    <head>
        <meta charset="UTF-8">
        <title><?= htmlspecialchars($title) ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="icon" type="image/svg+xml" href="/favicon.svg">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <?php if ($icons): ?>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
        <?php endif; ?>
        <?= theme_init_script() ?>
    </head>
    <body>
    <button id="themeToggle" type="button" class="btn btn-outline-secondary btn-sm position-fixed top-0 end-0 m-3" title="Toggle dark mode"><i class="bi bi-moon-fill"></i></button>
    <?php
}

function page_footer() {
    ?>
    <?= theme_toggle_script() ?>
    </body>
    </html>
    <?php
}

$method = $_SERVER['REQUEST_METHOD'];
$error = '';

if ($method === 'POST' && isset($_GET['delete'])) {
    $token = clean_token($_GET['token'] ?? '');
    $h = $_POST['h'] ?? '';
    $noteFile = $token ? token_dir($token) . 'note.txt' : '';
    $meta = load_meta($token);
    $ok = $token && file_exists($noteFile) && !token_expired($token);
    if ($ok && !empty($meta['encrypted'])) {
        $ok = $h && !empty($meta['key_hash']) && hash_equals($meta['key_hash'], $h);
    }
    header('Content-Type: application/json');
    if ($ok) {
        unlink($noteFile);
        if (file_exists(token_meta($token))) unlink(token_meta($token));
        delete_token_dir_if_empty($token);
        echo json_encode(['status' => 'deleted']);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error']);
    }
    exit;
}

if ($method === 'POST') {
    $token = bin2hex(random_bytes(4));
    $dir = token_dir($token);
    if (!file_exists($dir)) mkdir($dir, 0777, true);
    $note = trim($_POST['note'] ?? '');
    if ($note === '') {
        $error = 'Note empty';
    } else {
        file_put_contents($dir . 'note.txt', $note);
        $meta = load_meta($token);
        if (!empty($_POST['encrypted'])) {
            $meta['encrypted'] = true;
            if (!empty($_POST['key_hash'])) {
                $meta['key_hash'] = $_POST['key_hash'];
            }
        }
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
        $link = '/note/' . rawurlencode($token);
        ?>
        <?php page_header('Note Created'); ?>
        <div class="container" style="max-width:600px;margin-top:2em;">
            <div class="alert alert-success text-center">Note created!</div>
            <div class="input-group mb-3">
                <input type="text" class="form-control" id="noteLink" value="<?= htmlspecialchars($link) ?>" readonly>
                <button class="btn btn-outline-secondary copy-btn" type="button" data-clipboard-target="noteLink" title="Copy link with decryption key"><i class="bi bi-clipboard-plus"></i></button>
                <button class="btn btn-outline-secondary copy-btn" type="button" data-clipboard-target="noteLink" data-nokey="1" title="Copy link without decryption key"><i class="bi bi-clipboard-minus"></i></button>
            </div>
            <div id="copyStatus-noteLink" class="small text-success mb-2 text-center" style="display:none;">Link copied!</div>
            <script>
            document.addEventListener('DOMContentLoaded', function(){
                const inp = document.getElementById('noteLink');
                const k = sessionStorage.getItem('noteKey');
                if(inp){
                    const base = window.location.origin + inp.value;
                    inp.setAttribute('data-base-link', base);
                    let full = base;
                    if(k){ full += '#' + k; sessionStorage.removeItem('noteKey'); }
                    inp.value = full;
                    inp.setAttribute('data-full-link', full);
                }
                document.querySelectorAll('.copy-btn').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        const targetId = btn.getAttribute('data-clipboard-target');
                        const input = document.getElementById(targetId);
                        if(input){
                            const link = btn.dataset.nokey === '1'
                                ? (input.getAttribute('data-base-link') || input.value)
                                : (input.getAttribute('data-full-link') || input.value);
                            navigator.clipboard.writeText(link).then(function(){
                                const status = document.getElementById('copyStatus-' + targetId);
                                if(status){
                                    status.style.display = '';
                                    setTimeout(()=>{ status.style.display = 'none'; },1500);
                                }
                            });
                        }
                    });
                });
            });
            </script>
            <div class="text-center mt-3"><a href="/note" class="btn btn-secondary">New note</a></div>
        </div>
        <?php 
         page_footer();
         exit; 
    }
}

$token = clean_token($_GET['token'] ?? '');
$noteFile = $token ? token_dir($token) . 'note.txt' : '';

if ($token) {
    if (!file_exists($noteFile) || token_expired($token)) {
        $msg = 'Note not found.';
        if (token_expired($token)) $msg = 'Note expired.';
        ?>
        <?php page_header('Error'); ?>
        <div class="container" style="max-width:600px;margin-top:2em;">
            <div class="alert alert-danger text-center"><?= htmlspecialchars($msg) ?></div>
            <div class="text-center mt-3"><a href="/note" class="btn btn-secondary">New note</a></div>

        </div>
        <?php page_footer();
        exit; 

    }
    $meta = load_meta($token);
    $isEnc = !empty($meta['encrypted']);
    if (!isset($_GET['view'])) {
        $viewAction = '/note/' . rawurlencode($token);
        ?>
        <?php page_header('View Note'); ?>
        <div class="container" style="max-width:600px;margin-top:2em;">
            <div class="alert alert-info text-center">This note will self-destruct after you view it.</div>
            <form id="viewForm" method="get" action="<?= htmlspecialchars($viewAction) ?>" class="mt-4 text-center">
                <input type="hidden" name="view" value="1">
                <?php if ($isEnc): ?>
                <input type="hidden" name="h" id="formHash">
                <div class="mb-3">
                    <input type="text" id="formKey" class="form-control" placeholder="Decryption key" required>
                </div>
                <div id="errBox" class="alert alert-danger text-center" style="display:none;"></div>
                <?php endif; ?>
                <button type="submit" id="viewBtn" class="btn btn-primary">Read and Destroy Note</button>
            </form>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
<?php if ($isEnc): ?>
            const f = document.getElementById('viewForm');
            const errBox = document.getElementById('errBox');
            const initialErr = errBox && errBox.textContent.trim();

            function showError(msg){
                if(errBox){
                    errBox.textContent = msg;
                    errBox.style.display = '';
                } else {
                    alert(msg);
                }
            }

            async function submitWithKey(key){
                let bytes;
                try {
                    bytes = Uint8Array.from(atob(key), c => c.charCodeAt(0));
                } catch(e){
                    showError('Invalid key.');
                    return;
                }
                if(bytes.length < 2){
                    showError('Invalid key.');
                    return;
                }
                const digest = await crypto.subtle.digest('SHA-256', bytes);
                const hashStr = btoa(String.fromCharCode(...new Uint8Array(digest)));
                document.getElementById('formHash').value = hashStr;
                f.action = f.action.split('#')[0] + '#' + encodeURIComponent(key);
                f.submit();
            }

            const hashKeyRaw = location.hash.slice(1);
            if(hashKeyRaw && !initialErr){
                const hashKey = decodeURIComponent(hashKeyRaw);
                f.style.display = 'none';
                submitWithKey(hashKey);
                return;
            }
            if(initialErr){
                if(location.hash){
                    history.replaceState(null,'',location.href.split('#')[0]);
                }
                if(hashKeyRaw){
                    const inp = document.getElementById('formKey');
                    if(inp) inp.value = decodeURIComponent(hashKeyRaw);
                }
                errBox.style.display = '';
            }

            f.addEventListener('submit', async function(e){
                e.preventDefault();
                const key = document.getElementById('formKey').value.trim();
                if(!key){ showError('Enter decryption key'); return; }
                await submitWithKey(key);
            });
<?php else: ?>
            // append any hash to button link
<?php endif; ?>
        });
        </script>
        <?php page_footer(); 
      exit; 
    }
    $h = $_GET['h'] ?? '';
    if ($isEnc) {
        if (!$h) {
            header('Location: /note/' . rawurlencode($token));
            exit;
        }
        if (empty($meta['key_hash']) || !hash_equals($meta['key_hash'], $h)) {
            $viewAction = '/note/' . rawurlencode($token);
            $msg = 'Wrong decryption key.';
            ?>
            <?php page_header('View Note'); ?>
            <div class="container" style="max-width:600px;margin-top:2em;">
                <div id="errBox" class="alert alert-danger text-center">
                    <?= htmlspecialchars($msg) ?>
                </div>
                <form id="viewForm" method="get" action="<?= htmlspecialchars($viewAction) ?>" class="mt-4 text-center">
                    <input type="hidden" name="view" value="1">
                    <input type="hidden" name="h" id="formHash">
                    <div class="mb-3">
                        <input type="text" id="formKey" class="form-control" placeholder="Decryption key" required>
                    </div>
                    <button type="submit" id="viewBtn" class="btn btn-primary">Read and Destroy Note</button>
                </form>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', function(){
                const f = document.getElementById('viewForm');
                const errBox = document.getElementById('errBox');
                const initialErr = errBox && errBox.textContent.trim();

                function showError(msg){
                    if(errBox){
                        errBox.textContent = msg;
                        errBox.style.display = '';
                    } else {
                        alert(msg);
                    }
                }

                async function submitWithKey(key){
                    let bytes;
                    try {
                        bytes = Uint8Array.from(atob(key), c => c.charCodeAt(0));
                    } catch(e){
                        showError('Invalid key.');
                        return;
                    }
                    if(bytes.length < 2){
                        showError('Invalid key.');
                        return;
                    }
                    const digest = await crypto.subtle.digest('SHA-256', bytes);
                    const hashStr = btoa(String.fromCharCode(...new Uint8Array(digest)));
                    document.getElementById('formHash').value = hashStr;
                    f.action = f.action.split('#')[0] + '#' + encodeURIComponent(key);
                    f.submit();
                }

                const hashKeyRaw = location.hash.slice(1);
                if(hashKeyRaw && !initialErr){
                    const hashKey = decodeURIComponent(hashKeyRaw);
                    f.style.display = 'none';
                    submitWithKey(hashKey);
                    return;
                }
                if(initialErr){
                    if(location.hash){
                        history.replaceState(null,'',location.href.split('#')[0]);
                    }
                    if(hashKeyRaw){
                        const inp = document.getElementById('formKey');
                        if(inp) inp.value = decodeURIComponent(hashKeyRaw);
                    }
                    errBox.style.display = '';
                }

                f.addEventListener('submit', async function(e){
                    e.preventDefault();
                    const key = document.getElementById('formKey').value.trim();
                    if(!key){ showError('Enter decryption key'); return; }
                    await submitWithKey(key);
                });
            });
            </script>
            <?php page_footer(); 
            exit;
        }
    }

    $note = file_get_contents($noteFile);
    if ($note === false) {
        $note = '';
    }
    ?>
    <?php page_header('View Note'); ?>
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
            const raw = location.hash.slice(1);
            const key = raw ? decodeURIComponent(raw) : '';
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
                    }).then(async p => {
                        el.textContent = new TextDecoder().decode(p);
                        try {
                            await fetch('?delete=1', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'h=' + encodeURIComponent(<?= json_encode($h) ?>)});
                            const msg = document.getElementById('destroyMsg');
                            if(msg) msg.style.display = '';
                        } catch(e) {}
                    }).catch(() => {
                        el.textContent = "Decryption failed.";
                    });
                } catch (e) {
                    el.textContent = "Invalid key.";
                }
            }
        }
        </script>
        <div id="destroyMsg" class="alert alert-warning mt-3 text-center" style="display:none;">This note has been destroyed.</div>
        <div class="text-center mt-3"><a href="/note" class="btn btn-secondary">New note</a></div>
    </div>
    <?php page_footer();  
    exit; 
}
page_header('Create Note'); ?>
<div class="container my-5" style="max-width:600px;">
    <h2 class="mb-1 text-primary text-center">Create Self-Destructing Note</h2>
    <h6 class="mb-4 text-center primary-text">or upload <a href="/">self-destructing files</a></h6>
    <?php if ($error): ?>
    <div class="alert alert-danger text-center"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post">
        <div class="mb-3">
            <textarea name="note" class="form-control" rows="6" required></textarea>
        <input type="hidden" name="encrypted" value="0" id="encFlag">
        <input type="hidden" name="key_hash" id="keyHash">
        </div>
        <div class="mb-3 d-flex justify-content-center flex-wrap gap-3">
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
        const hashField = document.getElementById("keyHash");
        let submitting = false;
        form.addEventListener("submit", async function(e){
            if(submitting) return;
            e.preventDefault();
            const text = textarea.value;
            const keyBytes = new Uint8Array(32);
            crypto.getRandomValues(keyBytes);
            const keyStr = btoa(String.fromCharCode(...keyBytes));
            sessionStorage.setItem("noteKey", keyStr);
            const digest = await crypto.subtle.digest("SHA-256", keyBytes);
            const hashStr = btoa(String.fromCharCode(...new Uint8Array(digest)));
            if(hashField) hashField.value = hashStr;
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
    <?php page_footer(); ?>
