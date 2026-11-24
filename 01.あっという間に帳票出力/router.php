<?php
declare(strict_types=1);
require __DIR__ . '/quick-start.php';

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if ($path === '/' || $path === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    return;
}
if ($path === '/print-data') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(createPrintData(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}
if ($path === '/pdf' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    $engine = localEngine();
    if ($engine === null) {
        http_response_code(502);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'PDF の保存には Node.js か Docker が要ります（https://nodejs.org/）。'], JSON_UNESCAPED_UNICODE);
        return;
    }
    try {
        $pdf = (new \Pao\Reports\Web\ReportsWebEngine($engine))->renderPdf($body);
        header('Content-Type: application/pdf'); echo $pdf;
    } catch (Throwable $error) {
        http_response_code(502); header('Content-Type: application/json');
        echo json_encode(['error'=>'PDF engine request failed']);
    }
    return;
}
if (str_starts_with($path, '/reports.web/') || str_starts_with($path, '/demo/reports.web/')) {
    $root = reportsWebRoot();
    $prefix = str_starts_with($path, '/demo/reports.web/') ? '/demo/reports.web/' : '/reports.web/';
    $relative = substr($path, strlen($prefix));
    $candidate = $root === false ? false : realpath($root . '/' . $relative . (str_ends_with($path, '/') ? 'index.html' : ''));
    if ($candidate !== false && str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) && is_file($candidate)) {
        $types = ['html'=>'text/html; charset=utf-8','js'=>'text/javascript; charset=utf-8','mjs'=>'text/javascript; charset=utf-8','css'=>'text/css','wasm'=>'application/wasm','json'=>'application/json','svg'=>'image/svg+xml','woff2'=>'font/woff2'];
        header('Content-Type: ' . ($types[pathinfo($candidate, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        readfile($candidate);
        return;
    }
}
http_response_code(404);
echo 'not found';

// Docker を使わずに `php -S 127.0.0.1:19200 router.php` だけで動かすための準備（Docker の中では何もしない）。
// - 製品（Runtime/reports.web）は、ZIP の並びのまま ../../../Runtime/reports.web にある。
// - PDF を作るエンジンは、REPORTS_ENGINE_URL が無ければ 127.0.0.1:3107。居なければ、PC の Node.js で起動する。

function inDocker(): bool
{
    return is_file('/opt/reports/web/design/index.html');
}

function reportsWebRoot(): string|false
{
    $configured = getenv('REPORTS_WEB_ROOT');
    if ($configured !== false && $configured !== '') return realpath($configured);
    if (inDocker()) return realpath('/opt/reports/web');
    for ($dir = __DIR__; ; $dir = dirname($dir)) {
        $candidate = $dir . DIRECTORY_SEPARATOR . 'Runtime' . DIRECTORY_SEPARATOR . 'reports.web';
        if (is_file($candidate . '/design/index.html')) return realpath($candidate);
        if (dirname($dir) === $dir) return false;
    }
}

function engineAlive(string $url): bool
{
    $context = stream_context_create(['http' => ['timeout' => 1.5, 'ignore_errors' => true]]);
    $body = @file_get_contents($url . '/health', false, $context);
    return $body !== false && isset($http_response_header[0]) && str_contains($http_response_header[0], ' 200');
}

function findNode(): ?string
{
    $name = PHP_OS_FAMILY === 'Windows' ? 'node.exe' : 'node';
    foreach (explode(PATH_SEPARATOR, (string)getenv('PATH')) as $dir) {
        $candidate = rtrim(trim($dir, '"'), '/\\') . DIRECTORY_SEPARATOR . $name;
        if ($dir !== '' && is_file($candidate)) return $candidate;
    }
    return null;
}

// エンジンの宛先を返す。Node.js が無いときは null。
function localEngine(): ?string
{
    $configured = getenv('REPORTS_ENGINE_URL');
    if ($configured !== false && $configured !== '') return rtrim($configured, '/');
    if (inDocker()) return 'http://engine:3107';
    $url = 'http://127.0.0.1:3107';
    if (engineAlive($url)) return $url;
    $node = findNode();
    $root = reportsWebRoot();
    if ($node === null || $root === false) {
        error_log('PDF の保存には Node.js か Docker が要ります（https://nodejs.org/）。プレビューはこのまま使えます。');
        return null;
    }
    $server = $root . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'server.mjs';
    putenv('REPORTS_ENGINE_ROOT=' . dirname($server));
    putenv('REPORTS_ENGINE_PORT=3107');
    putenv('REPORTS_ENGINE_HOST=127.0.0.1');
    // 同じ端末で動かす（Ctrl+C や端末を閉じると一緒に止まる）。要求を待たせないよう、起動したらすぐ戻る。
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen('start "" /B ' . escapeshellarg($node) . ' --max-old-space-size=1024 ' . escapeshellarg($server) . ' >NUL 2>&1', 'r'));
    } else {
        exec(escapeshellarg($node) . ' --max-old-space-size=1024 ' . escapeshellarg($server) . ' >/dev/null 2>&1 &');
    }
    for ($i = 0; $i < 60; $i++) {
        if (engineAlive($url)) {
            error_log('PDF 用のエンジンを起動しました（127.0.0.1:3107）。');
            return $url;
        }
        usleep(500000);
    }
    error_log('PDF 用のエンジンを起動できませんでした（3107 番が使用中かもしれません）。');
    return $url;
}
