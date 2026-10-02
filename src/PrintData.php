<?php
declare(strict_types=1);

namespace Pao\Reports\Web;

final class PrintData
{
    public const FORMAT = 'Reports.net PrintData';
    public const VERSION = '1.0';
    private ?array $definition = null;
    private array $pages = [];
    private ?array $workingPage = null;

    public function setDefinition(array $definition): self
    {
        if ($this->workingPage !== null) throw new \LogicException('ページの途中では帳票定義を変更できません。pageEnd()を先に呼び出してください。');
        self::assertDefinition($definition);
        if ($this->definition !== null) {
            foreach ($this->pages as &$page) {
                if ($page['Definition'] === null) $page['Definition'] = $this->definition;
            }
            unset($page);
        }
        $this->definition = $definition;
        return $this;
    }

    public function pageStart(?array $definition = null): self
    {
        if ($this->workingPage !== null) throw new \LogicException('pageEnd()を呼び出してから次のページを開始してください。');
        if ($definition !== null) self::assertDefinition($definition);
        if ($definition === null && $this->definition === null) throw new \LogicException('帳票定義を先に設定してください。');
        $this->workingPage = ['Index' => count($this->pages) + 1, 'Definition' => $definition, 'Values' => [], 'DynamicAttributes' => []];
        return $this;
    }

    public function setValue(string $name, string|int|float|bool|null $value, int $index = 0, bool $drawing = true): self
    { return $this->addValue($name, $value, $index, null, null, $drawing); }

    public function setRepeatedValue(string $name, string|int|float|bool|null $value, int $indexX, int $indexY, bool $drawing = true): self
    { return $this->addValue($name, $value, 0, $indexX, $indexY, $drawing); }

    /** 型を明示する共通PREPEJ値設定。各言語の薄い補助ライブラリーで同じ意味を持つ。 */
    public function setTypedValue(string $name, string $type, string|int|float|bool|null $value, int $index = 0, bool $drawing = true): self
    { return $this->addValue($name, $value, $index, null, null, $drawing, self::requiredName($type)); }

    public function setImage(string $name, string $source, int $index = 0, bool $drawing = true): self
    { return $this->setTypedValue($name, 'DynamicImage', $source, $index, $drawing); }

    public function setBarcode(string $name, string $code, int $index = 0, bool $drawing = true): self
    { return $this->setTypedValue($name, 'DynamicBarcode', $code, $index, $drawing); }

    /** 値を変えず、指定した繰返し要素を描画対象にする。 */
    public function drawObject(string $name, int $index = 0, ?string $type = null): self
    { return $this->addValue($name, null, $index, null, null, true, $type); }

    public function changeAttributes(string $name, array $values, int $index = 0): self
    { return $this->changeRepeatedAttributes($name, $values, $index, null, null); }

    public function changeRepeatedAttributes(string $name, array $values, int $index = 0, ?int $indexX = null, ?int $indexY = null, ?string $type = null): self
    {
        $page =& $this->requirePage(); $normalized = [];
        foreach ($values as $key => $value) {
            if (!is_scalar($value)) throw new \InvalidArgumentException('属性値は単純値でなければなりません。');
            $normalized[(string)$key] = self::scalarText($value);
        }
        if (($indexX === null) !== ($indexY === null)) throw new \InvalidArgumentException('indexXとindexYは両方指定してください。');
        $item = ['Name' => self::requiredName($name), 'Type' => $this->resolveType($name, $type), 'Index' => self::nonNegative($index, 'index'), 'Values' => $normalized];
        if ($indexX !== null && $indexY !== null) {
            $item['IndexX'] = self::nonNegative($indexX, 'indexX');
            $item['IndexY'] = self::nonNegative($indexY, 'indexY');
        }
        $page['DynamicAttributes'][] = $item;
        return $this;
    }

    public function pageEnd(): self
    {
        if ($this->workingPage === null) throw new \LogicException('pageStart()が呼び出されていません。');
        $this->pages[] = $this->workingPage; $this->workingPage = null;
        return $this;
    }

    public function toArray(): array
    {
        if ($this->workingPage !== null) throw new \LogicException('未完了のページがあります。');
        if ($this->definition === null || $this->pages === []) throw new \LogicException('帳票定義と1ページ以上の印刷データが必要です。');
        return ['Format' => self::FORMAT, 'Version' => self::VERSION, 'Definition' => $this->definition, 'Pages' => $this->pages];
    }

