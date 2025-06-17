<?php
$uploadDir = __DIR__ . '/uploads/';
$now = time();
$maxAge = 3 * 24 * 60 * 60;

// Hilfsfunktionen
function getTokenFromUrl() {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('#/download/([a-zA-Z0-9_-]+)/zip#', $requestUri, $m))
        return [$m[1], 'zip', null];
    if (preg_match('#/download/([a-zA-Z0-9_-]+)/file/(.+)$#', $requestUri, $m))
        return [$m[1], null, $m[2]];
    if (preg_match('#/download/([a-zA-Z0-9_-]+)#', $requestUri, $m))
        return [$m[1], null, null];
    if (isset($_GET['token']))
        return [preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['token']), null, null];
    return [null, null, null];
}
function hashPassword($pw) { return hash('sha256', $pw); }
function log_action($tokenDir, $msg) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '-';
    $t = date('Y-m-d H:i:s');
    file_put_contents($tokenDir . 'download.log', "[$t][$ip][$ua] $msg\n", FILE_APPEND);
}

// Cleanup: lösche alte Token-Ordner
foreach (glob($uploadDir . '*') as $entry) {
    if (is_dir($entry) && preg_match('/^[a-zA-Z0-9_\-]+$/', basename($entry))) {
        if ($now - filemtime($entry) > $maxAge) {
            array_map('unlink', glob($entry . '/*'));
            array_map('unlink', glob($entry . '/chunks_*/*'));
            array_map('rmdir', glob($entry . '/chunks_*'));
            rmdir($entry);
        }
    }
}

