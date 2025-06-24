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
<div class="container my-5" style="max-width: 600px;">
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
            <div id="passwordLinkOption" class="form-check small" style="display:none;">
                <input class="form-check-input" type="checkbox" value="" id="includePasswordLink">
                <label class="form-check-label" for="includePasswordLink">Attach password to links</label>
            </div>
            <div class="form-check small">
                <input class="form-check-input" type="checkbox" id="encryptToggle">
                <label class="form-check-label" for="encryptToggle">Encrypt client-side</label>
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
<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header p-1 border-0">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center">
                <img id="previewImg" src="" style="max-width:100%; display:none;" alt="Preview">
                <pre id="previewText" class="text-start" style="white-space:pre-wrap; max-height:60vh; overflow:auto; display:none;"></pre>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/encryption.js?t=<?= time() ?>"></script>
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
    const passwordLinkOption = document.getElementById('passwordLinkOption');
    const includePasswordLink = document.getElementById('includePasswordLink');
    const encryptToggle = document.getElementById('encryptToggle');
    const genTokenBtn = document.getElementById('genTokenBtn');
    const togglePasswordBtn = document.getElementById('togglePassword');
    let encryptionState = null;
    async function ensureEncryption(password) {
        if (encryptionState &&
            encryptionState.password === password &&
            encryptionState.key && encryptionState.salt) {
            return encryptionState;
        }

        if (!encryptionState || encryptionState.password !== password) {
            encryptionState = { password };
        }

        if (encryptionState.promise) return encryptionState.promise;

        encryptionState.promise = deriveKey(password)
            .then(({ key, salt }) => {
                encryptionState.key = key;
                encryptionState.salt = salt;
                encryptionState.saltB64 = btoa(String.fromCharCode(...salt));
                encryptionState.promise = null;
                return encryptionState;
            })
            .catch(err => {
                console.error('Key derivation failed:', err);
                encryptionState = null;
                throw err;
            });

        return encryptionState.promise;
    }
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

    function updatePasswordOption() {
        if (!passwordLinkOption) return;
        passwordLinkOption.style.display = passwordInput.value.trim() ? '' : 'none';
    }
    updatePasswordOption();
    if (passwordInput) passwordInput.addEventListener('input', updatePasswordOption);
    if (includePasswordLink) includePasswordLink.addEventListener('change', function(){
        if(lastRender) renderResult(lastRender.data, lastRender.baseUrl, resultBox, lastRender.message);
    });

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
            let q = { token: token, password: pw, expiry: expiry };
            if (encryptToggle && encryptToggle.checked && pw) {
                q.encrypted = 1;
            }
            return q;
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
    let lastRender = null;

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

    function showPreview(url, ext) {
        const LOADER_SVG =
            "data:image/svg+xml;utf8," +
            encodeURIComponent(`
    <svg width="64" height="64" viewBox="0 0 64 48" xmlns="http://www.w3.org/2000/svg">
      <circle cx="32" cy="24" r="10" fill="#007bff">
        <animate
          attributeName="cy"
          values="24;6;24"
          keyTimes="0;0.5;1"
          dur="0.8s"
          repeatCount="indefinite"/>
        <animate
          attributeName="r"
          values="10;12;10"
          keyTimes="0;0.5;1"
          dur="0.8s"
          repeatCount="indefinite"/>
      </circle>
      <ellipse cx="32" cy="41" rx="14" ry="3.2" fill="#b3d6fc" opacity="0.7">
        <animate
          attributeName="rx"
          values="14;8;14"
          keyTimes="0;0.5;1"
          dur="0.8s"
          repeatCount="indefinite"/>
          <animate
          attributeName="opacity"
          values="0.7;0.4;0.7"
          keyTimes="0;0.5;1"
          dur="0.8s"
          repeatCount="indefinite"/>
      </ellipse>
    </svg>
  `);

        const img = document.getElementById('previewImg');
        const txt = document.getElementById('previewText');
        img.style.display = 'none';
        txt.style.display = 'none';

        // Lade-Bobber anzeigen, bevor etwas geladen wird
        img.src = LOADER_SVG;
        img.style.display = '';
        txt.style.display = 'none';

        if (['jpg','jpeg','png','gif','webp','bmp','svg'].includes(ext)) {
            // Bilddateien
            img.src = LOADER_SVG; // Erst Bobber, dann Bild nachladen
            img.onload = null; // Entferne evtl. alten onload-Handler
            // Nach kurzem Timeout das Bild wirklich laden (Bobber bleibt kurz sichtbar)
            setTimeout(() => {
                img.src = url;
                img.onload = () => {}; // optional: nach Laden z. B. Spinner ausblenden
            }, 150);
            txt.style.display = 'none';
            new bootstrap.Modal(document.getElementById('previewModal')).show();
        } else if (['txt','md','csv','log','html','htm','json'].includes(ext)) {
            // Textdateien
            img.style.display = 'none';
            txt.textContent = "Loading...";
            txt.style.display = '';
            fetch(url).then(r => r.text()).then(t => {
                txt.textContent = t.substring(0,2000) + (t.length>2000?'...':'');
            }).catch(() => {
                txt.textContent = "Error loading preview!";
            });
            new bootstrap.Modal(document.getElementById('previewModal')).show();
        } else {
            // Nicht unterstützt: Zeige Hinweis
            img.style.display = 'none';
            txt.textContent = "Preview not supported for this filetype.";
            txt.style.display = '';
            new bootstrap.Modal(document.getElementById('previewModal')).show();
        }
    }


    function initPreviewButtons() {
        document.querySelectorAll('.preview-btn').forEach(btn => {
            if (btn.dataset.previewBound) return;
            btn.dataset.previewBound = '1';
            const url = btn.getAttribute('data-url');
            const ext = btn.getAttribute('data-ext');
            btn.addEventListener('click', () => showPreview(url, ext));
        });
    }

    function triggerDownload(blob, filename) {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(url); a.remove(); }, 100);
    }

    function downloadAndDecrypt(url, filename, link) {
        const icon = link.querySelector('i');
        const origClass = icon ? icon.className : '';
        if (icon) {
            icon.className = 'spinner-border spinner-border-sm';
        }
        link.classList.add('disabled');

        fetch(url).then(resp => resp.blob().then(async b => {
            if (resp.headers.get('X-Encrypted')) {
                const buf = await b.arrayBuffer();
                const dec = await decryptFile(buf, passwordInput.value.trim());
                triggerDownload(dec, filename);
            } else {
                triggerDownload(b, filename);
            }
        })).finally(() => {
            if (icon) icon.className = origClass;
            link.classList.remove('disabled');
        });

    }

    function initDownloadLinks() {
        document.querySelectorAll('.file-download-link').forEach(a => {
            if (a.dataset.dlBound) return;
            a.dataset.dlBound = '1';
            a.addEventListener('click', e => {
                e.preventDefault();
                const fname = a.getAttribute('data-filename') || '';
                downloadAndDecrypt(a.href, fname, a);

            });
        });
    }

    r.assignDrop(dropArea);
    r.assignBrowse(browseBtn);

    r.on('fileAdded', function (file) {
        if (file.isEncrypted) return; // skip duplicate events
        resultBox.innerHTML = '';
        let tInput = tokenInput.value.trim();
        let effectiveToken = getEffectiveToken();
        if (!tInput) tokenInput.value = effectiveToken;
        currentToken = effectiveToken;
        progressBar.style.display = '';
        progressBarInner.style.width = "0%";
        progressInfo.innerHTML = '';
        showUploadControls('uploading');
        file.uploadStartTime = Date.now();
        const startUpload = () => r.upload();
        if (file.isEncrypted || (file.file && file.file.isEncrypted)) return; // avoid re-processing replacement files

        const pw = passwordInput.value.trim();
        if (encryptToggle && encryptToggle.checked && pw) {
            setupEncryption(r, pw)
                .then(startUpload)
                .catch(() => startUpload());
        } else {
            startUpload();
        }
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
        const attachPw = includePasswordLink && includePasswordLink.checked && passwordInput.value.trim() !== '';
        const pwSeg = attachPw ? '/' + encodeURIComponent(passwordInput.value.trim()) : '';
        if (headline) {
            html += `<div class="alert alert-success py-2 px-3 mb-2"><b>${headline}</b></div>`;
        }
        html += `
<div class="mb-2 small">Download links:</div>
<div class="input-group mb-2">
  <input type="text" class="form-control" id="mainDownloadLink" value="${baseUrl}${pwSeg}" readonly>
  <button class="btn btn-outline-secondary copy-btn" type="button" data-clipboard-target="mainDownloadLink" title="Copy folder link"><i class="bi bi-clipboard"></i></button>
</div>
<div id="copyStatus-mainDownloadLink" class="small text-success mb-2" style="display:none;">Folder link copied!</div>
`;
        if (data.files.length > 1) {
            html += `
<div class="input-group mb-2">
  <input type="text" class="form-control" id="zipDownloadLink" value="${baseUrl}/zip${pwSeg}" readonly>
  <button class="btn btn-outline-secondary copy-btn" type="button" data-clipboard-target="zipDownloadLink" title="Copy ZIP link"><i class="bi bi-clipboard"></i></button>
</div>
<div id="copyStatus-zipDownloadLink" class="small text-success mb-2" style="display:none;">ZIP link copied!</div>`;
        }
        const pwVal = passwordInput.value.trim();
        const pwSegFile = pwVal ? '/' + encodeURIComponent(pwVal) : '';
        data.files.forEach((fn, idx) => {
            let fileLinkId = 'fileDownloadLink_' + idx;
            let fileUrl = `${baseUrl}/f/${encodeURIComponent(fn.name)}${pwSeg}`;
            let previewUrl = `${window.location.origin}/action/d/${encodeURIComponent(fn.token)}/f/${encodeURIComponent(fn.name)}${pwSegFile}?preserve=true`;
            let ext = fn.name.split('.').pop().toLowerCase();
            let lock = fn.encrypted ? ' \uD83D\uDD12' : '';

            let previewsymbol = fn.encrypted ? 'bi bi-eye-slash' : 'bi bi-eye';
            html += `
<div class="input-group mb-2 download-row">
  <input type="text" class="form-control form-control-sm dl-input" id="${fileLinkId}" value="${fn.name}${lock}" data-full-link="${fileUrl}" readonly>
  <button class="btn btn-outline-secondary btn-sm copy-btn" type="button" data-clipboard-target="${fileLinkId}"><i class="bi bi-clipboard"></i></button>

  <button class="btn btn-outline-secondary btn-sm preview-btn${fn.encrypted ? ' disabled' : ''}" type="button" data-url="${previewUrl}" data-ext="${ext}" title="Preview"><i class="${previewsymbol}"></i></button>
  <a href="${fileUrl}" class="btn btn-outline-primary btn-sm file-download-link" data-filename="${fn.name}"><i class="bi bi-download"></i></a>
  <button class="delete-btn btn btn-outline-danger btn-sm" title="Delete file" data-file="${fn.name}" type="button"><i class="bi bi-trash"></i></button>
</div>
<div id="copyStatus-${fileLinkId}" class="small text-success mb-2" style="display:none;">File link copied!</div>`;
        });
        result.innerHTML = html;
        lastRender = {data: data, baseUrl: baseUrl, message: message};
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
        initPreviewButtons();
        initDownloadLinks();
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
