<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Basis-Konfig
$baseDir = __DIR__ . '/uploads/';
if (!file_exists($baseDir)) mkdir($baseDir, 0777, true);

// Hilfsfunktionen
function clean_token($t) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $t ?? '');
}
function log_action($dir, $msg) {
    $logfile = $dir . '/upload.log';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '-';
    $line = "[" . date('c') . "] [$ip] $msg (UA: $ua)\n";
    file_put_contents($logfile, $line, FILE_APPEND);
}

// ----------- Datei- und Token-Pfade -----------
function token_dir($token) {
    global $baseDir;
    return $baseDir . $token . '/';
}
function token_meta($token) {
    return token_dir($token) . 'meta.json';
}
function token_map($token) {
    return token_dir($token) . 'map.txt';
}
function token_chunks($token) {
    return token_dir($token) . 'chunks/';
}
function token_log($token) {
    return token_dir($token) . 'upload.log';
}

// --------- Password/Meta-Handling ----------
function hash_pw($pw) { return hash('sha256', $pw); }
function check_pw($token, $pw) {
    $metaFile = token_meta($token);
    if (!file_exists($metaFile)) return true;
    $meta = json_decode(file_get_contents($metaFile), true) ?: [];
    if (empty($meta['password'])) return true;
    return hash_pw($pw) === $meta['password'];
}
function load_meta($token) {
    $metaFile = token_meta($token);
    return file_exists($metaFile) ? (json_decode(file_get_contents($metaFile), true) ?: []) : [];
}
function save_meta($token, $meta) {
    $metaFile = token_meta($token);
    file_put_contents($metaFile, json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// --------- Expiry Check ----------
function token_expired($token) {
    $meta = load_meta($token);
    if (!empty($meta['expiry']) && $meta['expiry'] !== 'never') {
        $exp = strtotime($meta['expiry']);
        if ($exp && time() > $exp) return true;
    }
    return false;
}

// --------- Datei-Liste für ein Token ---------
function get_files($token) {
    $map = token_map($token);
    if (!file_exists($map)) return [];
    $files = file($map, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $files = array_filter($files, function ($f) use ($token) {
        return is_file(token_dir($token) . $f);
    });
    return array_values($files);
}

// --------- API: Routing ----------
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'upload') {
    // ----------- Resumable Upload + Multi/Single -----------
    $token = clean_token($_REQUEST['token'] ?? '');
    if (!$token) $token = bin2hex(random_bytes(4));
    $dir = token_dir($token);
    if (!file_exists($dir)) mkdir($dir, 0777, true);

    // Metadaten speichern/aktualisieren
    $metaFile = token_meta($token);
    $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) ?: [] : [];
    if (!empty($_REQUEST['password'])) $meta['password'] = hash_pw($_REQUEST['password']);
    if (isset($_REQUEST['expiry'])) {
        $exp = null;
        switch ($_REQUEST['expiry']) {
            case '1h': $exp = time() + 3600; break;
            case '12h': $exp = time() + 43200; break;
            case '1d': $exp = time() + 86400; break;
            case '3d': $exp = time() + 259200; break;
            case 'never': $exp = null; break;
        }
        $meta['expiry'] = $exp ? date('Y-m-d H:i', $exp) : 'never';
    }
    save_meta($token, $meta);

    // ----------- Chunked Upload (Resumable.js) -----------
    $chunkDir = token_chunks($token);
    if (!file_exists($chunkDir)) mkdir($chunkDir, 0777, true);

    // TestChunk
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['resumableChunkNumber'])) {
        $chunk = $chunkDir . 'chunk_' . intval($_GET['resumableChunkNumber']);
        if (file_exists($chunk)) http_response_code(200);
        else http_response_code(204);
        exit;
    }

    // Save Chunk
    if (
        ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resumableChunkNumber'])) ||
        ($_SERVER['REQUEST_METHOD'] === 'PUT' && isset($_GET['resumableChunkNumber']))
    ) {
        $filename = basename($_REQUEST['resumableFilename'] ?? 'unknown');
        $chunkNum = intval($_REQUEST['resumableChunkNumber'] ?? $_GET['resumableChunkNumber'] ?? 1);
        $totalChunks = intval($_REQUEST['resumableTotalChunks'] ?? $_GET['resumableTotalChunks'] ?? 1);
        $chunkFile = $chunkDir . 'chunk_' . $chunkNum;

        // POST mit FILES
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
            move_uploaded_file($_FILES['file']['tmp_name'], $chunkFile);
        } else if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
            $putdata = fopen("php://input", "rb");
            $out = fopen($chunkFile, "wb");
            while ($putdata && ($data = fread($putdata, 4096)) !== false) fwrite($out, $data);
            fclose($putdata); fclose($out);
        }

        // Fertig? Dann zusammensetzen
        $done = true;
        for ($i = 1; $i <= $totalChunks; $i++) {
            if (!file_exists($chunkDir . 'chunk_' . $i)) { $done = false; break; }
        }
        if ($done && $filename) {
            $final = $dir . $filename;
            $out = fopen($final, "wb");
            for ($i = 1; $i <= $totalChunks; $i++) {
                $in = fopen($chunkDir . 'chunk_' . $i, "rb");
                while ($data = fread($in, 4096)) fwrite($out, $data);
                fclose($in);
                unlink($chunkDir . 'chunk_' . $i);
            }
            fclose($out);
            @rmdir($chunkDir);
            // Map updaten
            $map = token_map($token);
            $existing = file_exists($map) ? file($map, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
            $allFiles = array_unique(array_merge($existing, [$filename]));
            file_put_contents($map, implode("\n", $allFiles));
            log_action($dir, "UPLOAD: $filename (resumable, $totalChunks chunks)");
            echo json_encode(['status' => 'ok', 'files' => [$filename], 'token' => $token]);
        } else {
            echo json_encode(['status' => 'chunk_received', 'token' => $token, 'chunk' => $chunkNum]);
        }
        exit;
    }

    // Normaler POST-Upload (nicht chunked)
    if (!empty($_FILES['file'])) {
        $saved = [];
        if (is_array($_FILES['file']['name'])) {
            foreach ($_FILES['file']['name'] as $i => $n) {
                $name = preg_replace('/[^a-zA-Z0-9\._-]/', '_', basename($n));
                $path = $dir . $name;
                if (move_uploaded_file($_FILES['file']['tmp_name'][$i], $path)) $saved[] = $name;
            }
        } else {
            $name = preg_replace('/[^a-zA-Z0-9\._-]/', '_', basename($_FILES['file']['name']));
            $path = $dir . $name;
            if (move_uploaded_file($_FILES['file']['tmp_name'], $path)) $saved[] = $name;
        }
        // Map updaten
        $map = token_map($token);
        $existing = file_exists($map) ? file($map, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        $allFiles = array_unique(array_merge($existing, $saved));
        file_put_contents($map, implode("\n", $allFiles));
        log_action($dir, "UPLOAD: " . implode(", ", $saved));
        echo json_encode(['status' => 'ok', 'files' => $saved, 'token' => $token]);
        exit;
    }
    http_response_code(400);
    echo json_encode(['error' => 'No upload']);
    exit;
}

