<?php

http_response_code(410);
echo "upload.php wurde durch api.php ersetzt.";
exit;
// --- Settings ---
$uploadBaseDir = __DIR__ . '/uploads/';
$now = time();
$maxAge = 3 * 24 * 60 * 60; // 3 Tage

// --- Cleanup globaler Upload-Ordner (lösche NUR Token-Unterordner) ---
foreach (glob($uploadBaseDir . '*') as $entry) {
    if (is_dir($entry) && preg_match('/^[a-zA-Z0-9_\-]+$/', basename($entry))) {
        // Lösche Token-Ordner, wenn älter als $maxAge (optional, vorsichtig)
        if ($now - filemtime($entry) > $maxAge) {
            array_map('unlink', glob($entry . '/*'));
            array_map('unlink', glob($entry . '/chunks_*/*'));
            array_map('rmdir', glob($entry . '/chunks_*'));
            rmdir($entry);
        }
    }
}
if (!file_exists($uploadBaseDir)) mkdir($uploadBaseDir, 0777, true);

ini_set('display_errors', 1);
error_reporting(E_ALL);
header("Access-Control-Allow-Origin: *");

// --- Helper: Token, Logging ---
function random_token($length = 7) {
    return substr(strtr(base64_encode(random_bytes($length)), '+/', 'az'), 0, $length);
}
function get_token() {
    if (!empty($_POST['token'])) {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $_POST['token']);
    } elseif (!empty($_GET['token'])) {
        return preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['token']);
    }
    return random_token(7);
}
function log_upload($tokenDir, $msg) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '-';
    $t = date('Y-m-d H:i:s');
    file_put_contents($tokenDir . 'upload.log', "[$t][$ip][$ua] $msg\n", FILE_APPEND);
}

// --- Meta-Handler: Passwort, Expiry ---
function handleMeta($tokenDir) {
    global $now;
    $metaFile = $tokenDir . 'files.meta';
    $meta = [];
    if (file_exists($metaFile)) {
        $meta = json_decode(file_get_contents($metaFile), true) ?: [];
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['password']) || isset($_POST['expiry']))) {
        if (!empty($_POST['password'])) {
            $meta['password'] = hash('sha256', $_POST['password']);
        }
        if (isset($_POST['expiry'])) {
            $ts = $now;
            if ($_POST['expiry'] == '1h') $ts += 3600;
            elseif ($_POST['expiry'] == '12h') $ts += 43200;
            elseif ($_POST['expiry'] == '1d') $ts += 86400;
            elseif ($_POST['expiry'] == '3d') $ts += 259200;
            elseif ($_POST['expiry'] == 'never') $ts = null;
            $meta['expiry'] = $ts ? date('Y-m-d H:i', $ts) : 'never';
        }
        file_put_contents($metaFile, json_encode($meta));
    }
}

// --- UPLOAD LOGIK ---
$token = get_token();
if (!$token) die('No token provided.');

$tokenDir = $uploadBaseDir . $token . '/';
if (!file_exists($tokenDir)) mkdir($tokenDir, 0777, true);

// --- CHUNKED/RESUMABLE UPLOAD ---
if (
    ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resumableChunkNumber'])) ||
    ($_SERVER['REQUEST_METHOD'] === 'PUT' && isset($_GET['resumableChunkNumber'])) ||
    ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['resumableChunkNumber'])) // For testChunk
) {
    handleMeta($tokenDir);

    $filename = isset($_POST['resumableFilename']) ? basename($_POST['resumableFilename'])
        : (isset($_GET['resumableFilename']) ? basename($_GET['resumableFilename']) : '');
    $chunkNumber = isset($_POST['resumableChunkNumber']) ? intval($_POST['resumableChunkNumber'])
        : (isset($_GET['resumableChunkNumber']) ? intval($_GET['resumableChunkNumber']) : 1);
    $totalChunks = isset($_POST['resumableTotalChunks']) ? intval($_POST['resumableTotalChunks'])
        : (isset($_GET['resumableTotalChunks']) ? intval($_GET['resumableTotalChunks']) : 1);

    $tmpDir = $tokenDir . 'chunks_' . md5($filename) . '/';
    if (!file_exists($tmpDir)) mkdir($tmpDir, 0777, true);

    // --- TestChunk/Exist: Kein 404, sondern 204 wenn nicht da
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $chunkFile = $tmpDir . 'chunk_' . $chunkNumber;
        if (file_exists($chunkFile)) {
            http_response_code(200);
        } else {
            http_response_code(204); // NICHT gefunden, aber KEIN Fehler
        }
        exit;
    }

    // --- Save chunk
    $chunkFile = $tmpDir . 'chunk_' . $chunkNumber;
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_FILES['file']) &&
        is_uploaded_file($_FILES['file']['tmp_name'])
    ) {
        move_uploaded_file($_FILES['file']['tmp_name'], $chunkFile);
        log_upload($tokenDir, "Chunk $chunkNumber of $filename saved.");
    } else if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        $putdata = fopen("php://input", "rb");
        $out = fopen($chunkFile, "wb");
        while ($putdata && ($data = fread($putdata, 4096)) !== false) {
            fwrite($out, $data);
        }
        fclose($putdata); fclose($out);
        log_upload($tokenDir, "Chunk $chunkNumber (PUT) of $filename saved.");
    }

    // --- Check if upload is done (all chunks present)
    $done = true;
    for ($i = 1; $i <= $totalChunks; $i++) {
        if (!file_exists($tmpDir . 'chunk_' . $i)) {
            $done = false; break;
        }
    }

    // --- Assemble file if all chunks exist
    if ($done && $filename) {
        $finalFile = $tokenDir . $filename;
        if (file_exists($finalFile)) unlink($finalFile);
        $out = fopen($finalFile, "wb");
        for ($i = 1; $i <= $totalChunks; $i++) {
            $in = fopen($tmpDir . 'chunk_' . $i, "rb");
            while ($data = fread($in, 4096)) {
                fwrite($out, $data);
            }
            fclose($in);
            unlink($tmpDir . 'chunk_' . $i);
        }
        fclose($out);
        rmdir($tmpDir);

        // Map file update
        $mapfile = $tokenDir . 'files.map';
        $existing = file_exists($mapfile) ? file($mapfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $allFiles = array_merge($existing, [$filename]);
        file_put_contents($mapfile, implode("\n", $allFiles));
        log_upload($tokenDir, "File $filename merges and saved as $finalFile.");

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $downloadUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/download/' . $token;
        echo json_encode([
            "status" => "ok",
            "files" => [$filename],
            "url" => $downloadUrl,
            "token" => $token
        ]);
        exit;
    } else {
        // Not finished yet
        echo json_encode([
            "status" => "chunk_received",
            "chunkNumber" => $chunkNumber,
            "token" => $token
        ]);
        exit;
    }
}

