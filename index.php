<?php
// Cleanup: löscht leere oder alte Token-Ordner (3 Tage, nur auf Hauptverzeichnis-Ebene!)
$uploadDir = __DIR__ . '/uploads/';
$now = time();
$maxAge = 3 * 24 * 60 * 60;

foreach (glob($uploadDir . '*') as $entry) {
    if (is_dir($entry)) {
        // Einzelne Files/Chunks im Token-Ordner aufräumen
        foreach (glob($entry . '/*') as $file) {
            if (is_file($file) && $now - filemtime($file) > $maxAge) unlink($file);
            if (is_dir($file) && preg_match('#^chunks#', basename($file)) && $now - filemtime($file) > $maxAge) {
                array_map('unlink', glob($file . '/*'));
                rmdir($file);
            }
        }
        // Token-Ordner löschen, wenn leer
        if (count(glob($entry . '/*')) === 0) rmdir($entry);
    }
}

// Prefill: Token/Password aus URL, falls vorhanden
function getTokenFromUrl() { return isset($_GET['token']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['token']) : ''; }
function getPasswordFromUrl() { return isset($_GET['password']) ? $_GET['password'] : ''; }
$tokenFromUrl = getTokenFromUrl();
$pwFromUrl = getPasswordFromUrl();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Resumable File Upload</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/resumable.js/1.1.0/resumable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrious/4.0.2/qrious.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        .drop-area { border: 2px dashed #0d6efd; border-radius: 1rem; background: #f8f9fa; padding: 3rem 2rem; text-align: center; transition: border-color .3s; min-height: 140px; margin-bottom: 1.5rem;}
        .drop-area.highlight { border-color: #198754; background: #e8f5e9; }
        .progress { height: 1.4rem; }
        .file-label { font-weight: 500; color: #495057; }
        #result a { word-break: break-all; }
        #upload-controls button { min-width: 80px; }
        .qrcode-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.2);}
        .qrcode-modal-inner { background: #fff; border-radius: 1em; padding: 2em 1em; max-width: 320px; margin: 8vh auto 0 auto; text-align: center;}
    </style>
</head>
<body>
<div class="container my-5" style="max-width: 540px;">
    <h2 class="mb-4 text-primary text-center">Resumable File Upload</h2>
    <form id="uploadForm" autocomplete="off" class="mb-3">
        <div class="mb-3 d-flex justify-content-center flex-wrap gap-3">
            <div class="form-text">Open this page with <code>/&lt;token&gt;/&lt;password&gt;</code> to prefill the fields.</div>
            <div class="d-flex align-items-center">
                <label for="token" class="form-label mb-0 me-2">Token</label>
                <div class="input-group input-group-sm" style="width: 150px;">
                    <input type="text" class="form-control" id="token" name="token" placeholder="optional"
                           value="<?= htmlspecialchars($tokenFromUrl) ?>">
                    <button type="button" class="btn btn-outline-secondary" id="genTokenBtn" title="Generate token"><i class="bi bi-shuffle"></i></button>
                </div>
            </div>
            <div class="d-flex align-items-center">
                <label for="password" class="form-label mb-0 me-2">Password</label>
                <div class="input-group input-group-sm" style="width: 150px;">
                    <input type="password" class="form-control" id="password" name="password" placeholder="optional"
                           value="<?= htmlspecialchars($pwFromUrl) ?>">
                    <button type="button" class="btn btn-outline-secondary" id="togglePassword" title="Show/Hide password"><i class="bi bi-eye"></i></button>
                </div>
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
        <div id="drop-area" class="drop-area">
            <p class="mb-2">Drag &amp; drop files here<br>or <button id="browseBtn" type="button"
                                                                     class="btn btn-link px-0 py-0 align-baseline" style="font-size:1rem;">browse</button></p>
            <div id="file-list" class="mb-2"></div>
            <div class="progress mb-2" style="display:none;">
                <div id="progress-bar-inner" class="progress-bar progress-bar-striped bg-primary" role="progressbar"
                     style="width: 0%"></div>
            </div>
            <div id="total-progress" class="mb-2"></div>
            <div id="upload-controls" class="mb-2 d-flex justify-content-center gap-2" style="display:none;">
                <button id="pauseBtn" type="button" class="btn btn-outline-secondary btn-sm">Pause</button>
                <button id="resumeBtn" type="button" class="btn btn-outline-primary btn-sm"
                        style="display:none;">Resume</button>
                <button id="cancelBtn" type="button" class="btn btn-outline-danger btn-sm">Cancel</button>
            </div>
            <div id="progress-info" class="mb-2 small text-secondary"></div>
            <div id="result" class="mt-2"></div>
        </div>
    </form>
</div>
<div id="qrModal" class="qrcode-modal">
    <div class="qrcode-modal-inner">
        <div id="qrCanvas"></div>
        <div id="qrUrl" class="small mb-3 mt-3"></div>
        <button type="button" class="btn btn-outline-secondary btn-sm"
                onclick="document.getElementById('qrModal').style.display='none'">Close</button>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // --------- Utility ---------
    function randomToken(length = 7) {
        const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        let result = '';
        for (let i = 0; i < length; i++) result += chars.charAt(Math.floor(Math.random() * chars.length));
        return result;
    }
    function formatBytes(bytes) {
        if (bytes === 0) return '0 B';
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = parseInt(Math.floor(Math.log(bytes) / Math.log(1024)));
        return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + sizes[i];
    }

    const invisibleToken = randomToken();
    let currentToken = "";
    const tokenInput = document.getElementById('token');
    const passwordInput = document.getElementById('password');
    const expiryInput = document.getElementById('expiry');
    const genTokenBtn = document.getElementById('genTokenBtn');
    const togglePasswordBtn = document.getElementById('togglePassword');
    function getEffectiveToken() {
        return tokenInput.value.trim() || currentToken || invisibleToken;
    }

    if (genTokenBtn) {
        genTokenBtn.addEventListener('click', function () {
            const newTok = randomToken();
            tokenInput.value = newTok;
            currentToken = newTok;
        });
    }

    if (togglePasswordBtn) {
        togglePasswordBtn.addEventListener('click', function () {
            const isPwd = passwordInput.type === 'password';
            passwordInput.type = isPwd ? 'text' : 'password';
            this.innerHTML = isPwd ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
        });
    }

    document.body.addEventListener('dragover', e => { e.preventDefault(); document.getElementById('drop-area').classList.add('highlight'); });
    document.body.addEventListener('dragleave', e => { e.preventDefault(); document.getElementById('drop-area').classList.remove('highlight'); });
    document.body.addEventListener('drop', e => {
        e.preventDefault();
        document.getElementById('drop-area').classList.remove('highlight');
    });

    let r = new Resumable({
        target: '/action/upload',
        query: function () {
            let tInput = tokenInput.value.trim();
            let token = tInput || currentToken || invisibleToken;
            let pw = passwordInput.value.trim();
            let expiry = expiryInput.value || "3d";
            return { token: token, password: pw, expiry: expiry };
        },
        chunkSize: 2 * 1024 * 1024,
        simultaneousUploads: 2,
        testChunks: true,
        throttleProgressCallbacks: 0,
        maxFiles: 10,
        headers: {},
        forceChunkSize: true
    });

    // UI Elements
    const dropArea = document.getElementById('drop-area');
    const browseBtn = document.getElementById('browseBtn');
    const progressBar = document.querySelector('.progress');
    const progressBarInner = document.getElementById('progress-bar-inner');
    const progressInfo = document.getElementById('progress-info');
    const totalProgress = document.getElementById('total-progress');
    const fileListBox = document.getElementById('file-list');
    const resultBox = document.getElementById('result');
    const pauseBtn = document.getElementById('pauseBtn');
    const resumeBtn = document.getElementById('resumeBtn');
    const cancelBtn = document.getElementById('cancelBtn');
    const uploadControls = document.getElementById('upload-controls');

    function showUploadControls(state) {
        if (state === 'uploading') {
            uploadControls.style.display = '';
            pauseBtn.style.display = '';
            resumeBtn.style.display = 'none';
            cancelBtn.style.display = '';
        } else if (state === 'paused') {
            uploadControls.style.display = '';
            pauseBtn.style.display = 'none';
            resumeBtn.style.display = '';
            cancelBtn.style.display = '';
        } else {
            uploadControls.style.display = 'none';
            pauseBtn.style.display = 'none';
            resumeBtn.style.display = 'none';
            cancelBtn.style.display = 'none';
        }
    }
    showUploadControls('none');
    function hideProgressBar() {
        progressBar.style.display = 'none';
        progressBarInner.style.width = "0%";
        progressBarInner.textContent = "";
        progressInfo.innerHTML = "";
    }

    r.assignDrop(dropArea);
    r.assignBrowse(browseBtn);

    r.on('fileAdded', function (file) {
        resultBox.innerHTML = '';
        if (r.files.length === 1) uploadedFiles = [];
        let tInput = tokenInput.value.trim();
        let effectiveToken = getEffectiveToken();
        if (!tInput) tokenInput.value = effectiveToken;
        currentToken = effectiveToken;
        progressBar.style.display = '';
        progressBarInner.style.width = "0%";
        progressInfo.innerHTML = '';
        showUploadControls('uploading');
        file.uploadStartTime = Date.now();
        r.upload();
    });

    r.on('fileProgress', function (file) {
        let percent = Math.floor(file.progress() * 100);
        let total = formatBytes(file.size);
        let uploadedBytes = file.progress() * file.size;
        let uploaded = formatBytes(uploadedBytes);
        let elapsedSec = (Date.now() - (file.uploadStartTime || Date.now())) / 1000;
        let speed = elapsedSec > 0 ? uploadedBytes / elapsedSec : 0;
        let speedMB = (speed / 1024 / 1024).toFixed(2);
        let totalChunks = file.chunks.length;
        let currentChunkIdx = file.chunks.findIndex(c => c.status() === 'uploading') + 1;
        if (currentChunkIdx <= 0) currentChunkIdx = totalChunks;

        // Verbleibende Zeit berechnen
        let remainingSec = speed > 0 ? (file.size - uploadedBytes) / speed : 0;
        let eta = '';
        if (speed > 0 && isFinite(remainingSec)) {
            let min = Math.floor(remainingSec / 60);
            let sec = Math.floor(remainingSec % 60);
            eta = min > 0 ? `${min}m ${sec}s` : `${sec}s`;
        } else {
            eta = '...';
        }

        progressBarInner.style.width = percent + '%';
        progressBarInner.textContent = percent + ' %';
        progressInfo.innerHTML =
            `<div class="text-center">
            <div>Speed: ${speedMB} MB/s</div>
            <div>Uploaded: ${uploaded} / ${total}</div>
            <div>Chunk ${currentChunkIdx} of ${totalChunks}</div>
            <div>Time remaining: ${eta}</div>
        </div>`;
        renderTotalProgress();
    });
    r.on('progress', renderTotalProgress);

    function renderTotalProgress() {
        let all = r.files.reduce((acc, f) => acc + f.size, 0);
        let done = r.files.reduce((acc, f) => acc + f.size * f.progress(), 0);
        let percent = all ? Math.floor((done / all) * 100) : 0;
        totalProgress.innerHTML = all ? `<div class="progress" style="height: 8px;">
            <div class="progress-bar bg-success" style="width:${percent}%;"></div>
        </div>
        <div class="text-center small">${formatBytes(done)} / ${formatBytes(all)} (${percent} %)</div>` : '';
    }

    let uploadedFiles = [];

    // Nach Upload: zeige Liste (API!)
    r.on('fileSuccess', function (file, resp) {
        let result;
        try { result = JSON.parse(resp); } catch (e) { result = {}; }
        if (result.token) currentToken = result.token;
        if (!tokenInput.value.trim()) tokenInput.value = currentToken;
        let token = currentToken;
        let baseUrl = window.location.origin + '/d/' + encodeURIComponent(token);
        if (r.files.every(f => f.isComplete())) hideProgressBar();

        fetch('/action/l/' + encodeURIComponent(token) + '/' + encodeURIComponent(passwordInput.value))
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    resultBox.innerHTML = `<div class="alert alert-warning text-center">${data.error}</div>`;
                    return;
                }
                renderResult(data, baseUrl, resultBox, "File uploaded!");
            });
    });

    function renderResult(data, baseUrl, result, message = null) {
        if (!data.files || !data.files.length) return;
        let headline;
        if (typeof message !== 'undefined' && message !== null && message !== '') {
            headline = message;
        } else if (typeof message === 'undefined') {
            headline = (data.files.length > 1 ? "All files uploaded!" : "File uploaded!");
        } else {
            headline = '';
        }

        let html = '';
        if (headline) {
            html += `<div class="alert alert-success py-2 px-3 mb-2"><b>${headline}</b></div>`;
        }
        html += `
<div class="mb-2 small">Download links:</div>
<div class="input-group mb-2">
  <input type="text" class="form-control" id="mainDownloadLink" value="${baseUrl}" readonly>
  <button class="btn btn-outline-secondary copy-btn" type="button" data-clipboard-target="mainDownloadLink" title="Copy folder link"><i class="bi bi-clipboard"></i></button>
</div>
<div id="copyStatus-mainDownloadLink" class="small text-success mb-2" style="display:none;">Folder link copied!</div>
`;
        if (data.files.length > 1) {
            html += `
<div class="input-group mb-2">
  <input type="text" class="form-control" id="zipDownloadLink" value="${baseUrl}/zip" readonly>
  <button class="btn btn-outline-secondary copy-btn" type="button" data-clipboard-target="zipDownloadLink" title="Copy ZIP link"><i class="bi bi-clipboard"></i></button>
</div>
<div id="copyStatus-zipDownloadLink" class="small text-success mb-2" style="display:none;">ZIP link copied!</div>`;
        }
        data.files.forEach((fn, idx) => {
            let fileLinkId = 'fileDownloadLink_' + idx;
            html += `
<div class="input-group mb-2 download-row">
  <input type="text" class="form-control form-control-sm dl-input" id="${fileLinkId}" value="${fn.name}" data-full-link="${baseUrl}/f/${encodeURIComponent(fn.name)}" readonly>
  <button class="btn btn-outline-secondary btn-sm copy-btn" type="button" data-clipboard-target="${fileLinkId}"><i class="bi bi-clipboard"></i></button>
  <a href="${baseUrl}/f/${encodeURIComponent(fn.name)}" class="btn btn-outline-primary btn-sm file-download-link" target="_blank"><i class="bi bi-download"></i></a>
  <button class="delete-btn btn btn-outline-danger btn-sm" title="Delete file" data-file="${fn.name}" type="button"><i class="bi bi-trash"></i></button>
</div>
<div id="copyStatus-${fileLinkId}" class="small text-success mb-2" style="display:none;">File link copied!</div>`;
        });
        result.innerHTML = html;
        document.querySelectorAll('.copy-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var targetId = btn.getAttribute('data-clipboard-target');
                var input = document.getElementById(targetId);
                if (input) {
                    var fullLink = input.getAttribute('data-full-link') || input.value;
                    navigator.clipboard.writeText(fullLink).then(function () {
                        var status = document.getElementById('copyStatus-' + targetId);
                        if (status) {
                            status.style.display = '';
                            setTimeout(() => { status.style.display = 'none'; }, 1500);
                        }
                    });
                }
            });
        });
        document.querySelectorAll('.delete-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                let file = btn.getAttribute('data-file');
                let token = getEffectiveToken();
                if (confirm('Delete file: ' + file + '?')) {
                    fetch('/action/delete', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'token=' + encodeURIComponent(token) +
                            '&file=' + encodeURIComponent(file) +
                            '&password=' + encodeURIComponent(passwordInput.value)
                    })
                        .then(r => r.json())
                        .then(data => {
                            if (data.status === 'deleted') btn.closest('.input-group').remove();
                        });
                }
            });
        });
        document.querySelectorAll('.dl-input').forEach(function (input) {
            input.addEventListener('copy', function (e) {
                var fullLink = input.getAttribute('data-full-link');
                if (fullLink) {
                    e.preventDefault();
                    if (e.clipboardData) {
                        e.clipboardData.setData('text/plain', fullLink);
                    } else if (window.clipboardData) {
                        window.clipboardData.setData('Text', fullLink);
                    }
                }
            });
        });
    }

    r.on('fileError', function (file, msg) {
        resultBox.innerHTML = `<div class="alert alert-danger py-2 px-3 mb-2">Error during upload: ${msg}</div>`;
        progressBar.style.display = "none";
        progressInfo.innerHTML = "";
    });

    pauseBtn.onclick = function () {
        r.pause(); showUploadControls('paused');
    };
    resumeBtn.onclick = function () {
        r.upload(); showUploadControls('uploading');
    };
    cancelBtn.onclick = function () {
        r.cancel(); showUploadControls('none'); progressBar.style.display = 'none'; progressInfo.innerHTML = ""; fileListBox.innerHTML = '';
        resultBox.innerHTML = `<div class="alert alert-warning py-2 px-3 mb-2">Upload canceled.</div>`;
    };
    r.on('complete', function () {
        let baseUrl = window.location.origin + '/d/' + encodeURIComponent(currentToken);
        fetch('/action/l/' + encodeURIComponent(currentToken) + '/' + encodeURIComponent(passwordInput.value))
            .then(r => r.json())
            .then(data => {
                renderResult(data, baseUrl, resultBox, "All files uploaded!");
            });
        showUploadControls('none');
        hideProgressBar();
    });

    // 3. On page load: load list
    function loadFilesWithPassword() {
        let initialToken = tokenInput.value.trim();
        if (initialToken) {
            let token = initialToken;
            let baseUrl = window.location.origin + '/d/' + encodeURIComponent(token);
            fetch('/action/l/' + encodeURIComponent(token) + '/' + encodeURIComponent(passwordInput.value))
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        resultBox.innerHTML = `<div class="alert alert-warning text-center">${data.error}</div>`;
                        fileListBox.innerHTML = '';
                        return;
                    }
                    fileListBox.innerHTML = '';
                    renderResult(data, baseUrl, resultBox, null);
                })
        }
    }
    document.addEventListener('DOMContentLoaded', loadFilesWithPassword);
    let pwTimeout = null;
    passwordInput.addEventListener('input', function () {
        clearTimeout(pwTimeout);
        pwTimeout = setTimeout(loadFilesWithPassword, 400);
    });
    tokenInput.addEventListener('input', function () {
        clearTimeout(pwTimeout);
        pwTimeout = setTimeout(loadFilesWithPassword, 200);
    });
</script>
</body>
</html>
