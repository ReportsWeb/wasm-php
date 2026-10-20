<?php
declare(strict_types=1);
namespace Pao\Reports\Web;
use PDO;

/** PHP port of the installed .NET 10 samples; definitions and rows are not substitutes. */
final class SampleCatalog
{
    public const SAMPLES = [
        "quick-start" => "あっという間に帳票出力",
        "multiples-of-ten" => "10のサンプル",
        "postal" => "郵便番号一覧（定義切替）",
        "estimate" => "見積書（表紙＋明細）",
        "invoice" => "請求書",
        "products" => "商品大小分類（途中で小計）",
        "business-card" => "名刺",
        "design-showcase" => "デザイン機能見本",
    ];
    private const FILES = [
        "quick-start" => "quick-start.prepdj",
        "multiples-of-ten" => "multiples-of-ten.prepdj",
        "postal" => "postal-1.prepdj",
        "estimate" => "estimate.prepdj",
        "invoice" => "invoice.prepdj",
        "products" => "products.prepdj",
        "business-card" => "business-card.prepdj",
        "design-showcase" => "design-showcase.prepdj",
    ];
    public static function connectFromEnvironment(): ?PDO
    {
        $dsn = getenv("REPORTS_DATABASE_URL");
        return $dsn
            ? new PDO(
                $dsn,
                getenv("REPORTS_DATABASE_USER") ?: null,
                getenv("REPORTS_DATABASE_PASSWORD") ?: null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
            )
            : null;
    }
    public static function definition(string $sample): array
    {
        self::assertSample($sample);
        return self::loadDefinition(self::FILES[$sample]);
    }
    private static function loadDefinition(string $name): array
    {
        $path = self::resourceRoot() . "/definitions/" . $name;
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("帳票定義を読めません: {$path}");
        }
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (($value["CoordinateUnit"] ?? "mm") !== "mm") {
            throw new \RuntimeException(
                "WPF変換帳票の単位がmmではありません: {$name}",
            );
        }
        return $value;
    }
    public static function printData(string $sample, ?PDO $pdo): PrintData
    {
        self::assertSample($sample);
        return match ($sample) {
            "multiples-of-ten" => self::multiples(),
            "postal" => self::postal($pdo),
            "estimate" => self::estimate($pdo),
            "invoice" => self::invoice($pdo),
            "products" => self::products($pdo),
            default => self::simple($sample),
        };
    }
    private static function simple(string $sample): PrintData
    {
        $p = (new PrintData())
            ->setDefinition(self::definition($sample))
            ->pageStart();
        if ($sample === "quick-start") {
            $p->setValue("Text2", "Webブラウザで作った\n印刷データです。");
        }
        // 名刺は 5 段。繰返しの物体は、何も書かないと 1 段目にしか描かない（原本と同じ、D-37）。
        // 各段に定義の文字をそのまま書く。
        if ($sample === "business-card") {
            foreach (self::definition($sample)["Objects"] as $o) {
                $count = $o["RepeatCount"] ?? ($o["Repeat"] ?? 0);
                for ($i = 0; $i < $count; $i++) {
                    $p->setValue($o["Name"], $o["Text"] ?? "", $i);
                }
            }
        }
        return $p->pageEnd();
    }
    private static function multiples(): PrintData
    {
        $p = (new PrintData())->setDefinition(
            self::definition("multiples-of-ten"),
        );
        for ($page = 1; $page <= 4; $page++) {
            $p->pageStart()
                ->setValue("日付", date("Y/m/d H:i:s"))
                ->setValue("頁数", "Page - " . $page)
                ->setValue("フォントサイズ", "フォントサイズ\n 変更後")
                ->changeAttributes("フォントサイズ", ["fontSize" => 12]);
            // 2ページ目だけ、ページ上部の線「Line3」を非表示にする。空文字と drawing=false を指定する。
            if ($page === 2) {
                $p->setValue("Line3", "", 0, false);
            }
            for ($line = 0; $line < 15; $line++) {
                $i = ($page - 1) * 15 + $line + 1;
                $p->setValue("行番号", (string) $i, $line)
                    ->setValue("10倍数", (string) ($i * 10), $line)
                    ->drawObject("横線", $line); // 罫線は値を書かずに描く（"" を書くと描かない。原本と同じ、D-33）
                // 100で割り切れる値だけ、この行の文字色を青にする。
                if (($i * 10) % 100 === 0) $p->changeAttributes("10倍数", ["foreground" => "#FF0000FF"], $line);
            }
            $p->pageEnd();
        }
        return $p;
    }
    private static function postal(?PDO $pdo): PrintData
    {
        $rows = self::rows($pdo, "postal", 1);
        $first = self::loadDefinition("postal-1.prepdj");
        $second = self::loadDefinition("postal-2.prepdj");
        $p = (new PrintData())->setDefinition($first);
        foreach (array_chunk($rows, 32) as $page => $chunk) {
            $definition = $page < 5 ? $first : $second;
            $p->pageStart($definition)
                ->setValue("ページ", "Page-" . ($page + 1))
                ->setValue("日時", date("Y/m/d H:i:s"));
            foreach ($chunk as $i => $row) {
                $v = array_values($row);
                $p->setValue("郵便番号", $v[0] ?? "", $i)
                    ->setValue("市区町村", $v[1] ?? "", $i)
                    ->setValue("住所", $v[2] ?? "", $i)
                    ->drawObject("横罫線", $i);
                if ($page >= 5 && $i % 2 === 1) {
                    $p->drawObject("網掛け", $i);
                }
            }
            if ($chunk && $page < 5) {
                $v = array_values($chunk[0]);
                $p->setValue(
                    "QR",
                    trim(($v[0] ?? "") . " " . ($v[1] ?? "") . ($v[2] ?? "")),
                );
            }
            $p->pageEnd();
        }
        return $p;
    }
    private static function estimate(?PDO $pdo): PrintData
    {
        $headers = self::rows($pdo, "estimate", 1);
        $details = self::rows($pdo, "estimate", 2);
        $cover = self::loadDefinition("estimate-cover.prepdj");
        $body = self::loadDefinition("estimate.prepdj");
        $p = (new PrintData())->setDefinition($cover);
        foreach ($headers as $h) {
            $matching = array_values(
                array_filter(
                    $details,
                    fn($r) => (string) $r["見積番号"] ===
                        (string) $h["見積番号"],
                ),
            );
            $p->pageStart($cover)
                ->setValue("お客様名", $h["お客様名"])
                ->setValue("担当者名", $h["担当者名"])
                ->pageEnd()
                ->pageStart($body)
                ->setValue("見積番号", $h["見積番号"])
                ->setValue("お客様名", $h["お客様名"])
                ->setValue("担当者名", $h["担当者名"])
                ->setValue("見積日", self::japaneseDate($h["見積日"]))
                ->setValue("ヘッダ合計", "\\ " . self::number($h["合計金額"]))
                ->setValue("消費税額", self::number($h["消費税額"]))
                ->setValue("フッタ合計", self::number($h["合計金額"]));
            foreach (range(0, 6) as $i) {
                foreach (
                    [
                        "品番白",
                        "品名白",
                        "数量白",
                        "単価白",
                        "金額白",
                        "品番青",
                        "品名青",
                        "数量青",
                        "単価青",
                        "金額青",
                        // 品名青・単価青と同じ位置の帯。何も書かないと 1 行目にしか描かない（D-37）。
                        "Square5",
                        "Square10",
                    ]
                    as $name
                ) {
                    $p->drawObject($name, $i); // 白・青の帯（四角形）。"" を書くと描かない（D-33）
                }
            }
            foreach ($matching as $i => $r) {
                $p->setValue("品番", $r["品番"], $i)
                    ->setValue("品名", $r["品名"], $i)
                    ->setValue("数量", $r["数量"], $i)
                    ->setValue("単価", self::number($r["単価"]), $i)
                    ->setValue("金額", self::number($r["金額"]), $i);
            }
            $p->pageEnd();
        }
        return $p;
    }
    private static function invoice(?PDO $pdo): PrintData
    {
        $headers = self::rows($pdo, "invoice", 1);
        $details = self::rows($pdo, "invoice", 2);
        $d = self::definition("invoice");
        $p = (new PrintData())->setDefinition($d);
        $px = 96 / 25.4;
        foreach ($headers as $h) {
            $matching = array_values(
                array_filter(
                    $details,
                    fn($r) => (string) $r["請求番号"] ===
                        (string) $h["請求番号"],
                ),
            );
            $maxH = max(4, (int) self::object($d, "hLine")["Repeat"] - 1);
            $maxV = max(1, (int) self::object($d, "vLine")["Repeat"] - 1);
            $p->pageStart()
                ->setValue("txtNo", $h["請求番号"])
                ->setValue("txtCustomer", $h["お客様名"])
                ->setValue("txtDate", date("Y年n月j日"))
                ->setValue("Image1", self::imageData("kakuin.png"));
            $adjust = [-5, 44, -20, -10, -9];
            $columnX = [];
            $nextX = null;
            for ($j = 0; $j < $maxV; $j++) {
                $base = self::object($d, "field" . ($j + 1));
                $x = $nextX ?? (float) $base["X"] * $px;
                $w = ((float) $base["Width"] + $adjust[$j]) * $px;
                $columnX[] = $x;
                $nextX = $x + $w;
            }
            for ($i = 0; $i < $maxH; $i++) {
                $p->drawObject("hLine", $i)->drawObject("LineRect", $i); // 罫線・背景は値を書かずに描く（D-33）
                if ($i === 0) {
                    $p->changeAttributes(
                        "hLine",
                        ["borderWidth" => 0.5 * $px],
                        $i,
                    );
                }
                if ($i === 1) {
                    $p->changeAttributes(
                        "hLine",
                        ["strokeStyle" => "Double"],
                        $i,
                    );
                }
                $color =
                    $i === 0
                        ? "#FFFFDAB9"
                        : ($i < $maxH - 3
                            ? ($i % 2 === 1
                                ? "#FFFFFFFF"
                                : "#FF87CEFA")
                            : "#FFFFFFB4");
                $p->changeAttributes(
                    "LineRect",
                    [
                        "background" => $color,
                        "fillEnabled" => true,
                        "fillStyle" => "Solid",
                        "borderColor" => "#FFFFFFFF",
                    ],
                    $i,
                );
                for ($j = 0; $j < $maxV; $j++) {
                    if ($j < 3 && $i > count($matching)) {
                        continue;
                    }
                    $base = self::object($d, "field" . ($j + 1));
                    $attrs = [
                        "x" => $columnX[$j],
                        "width" => ((float) $base["Width"] + $adjust[$j]) * $px,
                    ];
                    $attrs["bold"] = $i === 0;
                    $attrs["fontSize"] = $i === 0 ? $base["FontSizePt"] : 12;
                    $attrs["horizontalAlignment"] =
                        $i === 0
                            ? "Center"
                            : ($j === 1
                                ? "Left"
                                : ($j === 0
                                    ? "Center"
                                    : "Right"));
                    $p->setValue("field" . ($j + 1), "", $i)->changeAttributes(
                        "field" . ($j + 1),
                        $attrs,
                        $i,
                    );
                }
            }
            for ($j = 0; $j <= $maxV; $j++) {
                $p->drawObject("vLine", $j)->changeAttributes(
                    "vLine",
                    ["x" => $j < $maxV ? $columnX[$j] : $nextX],
                    $j,
                );
                if ($j === 0 || $j === $maxV) {
                    $p->changeAttributes(
                        "vLine",
                        ["borderWidth" => 0.5 * $px],
                        $j,
                    );
                }
            }
            $headersText = ["品番", "品名", "数量", "単価", "金額"];
            foreach ($headersText as $j => $text) {
                $p->setValue("field" . ($j + 1), $text, 0);
            }
            $total = 0;
            foreach ($matching as $i => $r) {
                $amount = self::invoiceInteger($r["数量"]) * self::invoiceInteger($r["単価"]);
                if (!is_int($amount)) throw new \OverflowException('Invoice amount overflow');
                $nextTotal = $total + $amount;
                if (!is_int($nextTotal)) throw new \OverflowException('Invoice total overflow');
                $total = $nextTotal;
                $row = $i + 1;
                $p->setValue("field1", $r["品番"], $row)
                    ->setValue("field2", $r["品名"], $row)
                    ->setValue("field3", $r["数量"], $row)
                    ->setValue("field4", self::invoiceNumber($r["単価"]), $row)
                    ->setValue("field5", self::invoiceNumber($amount), $row);
            }
            $tax = $total * 0.05;
            foreach (
                [
                    [$maxH - 3, "小計", $total],
                    [$maxH - 2, "消費税", $tax],
                    [$maxH - 1, "合計", $total + $tax],
                ]
                as [$row, $label, $amount]
            ) {
                $p->setValue("field4", $label, $row)
                    ->setValue("field5", self::invoiceNumber($amount), $row)
                    ->changeAttributes(
                        "field4",
                        [
                            "fontSize" => 16,
                            "bold" => true,
                            "horizontalAlignment" => "Center",
                        ],
                        $row,
                    );
            }
            $p->setValue("txtTotal", self::invoiceNumber($total + $tax))
                ->changeAttributes(
                    "hLine",
                    ["strokeStyle" => "Double"],
                    $maxH - 3,
                )
                ->drawObject("hLine", $maxH)
                ->changeAttributes("hLine", ["borderWidth" => 0.5 * $px], $maxH)
                ->pageEnd();
        }
        return $p;
    }

    // Reports.NET invoice: ToInt64 rounds operands to even; N0 only formats the result.
    private static function invoiceValue(mixed $value): float {
        if (!is_numeric($value) || !is_finite((float)$value)) throw new \InvalidArgumentException('Invalid invoice numeric value');
        return (float)$value;
    }
    private static function invoiceInteger(mixed $value): int {
        $n = round(self::invoiceValue($value), 0, PHP_ROUND_HALF_EVEN);
        if ($n < -9223372036854775808.0 || $n >= 9223372036854775808.0) throw new \OverflowException('Invoice Int64 overflow');
        return (int)$n;
    }
    private static function invoiceNumber(mixed $value): string {
        $n = self::invoiceValue($value);
        $rounded = round($n, 0, PHP_ROUND_HALF_EVEN);
        if ($rounded < -9223372036854775808.0 || $rounded >= 9223372036854775808.0) throw new \OverflowException('Invoice display overflow');
        return ($n < 0 && $rounded == 0 ? '-' : '') . number_format($rounded, 0, '.', ',');
    }

    private static function products(?PDO $pdo): PrintData
    {
        $big = [];
        foreach (self::rows($pdo, "products", 1) as $r) {
            $big[(string) $r["大分類コード"]] = $r["大分類名称"];
        }
        $small = [];
        foreach (self::rows($pdo, "products", 2) as $r) {
            $small[$r["大分類コード"] . ":" . $r["小分類コード"]] =
                $r["小分類名称"];
        }
        $stream = [];
        $prevBig = $prevSmall = $prevBigName = $prevSmallName = null;
        $bigCount = $smallCount = 0;
        foreach (self::rows($pdo, "products", 3) as $r) {
            $bigKey = (string) $r["大分類コード"];
            $smallKey = $bigKey . ":" . $r["小分類コード"];
            $bn = $big[$bigKey] ?? "";
            $sn = $small[$smallKey] ?? "";
            if ($prevSmall !== null && $prevSmall !== $smallKey) {
                $stream[] = [
                    "大分類" => "",
                    "小分類" => "小分類({$prevSmallName})小計",
                    "品番" => $smallCount . " 冊",
                    "品名" => "",
                    "kind" => "small",
                ];
                $smallCount = 0;
            }
            if ($prevBig !== null && $prevBig !== $bigKey) {
                $stream[] = [
                    "大分類" => "",
                    "小分類" => "大分類({$prevBigName})小計",
                    "品番" => $bigCount . " 冊",
                    "品名" => "",
                    "kind" => "big",
                ];
                $bigCount = 0;
            }
            $stream[] = [
                "大分類" => $prevBig !== $bigKey ? $bn : "",
                "小分類" => $prevSmall !== $smallKey ? $sn : "",
                "品番" => $r["品番"],
                "品名" => $r["品名"],
                "kind" => "detail",
            ];
            $prevBig = $bigKey;
            $prevSmall = $smallKey;
            $prevBigName = $bn;
            $prevSmallName = $sn;
            $bigCount++;
            $smallCount++;
        }
        if ($prevSmall !== null) {
            $stream[] = [
                "大分類" => "",
                "小分類" => "小分類({$prevSmallName})小計",
                "品番" => $smallCount . " 冊",
                "品名" => "",
                "kind" => "small",
            ];
        }
        if ($prevBig !== null) {
            $stream[] = [
                "大分類" => "",
                "小分類" => "大分類({$prevBigName})小計",
                "品番" => $bigCount . " 冊",
                "品名" => "",
                "kind" => "big",
            ];
        }
        $p = (new PrintData())->setDefinition(self::definition("products"));
        foreach (array_chunk($stream, 20) as $chunk) {
            $p->pageStart();
            foreach ($chunk as $i => $r) {
                foreach (["大分類", "小分類", "品番", "品名"] as $n) {
                    $p->setValue($n, $r[$n], $i);
                }
                foreach (
                    ["枠_大分類", "枠_小分類", "枠_品番", "枠_品名"]
                    as $n
                ) {
                    $p->drawObject($n, $i); // 枠（四角形）。"" を書くと描かない（D-33）
                    if ($r["kind"] !== "detail") {
                        $p->changeAttributes(
                            $n,
                            [
                                "background" =>
                                    $r["kind"] === "small"
                                        ? "#FFFFFFE0"
                                        : "#FFFFB6C1",
                                "fillEnabled" => true,
                                "fillStyle" => "Solid",
                            ],
                            $i,
                        );
                    }
                }
            }
            $p->pageEnd();
        }
        return $p;
    }
    private static function writeAssociative(PrintData $p, array $row): void
    {
        foreach ($row as $name => $value) {
            if ($value !== null) {
                $p->setValue((string) $name, (string) $value);
            }
        }
    }
    private static function writeIndexed(
        PrintData $p,
        array $row,
        int $index,
    ): void {
        foreach ($row as $name => $value) {
            if ($value !== null) {
                $p->setValue((string) $name, (string) $value, $index);
            }
        }
    }
    private static function rows(?PDO $pdo, string $sample, int $sheet): array
    {
        if (!$pdo) {
            throw new \RuntimeException(
                "FrameworkサンプルにはPostgreSQL接続が必要です。",
            );
        }
        $q = $pdo->prepare(
            "SELECT row_data FROM reports_framework_rows WHERE sample_key=:sample AND sheet_no=:sheet ORDER BY row_no",
        );
        $q->execute(["sample" => $sample, "sheet" => $sheet]);
        $rows = array_map(
            fn($r) => json_decode(
                $r["row_data"],
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
            $q->fetchAll(),
        );
        if ($sample === "products" && $sheet === 3) {
            usort(
                $rows,
                fn($a, $b) => [$a["大分類コード"], $a["小分類コード"]] <=> [
                    $b["大分類コード"],
                    $b["小分類コード"],
                ],
            );
        }
        return $rows;
    }
    private static function object(array $definition, string $name): array
    {
        foreach ($definition["Objects"] as $o) {
            if (($o["Name"] ?? "") === $name) {
                $o["Repeat"] = $o["Repeat"] ?? ($o["RepeatCount"] ?? 0);
                return $o;
            }
        }
        throw new \RuntimeException("帳票オブジェクトがありません: {$name}");
    }
    private static function number(int|float|string $value): string
    {
        return number_format((float) $value, 0, ".", ",");
    }
    private static function japaneseDate(string $value): string
    {
        $d = new \DateTimeImmutable($value);
        return $d->format("Y年n月j日");
    }
    private static function imageData(string $name): string
    {
        $path = self::resourceRoot() . "/images/" . basename($name);
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException("画像を読めません: {$name}");
        }
        $mime = str_ends_with(strtolower($name), ".png")
            ? "image/png"
            : "image/jpeg";
        return "data:" . $mime . ";base64," . base64_encode($bytes);
    }
    private static function resourceRoot(): string
    {
        $configured = getenv("REPORTS_RESOURCE_ROOT");
        if ($configured !== false && $configured !== "") {
            return $configured;
        }
        $deployed = dirname(__DIR__) . "/resources";
        return is_dir($deployed)
            ? $deployed
            : dirname(__DIR__) . "/php/resources";
    }
    private static function assertSample(string $sample): void
    {
        if (!isset(self::SAMPLES[$sample])) {
            throw new \InvalidArgumentException(
                "存在しないサンプルです: {$sample}",
            );
        }
    }
}