// --- PUT-UPLOAD (Direct PUT with X-Filename) ---
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    handleMeta($tokenDir);

    $filename = '';
    if (isset($_SERVER['HTTP_X_FILENAME'])) {
        $filename = basename($_SERVER['HTTP_X_FILENAME']);
    } else {
        http_response_code(400);
        die("No filename (X-Filename header expected).");
    }
    $safeName = preg_replace('/[^a-zA-Z0-9\._-]/', '_', $filename);
    $targetPath = $tokenDir . $safeName;
    if (file_exists($targetPath)) {
        $safeName = uniqid() . '_' . $safeName;
        $targetPath = $tokenDir . $safeName;
    }

    $putdata = fopen("php://input", "rb");
    $out = fopen($targetPath, "wb");
    $size = 0;
    while ($putdata && ($data = fread($putdata, 4096)) !== false) {
        $size += strlen($data);
        fwrite($out, $data);
    }
    fclose($putdata); fclose($out);

    // Map update
    $mapfile = $tokenDir . 'files.map';
    $existing = file_exists($mapfile) ? file($mapfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $allFiles = array_merge($existing, [$safeName]);
    file_put_contents($mapfile, implode("\n", $allFiles));
    log_upload($tokenDir, "PUT-Upload of $safeName ($size Bytes).");

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $downloadUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/download/' . $token;
    echo json_encode([
        "status" => "ok",
        "files" => [$safeName],
        "size" => $size,
        "url" => $downloadUrl,
        "token" => $token
    ]);
    exit;
}

// --- Standard POST-UPLOAD (single or multi file) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['file'])) {
    handleMeta($tokenDir);

    $files = $_FILES['file'];
    $multiple = is_array($files['name']);
    $savedFiles = [];
    if ($multiple) {
        foreach ($files['name'] as $idx => $originalName) {
            $safeName = preg_replace('/[^a-zA-Z0-9\._-]/', '_', basename($originalName));
            $targetPath = $tokenDir . $safeName;
            if (file_exists($targetPath)) {
                $safeName = uniqid() . '_' . $safeName;
                $targetPath = $tokenDir . $safeName;
            }
            if ($files['error'][$idx] === UPLOAD_ERR_OK) {
                if (move_uploaded_file($files['tmp_name'][$idx], $targetPath)) {
                    $savedFiles[] = $safeName;
                    log_upload($tokenDir, "Direct upload of $safeName (multipart).");
                }
            }
        }
    } else {
        $safeName = preg_replace('/[^a-zA-Z0-9\._-]/', '_', basename($files['name']));
        $targetPath = $tokenDir . $safeName;
        if (file_exists($targetPath)) {
            $safeName = uniqid() . '_' . $safeName;
            $targetPath = $tokenDir . $safeName;
        }
        if ($files['error'] === UPLOAD_ERR_OK) {
            if (move_uploaded_file($files['tmp_name'], $targetPath)) {
                $savedFiles[] = $safeName;
                log_upload($tokenDir, "Direct upload of $safeName (single multipart).");
            }
        }
    }
    // Map update (append)
    $mapfile = $tokenDir . 'files.map';
    $existing = file_exists($mapfile) ? file($mapfile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $allFiles = array_merge($existing, $savedFiles);
    file_put_contents($mapfile, implode("\n", $allFiles));

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
    $downloadUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/download/' . $token;
    http_response_code(200);
    echo json_encode([
        "status" => "ok",
        "files" => $savedFiles,
        "url" => $downloadUrl,
        "token" => $token
    ]);
    exit;
}

// --- Fallback (wrong method etc) ---
http_response_code(405);
echo "Please upload file via POST (multipart/form-data, field: file) or PUT (curl -T ...).";
exit;
?>