// ---------- Datei-Liste (GET) -----------
if ($action === 'list') {
    $token = clean_token($_GET['token'] ?? '');
    $pw = $_GET['password'] ?? $_SERVER['HTTP_X_PASSWORD'] ?? '';
    if (!$token || !file_exists(token_dir($token))) {
        echo json_encode(['error' => 'Token not found. Create it by uploading files.']); exit;
    }
    if (token_expired($token)) {
        echo json_encode(['error' => 'Token expired']); exit;
    }
    if (!check_pw($token, $pw)) {
        echo json_encode(['error' => 'Wrong or missing password']); exit;
    }
    $files = get_files($token);
    $meta = load_meta($token);
    $out = [];
    foreach ($files as $f) {
        $path = token_dir($token) . '/' . $f;
        $ext = pathinfo($f, PATHINFO_EXTENSION);
        $out[] = [
            'name' => $f,
            'size' => file_exists($path) ? filesize($path) : null,
            'token' => $token,
            'extension' => $ext
        ];
    }
    echo json_encode(['files' => $out]);
    exit;
}

// ---------- Datei löschen (POST) -----------
if ($action === 'delete') {
    $token = clean_token($_POST['token'] ?? '');
    $file = $_POST['file'] ?? null;
    $pw = $_POST['password'] ?? $_SERVER['HTTP_X_PASSWORD'] ?? '';
    $dir = token_dir($token);
    if (!$token || !file_exists($dir)) {
        echo json_encode(['error' => 'Token not found']); exit;
    }
    if (token_expired($token)) {
        echo json_encode(['error' => 'Token expired']); exit;
    }
    if (!check_pw($token, $pw)) {
        echo json_encode(['error' => 'Wrong or missing password']); exit;
    }
    $deleted = [];
    if ($file) {
        $filePath = $dir . basename($file);
        if (file_exists($filePath)) {
            unlink($filePath);
            $deleted[] = $file;
            log_action($dir, "DELETE: $file");
        }
        // Map updaten
        $map = token_map($token);
        if (file_exists($map)) {
            $left = array_filter(file($map, FILE_IGNORE_NEW_LINES), fn($f) => $f !== $file);
            if (count($left)) file_put_contents($map, implode("\n", $left));
            else { unlink($map); $meta = token_meta($token); if (file_exists($meta)) unlink($meta); }
        }
    } else {
        // Alles löschen
        foreach (get_files($token) as $f) {
            $filePath = $dir . basename($f);
            if (file_exists($filePath)) { unlink($filePath); $deleted[] = $f; }
        }
        if (file_exists(token_map($token))) unlink(token_map($token));
        if (file_exists(token_meta($token))) unlink(token_meta($token));
        log_action($dir, "DELETE ALL FILES");
    }
    echo json_encode(['status' => 'deleted', 'deleted' => $deleted]);
    exit;
}