// Token/Dateien/Meta laden
list($token, $isZip, $downloadFile) = getTokenFromUrl();
$tokenDir = $token ? $uploadDir . $token . '/' : '';
$metaFile = $tokenDir . 'files.meta';
$mapfile = $tokenDir . 'files.map';
$files = [];
$meta = [];
if ($token && file_exists($mapfile)) $files = file($mapfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($token && file_exists($metaFile)) $meta = json_decode(file_get_contents($metaFile), true) ?: [];

function needsPassword() { global $meta; return !empty($meta['password']); }
function getPassword() {
    if (!empty($_SERVER['HTTP_X_PASSWORD'])) return $_SERVER['HTTP_X_PASSWORD'];
    if (!empty($_POST['password'])) return $_POST['password'];
    if (!empty($_POST['dlpw'])) return $_POST['dlpw'];
    if (!empty($_GET['password'])) return $_GET['password'];
    return '';
}
function checkPassword($pw) { global $meta; return isset($meta['password']) && hashPassword($pw) === $meta['password']; }

// Ablaufdatum-Check
if (!empty($meta['expiry']) && $meta['expiry'] !== 'never') {
    $expiryUnix = strtotime($meta['expiry']);
    if ($expiryUnix && $now > $expiryUnix) {
        foreach ($files as $f) @unlink($tokenDir . $f);
        @unlink($mapfile); @unlink($metaFile);
        die('<div style="margin:2em auto;text-align:center;max-width:300px;" class="alert alert-danger">Token expired.</div>');
    }
}

// Einzeldatei- oder ZIP-Download
if (($downloadFile || $isZip) && $token) {
    $pwOk = true;
    if (needsPassword()) {
        $pw = getPassword();
        $pwOk = $pw && checkPassword($pw);
    }
    if (!$pwOk) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $pw = $_POST['dlpw'] ?? '';
            $params = '?password=' . urlencode($pw);
            if ($downloadFile) {
                header("Location: /download/" . urlencode($token) . "/file/" . rawurlencode($downloadFile) . $params);
            } else if ($isZip) {
                header("Location: /download/" . urlencode($token) . "/zip" . $params);
            }
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        http_response_code(401);
        ?>
        <!DOCTYPE html><html lang="en"><head>
            <meta charset="utf-8"><title>Download - Password</title>
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        </head><body>
        <div class="container" style="max-width:440px;margin-top:4rem">
            <form method="post">
                <input type="hidden" name="token" value="<?=htmlspecialchars($token)?>">
                <?php if ($downloadFile): ?><input type="hidden" name="file" value="<?=htmlspecialchars($downloadFile)?>"><?php endif; ?>
                <div class="alert alert-warning text-center">This file/folder is password-protected.</div>
                <div class="mb-3"><input type="password" class="form-control" name="dlpw" placeholder="Password" autofocus></div>
                <div class="d-grid gap-2 col-12 mx-auto"><button class="btn btn-primary" type="submit">Unlock</button></div>
            </form>
            <div class="mt-5 text-center small text-muted"><a href="/?token=<?= htmlspecialchars($token) ?>" class="link-secondary">&larr; Back to upload</a></div>
        </div>
        </body></html>
        <?php exit;
    }

    // Einzeldatei
    if ($downloadFile && in_array($downloadFile, $files)) {
        $dlFile = basename($downloadFile);
        $dlPath = $tokenDir . $dlFile;
        if (file_exists($dlPath)) {
            log_action($tokenDir, "Download $dlFile");
            ignore_user_abort(true); set_time_limit(0);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $dlFile . '"');
            header('Content-Length: ' . filesize($dlPath));
            $fp = fopen($dlPath, 'rb');
            while (!feof($fp)) { echo fread($fp, 8192); flush(); if (connection_aborted()) { fclose($fp); exit; } }
            fclose($fp);
            unlink($dlPath);
            $newFiles = array_filter($files, function ($f) use ($downloadFile) { return $f !== $downloadFile; });
            if (!empty($newFiles)) file_put_contents($mapfile, implode("\n", $newFiles));
            else { if (file_exists($mapfile)) unlink($mapfile); if (file_exists($metaFile)) unlink($metaFile); }
            // Chunks löschen (nur zur Sicherheit)
            $tmpDir = $tokenDir . 'chunks_' . md5($dlFile) . '/';
            if (is_dir($tmpDir)) { array_map('unlink', glob($tmpDir . "/*")); rmdir($tmpDir); }
            log_action($tokenDir, "Deleted $dlFile");
            exit;
        } else { http_response_code(404); die("File not found."); }
    }
    // ZIP-Download
    if ($isZip && $token && $files) {
        $zipname = "files_{$token}.zip";
        $tmpZip = tempnam(sys_get_temp_dir(), "zip_");
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::OVERWRITE) === TRUE) {
            foreach ($files as $file) {
                $filePath = $tokenDir . basename($file);
                if (file_exists($filePath)) $zip->addFile($filePath, basename($file));
            }
            $zip->close();
            log_action($tokenDir, "ZIP-Download of all Files");
            ignore_user_abort(true); set_time_limit(0);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipname . '"');
            header('Content-Length: ' . filesize($tmpZip));
            $fp = fopen($tmpZip, 'rb');
            while (!feof($fp)) { echo fread($fp, 8192); flush(); if (connection_aborted()) { fclose($fp); unlink($tmpZip); exit; } }
            fclose($fp);
            foreach ($files as $file) {
                $filePath = $tokenDir . basename($file);
                if (file_exists($filePath)) unlink($filePath);
            }
            if (file_exists($mapfile)) unlink($mapfile);
            if (file_exists($metaFile)) unlink($metaFile);
            unlink($tmpZip);
            array_map('unlink', glob($tokenDir . 'chunks_*/*'));
            array_map('rmdir', glob($tokenDir . 'chunks_*'));
            exit;
        } else { http_response_code(500); die("Could not create ZIP archive."); }
    }
}

// AJAX-Liste mit Passwort
if (isset($_GET['list'])) {
    $requirePassword = !empty($meta['password']);
    $pwOk = false;
    if ($requirePassword) {
        $pw = getPassword();
        if ($pw && hashPassword($pw) === $meta['password']) $pwOk = true;
    } else {
        $pwOk = true;
    }
    if (!$pwOk) {
        header("Content-Type: application/json");
        echo json_encode(['error' => 'Password required or wrong password.']);
        exit;
    }
    echo json_encode(['files' => $files]);
    exit;
}

