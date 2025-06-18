<?php
// download.php
$token = isset($_GET['token']) ? preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['token']) : '';
$password = $_GET['password'] ?? '';
$fileHighlight = isset($_GET['file']) ? urldecode($_GET['file']) : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Download Files</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .file-highlight { background-color: #fff9d6; }
    </style>
</head>
<body>
<div class="container" style="max-width:600px; margin:2em auto;">
    <h2 class="mb-4 text-primary text-center">Your Uploaded Files</h2>
    <div id="alertBox"></div>
    <div id="filesBox"></div>
    <div id="actionBox" class="text-center my-3"></div>
    <div class="mt-5 text-center small text-muted">
        <a href="/<?= htmlspecialchars($token) . (empty($password) ? '' : '/' . htmlspecialchars($password)) ?>" class="link-secondary">&larr; Back to upload / add more files</a>
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
<script>
    const token = "<?= htmlspecialchars($token) ?>";

    let password = "<?= htmlspecialchars($password) ?>"
    const highlightFile = "<?= htmlspecialchars($fileHighlight) ?>"
    const alertBox = document.getElementById('alertBox');
    const filesBox = document.getElementById('filesBox');
    const actionBox = document.getElementById('actionBox');
    const backLink = document.querySelector('.mt-5 a.link-secondary');
    let files = [];
    updateBackLink();

    function updateBackLink() {
        if (backLink) {
            backLink.href = '/' + encodeURIComponent(token) + (password ? '/' + encodeURIComponent(password) : '');
        }
    }

    function showPasswordPrompt(msg) {
        alertBox.innerHTML = `<div class="alert alert-warning text-center">${msg}<br>` +
            `<div class="input-group mt-2"><input type="password" class="form-control" id="pwInput" placeholder="Password">` +
            `<button class="btn btn-primary" id="pwSubmit">OK</button></div></div>`;
        filesBox.innerHTML = '';
        actionBox.innerHTML = '';
        document.getElementById('pwSubmit').onclick = function () {
            password = document.getElementById('pwInput').value.trim();
            updateBackLink();
            fetchFiles();
        };
    }

    // 1. Files laden
    function fetchFiles() {
        let url = '/action/l/' + encodeURIComponent(token);
        if (password && password.trim() !== '') {
            url += '/' + encodeURIComponent(password);
        }
        fetch(url)
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    if (data.error.toLowerCase().includes('password')) {
                        showPasswordPrompt(data.error);
                    } else {
                        alertBox.innerHTML = `<div class="alert alert-danger text-center">${data.error}</div>`;
                        filesBox.innerHTML = '';
                        actionBox.innerHTML = '';
                    }
                    return;
                }
                alertBox.innerHTML = '';
                files = data.files || [];
                renderFiles();
            });
    }

    function formatBytes(bytes) {
        if (!bytes) return '';
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = bytes > 0 ? Math.floor(Math.log(bytes) / Math.log(1024)) : 0;
        return (bytes / Math.pow(1024, i)).toFixed(2) + ' ' + sizes[i];
    }

    function showPreview(url, ext) {
        const img = document.getElementById('previewImg');
        const txt = document.getElementById('previewText');
        img.style.display = 'none';
        txt.style.display = 'none';
        if(['jpg','jpeg','png','gif','webp','bmp','svg'].includes(ext)) {
            img.src = url;
            img.style.display = '';
            new bootstrap.Modal(document.getElementById('previewModal')).show();
        } else if(['txt','md','csv','log','html','htm','json'].includes(ext)) {
            fetch(url).then(r=>r.text()).then(t => {
                txt.textContent = t.substring(0,2000) + (t.length>2000?'...':'');
                txt.style.display = '';
                new bootstrap.Modal(document.getElementById('previewModal')).show();
            });
        }
    }

    function initPreviewButtons() {
        document.querySelectorAll('.preview-btn').forEach(btn => {
            const url = btn.getAttribute('data-url');
            const ext = btn.getAttribute('data-ext');
            btn.addEventListener('click', () => showPreview(url, ext));
        });
    }

    function renderFiles() {
        if (!files.length) {
            filesBox.innerHTML = `<div class="alert alert-warning text-center">No files available.</div>`;
            actionBox.innerHTML = '';
            return;
        }
        let html = '<div class="list-group mb-4">';
        const pwSeg = password && password.trim() !== '' ? '/' + encodeURIComponent(password) : '';
        const pwSegFile = password && password.trim() !== '' ? '/' + encodeURIComponent(password) : '';
        files.forEach(f => {
            let name = f.name || f;
            let size = f.size ? formatBytes(f.size) : '';
            let downloadUrl = `/action/d/${encodeURIComponent(token)}/f/${encodeURIComponent(name)}${pwSeg}`;
            let previewUrl = `/action/d/${encodeURIComponent(token)}/f/${encodeURIComponent(name)}${pwSegFile}?preserve=true`;
            let ext = name.split('.').pop().toLowerCase();
            html += `
<div class="list-group-item py-2" data-file="${name}">
  <div class="row align-items-center g-2 flex-nowrap">
    <div class="col-6 col-md-8 min-width-0">
      <span class="d-block text-truncate" title="${name}">${name}</span>
    </div>
    <div class="col-2 col-md-2 text-end text-secondary small">
      ${size ? `(${size})` : ''}
    </div>
    <div class="col-4 col-md-2 text-end d-flex justify-content-end align-items-center gap-2">
      <a href="${downloadUrl}"
         class="btn btn-outline-primary btn-sm" download title="Download">
        <i class="bi bi-download"></i>
      </a>
      <button class="btn btn-outline-secondary btn-sm preview-btn" data-url="${previewUrl}" data-ext="${ext}" title="Preview"><i class="bi bi-eye"></i></button>
      <button class="delete-btn btn btn-outline-danger btn-sm" data-file="${name}"><i class="bi bi-trash"></i></button>
    </div>
  </div>
</div>
`;
        });
        html += '</div>';
        filesBox.innerHTML = html;
        if (highlightFile) {
            document.querySelectorAll('.list-group-item[data-file]').forEach(it => {
                if (it.getAttribute('data-file') === highlightFile) {
                    it.classList.add('file-highlight');
                    it.scrollIntoView({behavior:'smooth', block:'center'});
                }
            });
        }

        // Actions
        let buttons = '';
        let zipUrl = `/action/z/${encodeURIComponent(token)}${pwSeg}`;
        if (files.length > 1) {
            buttons += `<a href="${zipUrl}" class="btn btn-success btn-sm me-2" id="zipBtn">Download all as ZIP</a>`;
            buttons += `<button id="deleteBtn" class="btn btn-danger btn-sm"><i class="bi bi-trash"></i> Delete all files</button>`;
        } else {
            buttons += `<button id="deleteBtn" class="btn btn-danger btn-sm"><i class="bi bi-trash"></i> Delete file</button>`;
        }
        actionBox.innerHTML = buttons;

        // Einzel-Datei löschen
        document.querySelectorAll('.delete-btn').forEach(btn => {
            btn.onclick = function () {
                let file = btn.getAttribute('data-file');
                if (!confirm('Delete file: ' + file + '?')) return;
                let body = `token=${encodeURIComponent(token)}&file=${encodeURIComponent(file)}`;
                if (password && password.trim() !== '') body += `&password=${encodeURIComponent(password)}`;
                fetch('/action/delete', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: body
                }).then(r => r.text())
                    .then(txt => {
                        try {
                            const data = JSON.parse(txt);
                            if (data.status === 'deleted') fetchFiles();
                            else alertBox.innerHTML = `<div class="alert alert-danger">Delete failed</div>`;
                        } catch (e) {
                            alertBox.innerHTML = `<div class="alert alert-danger">Server error:<br><pre>${txt}</pre></div>`;
                        }
                    });
            };
        });
        initPreviewButtons();
        // Alle löschen
        document.getElementById('deleteBtn').onclick = function () {
            if (!confirm('Really delete ALL files?')) return;
            let body = `token=${encodeURIComponent(token)}`;
            if (password && password.trim() !== '') body += `&password=${encodeURIComponent(password)}`;
            fetch('/action/delete', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: body
            }).then(r => r.text())
                .then(txt => {
                    try {
                        const data = JSON.parse(txt);
                        if (data.status === 'deleted') fetchFiles();
                        else alertBox.innerHTML = `<div class="alert alert-danger">Delete failed</div>`;
                    } catch (e) {
                        alertBox.innerHTML = `<div class="alert alert-danger">Server error:<br><pre>${txt}</pre></div>`;
                    }
                });
        }

    }

    fetchFiles();
</script>
</body>
</html>