// ---------- Einzeldatei-Download (GET) -----------
if ($action === 'download') {
    $token = clean_token($_GET['token'] ?? '');
    $file = $_GET['file'] ?? '';
    $pw = $_GET['password'] ?? $_SERVER['HTTP_X_PASSWORD'] ?? '';
    $dir = token_dir($token);
    $filePath = $dir . basename($file);
    $preserveFile = isset($_GET['preserve']) && boolval($_GET['preserve']);
    if (!$token || !file_exists($filePath)) { http_response_code(404); die("Not found"); }
    if (token_expired($token)) { http_response_code(410); die("Expired"); }
    if (!check_pw($token, $pw)) { http_response_code(403); die("Wrong password"); }
    // Logging
    log_action($dir, "DOWNLOAD: $file");
    // Download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    // **Jetzt direkt löschen**
    if (!$preserveFile) { unlink($filePath); }

    // (Optional: Datei nach Download löschen? Oder separat per delete!)
    exit;
}

// ---------- ZIP Download (GET) -----------
if ($action === 'zip') {
    $token = clean_token($_GET['token'] ?? '');
    $pw = $_GET['password'] ?? $_SERVER['HTTP_X_PASSWORD'] ?? '';
    $dir = token_dir($token);
    if (!$token || !file_exists($dir)) { http_response_code(404); die("Not found"); }
    if (token_expired($token)) { http_response_code(410); die("Expired"); }
    if (!check_pw($token, $pw)) { http_response_code(403); die("Wrong password"); }
    $files = get_files($token);
    if (!$files) { http_response_code(404); die("No files"); }
    $zip = new ZipArchive();
    $tmpZip = tempnam(sys_get_temp_dir(), "zip_");
    $zip->open($tmpZip, ZipArchive::OVERWRITE);
    foreach ($files as $f) $zip->addFile($dir . $f, $f);
    $zip->close();
    log_action($dir, "ZIP DOWNLOAD: " . implode(", ", $files));
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="files_' . $token . '.zip"');
    header('Content-Length: ' . filesize($tmpZip));
    readfile($tmpZip);
    unlink($tmpZip);
    exit;
}

// ---------- Fallback ----------
http_response_code(400);
echo json_encode(['error' => 'Invalid or missing action']);
exit;