// Einzeldatei-Löschen (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete']) && $token) {
    $deleted = [];
    if (!empty($_POST['file'])) {
        $f = basename($_POST['file']);
        $filePath = $tokenDir . $f;
        if (file_exists($filePath)) { unlink($filePath); $deleted[] = $f; }
        $newFiles = array_filter($files, function ($x) use ($f) { return $x !== $f; });
        if (!empty($newFiles)) {
            file_put_contents($mapfile, implode("\n", $newFiles));
            if (file_exists($metaFile)) {
                $metaArr = json_decode(file_get_contents($metaFile), true) ?: [];
                file_put_contents($metaFile, json_encode($metaArr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }
        } else {
            if (file_exists($mapfile)) unlink($mapfile);
            if (file_exists($metaFile)) unlink($metaFile);
        }
        // Auch Chunks löschen
        $tmpDir = $tokenDir . 'chunks_' . md5($f) . '/';
        if (is_dir($tmpDir)) { array_map('unlink', glob($tmpDir . "/*")); rmdir($tmpDir); }
    } else {
        // Lösche ALLE Dateien
        foreach ($files as $f) {
            $filePath = $tokenDir . basename($f);
            if (file_exists($filePath)) { unlink($filePath); $deleted[] = $f; }
            $tmpDir = $tokenDir . 'chunks_' . md5($f) . '/';
            if (is_dir($tmpDir)) { array_map('unlink', glob($tmpDir . "/*")); rmdir($tmpDir); }
        }
        if (file_exists($mapfile)) unlink($mapfile);
        if (file_exists($metaFile)) unlink($metaFile);
    }
    log_action($tokenDir, "Delete ".(empty($_POST['file']) ? "ALL files" : $_POST['file']));
    header("Content-Type: application/json");
    echo json_encode([
        "status" => "deleted",
        "files" => $deleted,
        "token" => $token
    ]);
    exit;
}

// --- Download-HTML-Oberfläche (Listing) ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>File Download</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"></script>
    <style>
        .download-card { max-width: 540px; margin: 3rem auto; }
        .token-label { font-size: 1rem; color: #999; }
        .file-link { word-break: break-all; }
    </style>
</head>
<body>
<!-- QR Modal (Bootstrap) -->
<div class="modal fade" id="qrModal" tabindex="-1" aria-labelledby="qrModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-center">
            <div class="modal-header">
                <h5 class="modal-title" id="qrModalLabel">Share as QR-Code</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="qrCanvas"></div>
                <div id="qrUrl" class="small mt-3 mb-2 text-break"></div>
            </div>
            <div class="modal-footer justify-content-center">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="container download-card">
    <h2 class="mb-4 text-primary text-center">Your Uploaded Files</h2>
    <?php if ($token): ?>
        <div class="mb-3 text-center token-label">Token: <b><?= htmlspecialchars($token) ?></b></div>
        <?php if (!empty($meta['expiry'])): ?>
            <div class="mb-2 text-center small text-muted">
                Expires: <?= htmlspecialchars($meta['expiry']) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($meta['password'])): ?>
            <div class="mb-2 text-center small text-warning">Password protected</div>
        <?php endif; ?>
    <?php endif; ?>
    <div id="alertBox"></div>
    <?php if ($files): ?>
        <div class="list-group mb-4">
            <?php foreach ($files as $f): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center flex-wrap">
                        <span>
                            <span style="max-width: 250px;" class="file-link text-truncate d-inline-block" title="<?= htmlspecialchars($f) ?>"><?= htmlspecialchars($f) ?></span>
                        </span>
                    <span>
                            <a href="/download/<?= urlencode($token) ?>/file/<?= rawurlencode($f) ?><?= !empty($meta['password']) ? '?password='.urlencode(getPassword()) : '' ?>"
                               class="btn btn-outline-primary btn-sm">Download</a>
                            <button class="delete-btn btn btn-outline-danger btn-sm ms-2"
                                    data-file="<?= htmlspecialchars($f) ?>">&times;</button>
                        </span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="text-center mb-3">
            <?php if (count($files) > 1): ?>
                <a href="/download/<?= urlencode($token) ?>/zip<?= !empty($meta['password']) ? '?password='.urlencode(getPassword()) : '' ?>" class="btn btn-success btn-sm me-2" id="zipBtn">Download all as ZIP</a>
            <?php endif; ?>
            <button id="deleteBtn" class="btn btn-danger btn-sm">Delete all files</button>
            <button id="shareBtn" class="btn btn-outline-secondary btn-sm ms-2"
                    data-link="<?= htmlspecialchars('https://' . $_SERVER['HTTP_HOST'] . '/download/' . urlencode($token)) ?>">Share</button>
            <button id="qrcodeBtn" class="btn btn-outline-info btn-sm ms-2"
                    data-link="<?= htmlspecialchars('https://' . $_SERVER['HTTP_HOST'] . '/download/' . urlencode($token)) ?>">QR</button>
        </div>
        <iframe id="zipFrame" style="display:none;"></iframe>
    <?php elseif ($token): ?>
        <div class="alert alert-warning text-center">No files available for this token.</div>
    <?php else: ?>
        <div class="alert alert-info text-center">No token provided.</div>
    <?php endif; ?>
    <div class="mt-5 text-center small text-muted">
        <a href="/?token=<?= htmlspecialchars($token) ?>" class="link-secondary">&larr; Back to upload</a>
    </div>
</div>
<script>
    let token = "<?= htmlspecialchars($token) ?>";
    const deleteBtn = document.getElementById('deleteBtn');
    const alertBox = document.getElementById('alertBox');
    const zipBtn = document.getElementById('zipBtn');
    const shareBtn = document.getElementById('shareBtn');
    const qrcodeBtn = document.getElementById('qrcodeBtn');
    document.querySelectorAll('.delete-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            let file = btn.getAttribute('data-file');
            if (confirm('Delete file: ' + file + '?')) {
                fetch('/download/' + encodeURIComponent(token), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'delete=1&file=' + encodeURIComponent(file)
                })
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === 'deleted') {
                            btn.closest('.input-group, .download-row, .list-group-item').remove();
                            if (!document.querySelector('.delete-btn')) {
                                let box = document.getElementById('result') || document.getElementById('resultBox');
                                if (box) {
                                    box.innerHTML = '<div class="alert alert-info text-center mt-3">No files left.</div>';
                                }
                            }
                        }
                    });
            }
        });
    });
    if (deleteBtn) {
        deleteBtn.onclick = function () {
            if (confirm('Really delete all files for this token?')) {
                fetch('/download/' + encodeURIComponent(token), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'delete=1'
                })
                    .then(r => r.json())
                    .then(data => {
                        if (data.status === "deleted") {
                            alertBox.innerHTML =
                                `<div class="alert alert-warning mt-2">Deleted files: ${data.files.map(f => '<span>' + f + '</span>').join(', ')}</div>`;
                            document.querySelectorAll('.list-group, #deleteBtn, #zipBtn').forEach(el => el && (el.style.display = 'none'));
                        } else {
                            alertBox.innerHTML = `<div class="alert alert-danger mt-2">Deletion failed.</div>`;
                        }
                    });
            }
        }
    }
    if (zipBtn) {
        zipBtn.addEventListener('click', function (e) {
            e.preventDefault();
            document.getElementById('zipFrame').src = this.href;
            setTimeout(function () {
                window.location.href = "/download/" + encodeURIComponent(token) + "?deleted=1";
            }, 2000);
        });
    }
    if (shareBtn) {
        shareBtn.addEventListener('click', function () {
            let url = shareBtn.getAttribute('data-link');
            if (navigator.share) {
                navigator.share({ url: url, title: "Download link" });
            } else {
                navigator.clipboard.writeText(url);
                alert('Link copied to clipboard!');
            }
        });
    }
    if (qrcodeBtn) {
        qrcodeBtn.addEventListener('click', function () {
            let url = qrcodeBtn.getAttribute('data-link');
            let qrDiv = document.getElementById('qrCanvas');
            let qrUrl = document.getElementById('qrUrl');
            qrDiv.innerHTML = '';
            let qr = new QRious({ element: document.createElement('canvas'), value: url, size: 220 });
            qrDiv.appendChild(qr.element);
            qrUrl.textContent = url;
            let modal = new bootstrap.Modal(document.getElementById('qrModal'));
            modal.show();
        });
    }
</script>
</body>
</html>
