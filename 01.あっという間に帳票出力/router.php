<?php
declare(strict_types=1);
require __DIR__ . '/quick-start.php';

use Pao\Reports\Web\ReportsWebEngine;
use Pao\Reports\Web\ReportsWebEngineException;

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
    try {
        // paoatoffice/reports-web: POST /render/pdf to the Reports.Web engine.
        $pdf = (new ReportsWebEngine(getenv('REPORTS_ENGINE_URL') ?: 'http://engine:3107'))->renderPdf($body);
    } catch (ReportsWebEngineException $e) {
        http_response_code($e->getStatus() ?? 502);
        header('Content-Type: text/plain; charset=utf-8');
        echo $e->getMessage();
        return;
    }
    header('Content-Type: application/pdf');
    echo $pdf;
    return;
}
if (str_starts_with($path, '/reports.web/') || str_starts_with($path, '/demo/reports.web/')) {
    $root = realpath('/opt/reports/web');
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