    public function toJson(bool $pretty = true): string
    { return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($pretty ? JSON_PRETTY_PRINT : 0)) . "\n"; }

    public function save(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory)) throw new \RuntimeException("保存先へ書き込めません: {$directory}");
        $temporary = tempnam($directory, '.prepej-');
        if ($temporary === false) throw new \RuntimeException('一時ファイルを作成できません。');
        try {
            if (file_put_contents($temporary, $this->toJson(), LOCK_EX) === false || !rename($temporary, $path)) throw new \RuntimeException("保存できません: {$path}");
        } finally { if (is_file($temporary)) @unlink($temporary); }
    }

    private function addValue(string $name, string|int|float|bool|null $value, int $index, ?int $indexX, ?int $indexY, bool $drawing, ?string $type = null): self
    {
        $page =& $this->requirePage();
        $item = ['Name' => self::requiredName($name), 'Type' => $this->resolveType($name, $type), 'Index' => self::nonNegative($index, 'index'), 'Drawing' => $drawing, 'Value' => $value === null ? null : self::scalarText($value)];
        if ($indexX !== null || $indexY !== null) {
            if ($indexX === null || $indexY === null) throw new \InvalidArgumentException('indexXとindexYは両方指定してください。');
            $item['IndexX'] = self::nonNegative($indexX, 'indexX'); $item['IndexY'] = self::nonNegative($indexY, 'indexY');
        }
        $page['Values'][] = $item;
        return $this;
    }

    /** 型省略時は当該ページの定義を使う。画像や罫線をTextとして保存しない。 */
    private function resolveType(string $name, ?string $explicit): string
    {
        if ($explicit !== null) return self::requiredName($explicit);
        $page = $this->requirePage();
        $definition = $page['Definition'] ?? $this->definition;
        $objects = $definition['Objects'] ?? [];
        if (isset($definition['pages'])) {
            foreach ($definition['pages'] as $sourcePage) {
                foreach ($sourcePage['objects'] ?? [] as $object) $objects[] = $object;
            }
        }
        $kinds = ['Text','Line','Square','Circle','Image','Barcode'];
        $webKinds = ['text'=>'Text','line'=>'Line','rectangle'=>'Square','ellipse'=>'Circle','image'=>'Image','barcode'=>'Barcode'];
        foreach ($objects as $object) {
            if (($object['Name'] ?? $object['name'] ?? null) !== trim($name)) continue;
            $kind = $object['Kind'] ?? null;
            if (is_int($kind) || (is_string($kind) && ctype_digit($kind))) {
                if (isset($kinds[(int)$kind])) return 'Dynamic'.$kinds[(int)$kind];
            }
            $webKind = $object['kind'] ?? null;
            if (is_string($webKind) && isset($webKinds[$webKind])) return 'Dynamic'.$webKinds[$webKind];
        }
        // 未登録の名前を許す従来APIとの互換性。明示した型は変更しない。
        return 'DynamicText';
    }

    private function &requirePage(): array
    {
        if ($this->workingPage === null) throw new \LogicException('pageStart()を先に呼び出してください。');
        return $this->workingPage;
    }

    private static function assertDefinition(array $definition): void
    {
        $web = ($definition['format'] ?? null) === 'pao-reports-web' && ($definition['version'] ?? null) === 1 && is_array($definition['pages'] ?? null) && $definition['pages'] !== [];
        $portable = isset($definition['Version']) && is_array($definition['Objects'] ?? null) && in_array(strtolower((string)($definition['CoordinateUnit'] ?? 'mm')), ['mm','px'], true);
        if (!$web && !$portable) throw new \InvalidArgumentException('Reports.net帳票定義の形式が正しくありません。');
    }
    private static function requiredName(string $value): string { $value = trim($value); if ($value === '') throw new \InvalidArgumentException('項目名は必須です。'); return $value; }
    private static function nonNegative(int $value, string $name): int { if ($value < 0) throw new \InvalidArgumentException("{$name}は0以上でなければなりません。"); return $value; }
    private static function scalarText(string|int|float|bool $value): string
    {
        if (is_float($value) && !is_finite($value)) throw new \InvalidArgumentException('数値には有限値を指定してください。');
        return is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
    }
}
