<?php
declare(strict_types=1);

namespace Pao\Reports\Web;

/**
 * Reports.Webエンジン（ghcr.io/reportsweb/engine）のクライアント。
 * 印刷データ（PREPEJ）を POST /render/pdf に送り、PDFのバイト列を受け取る。
 * PHP標準のストリーム機能だけを使うので、curl拡張は不要。
 */
final class ReportsWebEngine
{
    public const DEFAULT_URL = 'http://127.0.0.1:3107';
    public const MAX_BYTES = 33554432; // 32 MiB

    private string $url;

    /**
     * @param string|null $url 既定は環境変数 REPORTS_ENGINE_URL、なければ http://127.0.0.1:3107
     */
    public function __construct(?string $url = null, private float $timeoutSeconds = 90.0, private int $maxBytes = self::MAX_BYTES)
    {
        $url ??= (getenv('REPORTS_ENGINE_URL') ?: self::DEFAULT_URL);
        $this->url = rtrim($url, '/');
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    /** GET /health。例：['status' => 'ok', 'engine' => 'C++ WebAssembly'] */
    public function health(): array
    {
        [$status, $body] = $this->request('GET', '/health', null, []);
        if ($status !== 200) throw new ReportsWebEngineException("エンジンの状態を確認できません（HTTP {$status}）。", $status);
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * 印刷データをPDFにする。PrintData、その配列、またはJSON文字列を渡せる。
     * @param bool|null $swapPdfImage 大きな画像を一時ファイルへ逃がす（X-Reports-Swap-Pdf-Image）
     */
    public function renderPdf(PrintData|array|string $data, ?bool $swapPdfImage = null): string
    {
        $payload = match (true) {
            $data instanceof PrintData => $data->toJson(false),
            is_array($data) => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => $data,
        };
        if (strlen($payload) > self::MAX_BYTES) throw new ReportsWebEngineException('印刷データが32MiBを超えます。');
        $headers = ['Content-Type: application/json'];
        if ($swapPdfImage !== null) $headers[] = 'X-Reports-Swap-Pdf-Image: ' . ($swapPdfImage ? 'true' : 'false');
        [$status, $body] = $this->request('POST', '/render/pdf', $payload, $headers);
        if ($status !== 200) {
            $detail = json_decode($body, true)['error'] ?? trim($body);
            throw new ReportsWebEngineException("エンジンでPDFを作成できませんでした（HTTP {$status}）" . ($detail !== '' ? '：' . $detail : '。'), $status);
        }
        if (!str_starts_with($body, '%PDF-')) throw new ReportsWebEngineException('エンジンの応答がPDFではありません。', $status);
        return $body;
    }

    /** @return array{0:int,1:string} */
    private function request(string $method, string $path, ?string $body, array $headers): array
    {
        $headers[] = 'Connection: close';
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body ?? '',
            'timeout' => $this->timeoutSeconds,
            'ignore_errors' => true,
            'follow_location' => 0,
            'protocol_version' => 1.1,
        ]]);
        $http_response_header = [];
        $response = @file_get_contents($this->url . $path, false, $context, 0, $this->maxBytes + 1);
        if ($response === false || $http_response_header === []) {
            $error = error_get_last()['message'] ?? 'unknown error';
            throw new ReportsWebEngineException("Reports.Webエンジン（{$this->url}）に接続できません：{$error}");
        }
        if (strlen($response) > $this->maxBytes) throw new ReportsWebEngineException("エンジンの応答が{$this->maxBytes}バイトを超えます。");
        $status = preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m) ? (int)$m[1] : 0;
        return [$status, $response];
    }
}
