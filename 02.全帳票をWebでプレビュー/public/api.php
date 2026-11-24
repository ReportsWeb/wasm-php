<?php
declare(strict_types=1);

require_once dirname(__DIR__) . "/vendor/autoload.php";
require_once dirname(__DIR__) . "/src/SampleCatalog.php";

use Pao\Reports\Web\SampleCatalog;
use Pao\Reports\Web\ReportsWebEngine;
use Pao\Reports\Web\ReportsWebEngineException;

header("Cache-Control: no-store");
header("X-Content-Type-Options: nosniff");

try {
    $sample = (string) ($_GET["sample"] ?? "estimate");
    $action = (string) ($_GET["action"] ?? "data");
    $scheme =
        !empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off"
            ? "https"
            : "http";
    $host = (string) ($_SERVER["HTTP_HOST"] ?? "localhost");
    $script = (string) ($_SERVER["SCRIPT_NAME"] ?? "/api.php");
    $assetBase = $scheme . "://" . $host . $script . "?action=asset&name=";
    putenv("REPORTS_PUBLIC_ASSET_BASE=" . $assetBase);
    $inlineDefinitionImages = static function (array $definition) use (
        $sample,
    ): array {
        $assets = match ($sample) {
            "estimate" => [
                "Image1" => "kakuin.png",
                "Image2" => "estimate-header.jpg",
            ],
            "invoice" => ["Image1" => "kakuin.png"],
            default => [],
        };
        if (
            !isset($definition["Objects"]) ||
            !is_array($definition["Objects"])
        ) {
            return $definition;
        }
        foreach ($definition["Objects"] as &$object) {
            $name = (string) ($object["Name"] ?? "");
            if (!isset($assets[$name])) {
                continue;
            }
            // Keep saved print data portable: include the image, not a server URL.
            $imageFile = dirname(__DIR__) . "/resources/images/" . $assets[$name];
            $bytes = file_get_contents($imageFile);
            if ($bytes === false) throw new RuntimeException("Sample image asset is missing: " . $assets[$name]);
            $object["ImagePath"] = "";
            $object["ImageDataBase64"] = base64_encode($bytes);
        }
        unset($object);
        return $definition;
    };
    if ($action === "asset") {
        $name = (string) ($_GET["name"] ?? "");
        $allowed = ["estimate-header.jpg", "kakuin.png"];
        if (!in_array($name, $allowed, true)) {
            throw new InvalidArgumentException("未登録の画像資源です。");
        }
        $resourceRoots = [
            dirname(__DIR__) . "/resources/images",
            __DIR__ . "/resources/images",
        ];
        $resourceRoot =
            array_values(array_filter($resourceRoots, "is_dir"))[0] ??
            $resourceRoots[0];
        $path = $resourceRoot . "/" . $name;
        if (!is_file($path)) {
            throw new RuntimeException("画像資源がありません。");
        }
        header(
            "Content-Type: image/" .
                (str_ends_with($name, ".jpg") ? "jpeg" : "png"),
        );
        header("Cache-Control: public, max-age=86400");
        readfile($path);
        exit();
    }
    if ($action === "catalog") {
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode(
            SampleCatalog::SAMPLES,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
        exit();
    }
    if ($action === "definition") {
        header(
            "Content-Type: application/vnd.pao.reports-definition+json; charset=utf-8",
        );
        header(
            'Content-Disposition: inline; filename="' . $sample . '.prepdj"',
        );
        echo json_encode(
            $inlineDefinitionImages(SampleCatalog::definition($sample)),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        );
        exit();
    }
    if (!in_array($action, ["data", "server-pdf"], true)) {
        throw new InvalidArgumentException("未対応の操作です。");
    }
    if ($action === "server-pdf" && $_SERVER["REQUEST_METHOD"] === "POST") {
        // Bound the raw body before JSON decoding, including chunked requests.
        $maxBodyBytes = 64 * 1024 * 1024;
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBodyBytes) {
            throw new RuntimeException('印刷データは64MiB以内にしてください。', 413);
        }
        $raw = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
        if ($raw === false) throw new RuntimeException('要求の本文を読めませんでした。', 400);
        if (strlen($raw) > $maxBodyBytes) {
            throw new RuntimeException('印刷データは64MiB以内にしてください。', 413);
        }
        $submitted = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        if (
            !is_array($submitted) ||
            !isset($submitted["Pages"]) ||
            !is_array($submitted["Pages"]) ||
            count($submitted["Pages"]) === 0 ||
            (isset($submitted["Format"]) &&
                $submitted["Format"] !== "Reports.net PrintData")
        ) {
            throw new InvalidArgumentException(
                "PREPEJ形式の印刷データではありません。",
            );
        }
        // Public image URLs are convenient in the browser, but the isolated
        // renderer must not fetch arbitrary URLs. Resolve only the sample's
        // allow-listed image endpoint and pass those bytes inline (SSRF-safe).
        $resourceRoots = [
            dirname(__DIR__) . "/resources/images",
            __DIR__ . "/resources/images",
        ];
        $resourceRoot =
            array_values(array_filter($resourceRoots, "is_dir"))[0] ??
            $resourceRoots[0];
        $inlineAssets = static function (&$value) use (
            &$inlineAssets,
            $assetBase,
            $resourceRoot,
        ): void {
            if (is_array($value)) {
                foreach ($value as &$child) {
                    $inlineAssets($child);
                }
                unset($child);
                return;
            }
            if (!is_string($value) || !str_starts_with($value, $assetBase)) {
                return;
            }
            $name = rawurldecode(substr($value, strlen($assetBase)));
            if (!in_array($name, ["estimate-header.jpg", "kakuin.png"], true)) {
                return;
            }
            $path = $resourceRoot . "/" . $name;
            $bytes = is_file($path) ? file_get_contents($path) : false;
            if ($bytes === false) {
                throw new RuntimeException(
                    "Server PDF image asset is missing: " . $name,
                );
            }
            $mime = str_ends_with(strtolower($name), ".jpg")
                ? "image/jpeg"
                : "image/png";
            $value = "data:" . $mime . ";base64," . base64_encode($bytes);
        };
        $inlineAssets($submitted);
        try {
            $pdf = (new ReportsWebEngine((string)(getenv("REPORTS_ENGINE_URL") ?: "http://127.0.0.1:3107")))->renderPdf($submitted);
        } catch (ReportsWebEngineException $error) {
            throw new RuntimeException("サーバーでPDFを作成できませんでした。", 502);
        }
        header("Content-Type: application/pdf");
        header(
            'Content-Disposition: inline; filename="' .
                preg_replace("/[^A-Za-z0-9_.-]/", "_", $sample) .
                '.pdf"',
        );
        header("X-Reports-Engine: server-wasm");
        echo $pdf;
        exit();
    }
    header(
        "Content-Type: application/vnd.pao.reports-printdata+json; charset=utf-8",
    );
    header('Content-Disposition: inline; filename="' . $sample . '.prepej"');
    $payload = json_decode(
        SampleCatalog::printData(
            $sample,
            SampleCatalog::connectFromEnvironment(),
        )->toJson(),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    if (!isset($payload["Pages"]) || !is_array($payload["Pages"])) {
        throw new RuntimeException("印刷データにページがありません。");
    }
    if (isset($payload['Definition']) && is_array($payload['Definition'])) $payload['Definition'] = $inlineDefinitionImages($payload['Definition']);
    foreach ($payload["Pages"] as &$page) {
        if (isset($page["Definition"]) && is_array($page["Definition"])) {
            $page["Definition"] = $inlineDefinitionImages(
                $page["Definition"],
            );
        }
        if ($sample === "invoice") {
            if (!isset($page["Values"]) || !is_array($page["Values"])) {
                continue;
            }
            foreach ($page["Values"] as &$value) {
                if (($value["Name"] ?? "") === "Image1") {
                    $value["Value"] = "data:image/png;base64," . base64_encode(file_get_contents(dirname(__DIR__) . "/resources/images/kakuin.png"));
                }
            }
            unset($value);
        }
    }
    unset($page);
    echo json_encode(
        $payload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
} catch (Throwable $error) {
    http_response_code($error instanceof InvalidArgumentException || $error instanceof JsonException ? 400 : (in_array($error->getCode(), [400, 413, 502, 503], true) ? $error->getCode() : 500));
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(
        ["error" => $error instanceof JsonException ? "要求の本文をJSONとして読めません。JSON形式を確認してください。" : $error->getMessage()],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
}
