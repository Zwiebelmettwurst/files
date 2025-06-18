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
<div class="container" style="max-width:540px; margin:2em auto;">
    <h2 class="mb-4 text-primary text-center">Your Uploaded Files</h2>
    <div id="alertBox"></div>
    <div id="filesBox"></div>
    <div id="actionBox" class="text-center my-3"></div>
    <div class="mt-5 text-center small text-muted">
        <a href="/<?= htmlspecialchars($token) . (empty($password) ? '' : '/' . htmlspecialchars($password)) ?>" class="link-secondary">&larr; Back to upload / add more files</a>
    </div>
</div>
<script>
    const token = "<?= htmlspecialchars($token) ?>";

    const password = "<?= htmlspecialchars($password) ?>"
    const highlightFile = "<?= htmlspecialchars($fileHighlight) ?>"
    const alertBox = document.getElementById('alertBox');
    const filesBox = document.getElementById('filesBox');
    const actionBox = document.getElementById('actionBox');
    let files = [];

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
                    alertBox.innerHTML = `<div class="alert alert-danger text-center">${data.error}</div>`;
                    filesBox.innerHTML = '';
                    actionBox.innerHTML = '';
                    return;
                }
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

    function renderFiles() {
        if (!files.length) {
            filesBox.innerHTML = `<div class="alert alert-warning text-center">No files available.</div>`;
            actionBox.innerHTML = '';
            return;
        }
        let html = '<div class="list-group mb-4">';
        const pwSeg = password && password.trim() !== '' ? '/' + encodeURIComponent(password) : '';
        files.forEach(f => {
            let name = f.name || f;
            let size = f.size ? formatBytes(f.size) : '';
            let downloadUrl = `/action/d/${encodeURIComponent(token)}/f/${encodeURIComponent(name)}${pwSeg}`;
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
        }
        buttons += `<button id="deleteBtn" class="btn btn-danger btn-sm">Delete all files</button>`;
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
