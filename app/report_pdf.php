<?php
declare(strict_types=1);

/** A TrueType font embedded in a report PDF: measures text and writes only the glyphs that were used. */
final class ReportPdfFont
{
    public int $unitsPerEm;
    public array $bbox;
    public int $ascent;
    public int $descent;
    /** Glyph id => Unicode code point, for every glyph drawn. */
    public array $used = [0 => 0];
    private array $tables = [];
    private int $numGlyphs;
    private int $numMetrics;
    private array $metrics;
    private array $loca;
    private array $glyphIds = [];
    private array $segments;
    private int $rangeAt;

    public function __construct(string $path)
    {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 12 || !in_array(substr($data, 0, 4), ["\x00\x01\x00\x00", 'true'], true)) {
            throw new RuntimeException('Unsupported report font: ' . $path);
        }
        for ($index = 0, $count = self::u16($data, 4); $index < $count; $index++) {
            $record = unpack('a4tag/Nsum/Noffset/Nlength', $data, 12 + 16 * $index);
            $this->tables[$record['tag']] = substr($data, $record['offset'], $record['length']);
        }
        foreach (['head', 'hhea', 'maxp', 'hmtx', 'loca', 'glyf', 'cmap'] as $tag) {
            if (!isset($this->tables[$tag])) { throw new RuntimeException('Unsupported report font: ' . $path); }
        }
        $head = $this->tables['head'];
        $this->unitsPerEm = self::u16($head, 18);
        $this->bbox = [self::s16($head, 36), self::s16($head, 38), self::s16($head, 40), self::s16($head, 42)];
        $this->ascent = self::s16($this->tables['hhea'], 4);
        $this->descent = self::s16($this->tables['hhea'], 6);
        $this->numMetrics = self::u16($this->tables['hhea'], 34);
        $this->numGlyphs = self::u16($this->tables['maxp'], 4);
        $this->metrics = array_values(unpack('n*', substr($this->tables['hmtx'], 0, $this->numMetrics * 4)));
        $this->loca = self::u16($head, 50) === 1
            ? array_values(unpack('N*', $this->tables['loca']))
            : array_map(fn ($offset) => $offset * 2, array_values(unpack('n*', $this->tables['loca'])));
        // Use the Unicode BMP character map (format 4), preferring the Windows one.
        $cmap = $this->tables['cmap'];
        $subtable = null;
        for ($index = 0, $count = self::u16($cmap, 2); $index < $count; $index++) {
            $platform = self::u16($cmap, 4 + 8 * $index);
            $offset = unpack('N', $cmap, 8 + 8 * $index)[1];
            $unicode = $platform === 0 || ($platform === 3 && self::u16($cmap, 6 + 8 * $index) === 1);
            if ($unicode && self::u16($cmap, $offset) === 4 && ($subtable === null || $platform === 3)) { $subtable = $offset; }
        }
        if ($subtable === null) { throw new RuntimeException('Unsupported report font: ' . $path); }
        $size = self::u16($cmap, $subtable + 6);
        $read = fn (int $at) => array_values(unpack('n*', substr($cmap, $at, $size)));
        $this->rangeAt = $subtable + 16 + 3 * $size;
        $this->segments = [$read($subtable + 14), $read($subtable + 16 + $size), $read($subtable + 16 + 2 * $size), $read($this->rangeAt)];
    }

    private static function u16(string $data, int $at): int
    {
        return unpack('n', $data, $at)[1];
    }

    private static function s16(string $data, int $at): int
    {
        $value = self::u16($data, $at);
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    private function glyph(string $char): int
    {
        if (isset($this->glyphIds[$char])) { return $this->glyphIds[$char]; }
        $code = mb_ord($char, 'UTF-8');
        $glyph = 0;
        [$ends, $starts, $deltas, $ranges] = $this->segments;
        foreach ($ends as $index => $end) {
            if ($code === false || $code > $end) { continue; }
            if ($code >= $starts[$index]) {
                if ($ranges[$index] === 0) {
                    $glyph = ($code + $deltas[$index]) & 0xFFFF;
                } else {
                    $at = $this->rangeAt + 2 * $index + $ranges[$index] + 2 * ($code - $starts[$index]);
                    $mapped = $at + 2 <= strlen($this->tables['cmap']) ? self::u16($this->tables['cmap'], $at) : 0;
                    $glyph = $mapped === 0 ? 0 : ($mapped + $deltas[$index]) & 0xFFFF;
                }
            }
            break;
        }
        if ($glyph >= $this->numGlyphs) { $glyph = 0; }
        return $this->glyphIds[$char] = $glyph;
    }

    private function advance(int $glyph): int
    {
        return $this->metrics[2 * min($glyph, $this->numMetrics - 1)];
    }

    public function width(string $text, float $size): float
    {
        $width = 0;
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) { $width += $this->advance($this->glyph($char)); }
        return $width * $size / $this->unitsPerEm;
    }

    /** Returns the text as hexadecimal glyph ids and remembers the glyphs for embedding. */
    public function encode(string $text): string
    {
        $hex = '';
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            $glyph = $this->glyph($char);
            $this->used[$glyph] ??= (int) mb_ord($char, 'UTF-8');
            $hex .= sprintf('%04X', $glyph);
        }
        return $hex;
    }

    public function scaled(int $value): int
    {
        return (int) round($value * 1000 / $this->unitsPerEm);
    }

    public function glyphWidths(): string
    {
        ksort($this->used);
        $widths = '';
        foreach ($this->used as $glyph => $code) { $widths .= $glyph . ' [' . $this->scaled($this->advance($glyph)) . '] '; }
        return $widths;
    }

    private function outline(int $glyph): string
    {
        return $glyph < $this->numGlyphs ? substr($this->tables['glyf'], $this->loca[$glyph], $this->loca[$glyph + 1] - $this->loca[$glyph]) : '';
    }

    private static function checksum(string $data): int
    {
        $sum = 0;
        foreach (unpack('N*', $data) as $value) { $sum = ($sum + $value) & 0xFFFFFFFF; }
        return $sum;
    }

    /** Builds a font file holding only the used glyph outlines, so the PDF stays small. */
    public function subset(): string
    {
        $keep = array_fill_keys(array_keys($this->used), true);
        $queue = array_keys($keep);
        while ($queue) {
            // Accented letters are often composites that reference other glyphs.
            $outline = $this->outline(array_pop($queue));
            if (strlen($outline) < 10 || self::s16($outline, 0) >= 0) { continue; }
            $at = 10;
            do {
                $flags = self::u16($outline, $at);
                $component = self::u16($outline, $at + 2);
                if (!isset($keep[$component])) { $keep[$component] = true; $queue[] = $component; }
                $at += 4 + ($flags & 1 ? 4 : 2) + ($flags & 8 ? 2 : ($flags & 0x40 ? 4 : ($flags & 0x80 ? 8 : 0)));
            } while ($flags & 0x20 && $at + 4 <= strlen($outline));
        }
        $glyf = '';
        $loca = '';
        for ($glyph = 0; $glyph < $this->numGlyphs; $glyph++) {
            $loca .= pack('N', strlen($glyf));
            if (isset($keep[$glyph])) {
                $outline = $this->outline($glyph);
                $glyf .= $outline . str_repeat("\0", (4 - strlen($outline) % 4) % 4);
            }
        }
        $loca .= pack('N', strlen($glyf));
        // Clear the file checksum and switch to the long glyph offsets written above.
        $head = substr_replace(substr_replace($this->tables['head'], "\0\0\0\0", 8, 4), "\0\1", 50, 2);
        $tables = ['head' => $head, 'hhea' => $this->tables['hhea'], 'maxp' => $this->tables['maxp'], 'hmtx' => $this->tables['hmtx'],
            'loca' => $loca, 'glyf' => $glyf, 'post' => pack('N', 0x00030000) . str_repeat("\0", 28)];
        foreach (['OS/2', 'cvt ', 'fpgm', 'prep'] as $tag) {
            if (isset($this->tables[$tag])) { $tables[$tag] = $this->tables[$tag]; }
        }
        ksort($tables, SORT_STRING);
        $count = count($tables);
        $selector = (int) floor(log($count, 2));
        $offset = 12 + 16 * $count;
        $directory = '';
        $body = '';
        $headAt = 0;
        foreach ($tables as $tag => $table) {
            $padded = $table . str_repeat("\0", (4 - strlen($table) % 4) % 4);
            if ($tag === 'head') { $headAt = $offset; }
            $directory .= $tag . pack('NNN', self::checksum($padded), $offset, strlen($table));
            $body .= $padded;
            $offset += strlen($padded);
        }
        $font = pack('Nnnnn', 0x00010000, $count, 2 ** $selector * 16, $selector, $count * 16 - 2 ** $selector * 16) . $directory . $body;
        return substr_replace($font, pack('N', (0xB1B0AFBA - self::checksum($font)) & 0xFFFFFFFF), $headAt + 8, 4);
    }
}

/**
 * Minimal PDF writer for server-side reports: Unicode text in embedded TrueType fonts, lines and JPEG images.
 * Coordinates are in points from the top-left corner of the page.
 */
final class ReportPdf
{
    private array $pages = [];
    private array $fonts = [];
    private array $images = [];

    public function __construct(private float $width, private float $height, private string $title = '', private string $author = '')
    {
    }

    public function addFont(string $name, string $path): void
    {
        $this->fonts[$name] = new ReportPdfFont($path);
    }

    public function addPage(): void
    {
        $this->pages[] = '';
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function textWidth(string $text, string $font, float $size): float
    {
        return $this->fonts[$font]->width($text, $size);
    }

    /** Distance from the top of a line to its baseline. */
    public function ascent(string $font, float $size): float
    {
        return $this->fonts[$font]->ascent * $size / $this->fonts[$font]->unitsPerEm;
    }

    /** Splits text into lines no wider than $width; line breaks in the text are kept. */
    public function wrap(string $text, string $font, float $size, float $width): array
    {
        $lines = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", mb_scrub($text, 'UTF-8'))) as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if ($this->textWidth($candidate, $font, $size) <= $width) { $line = $candidate; continue; }
                if ($line !== '') { $lines[] = $line; $line = ''; }
                // Break words that are wider than the available width.
                foreach (mb_str_split($word, 1, 'UTF-8') as $char) {
                    if ($line !== '' && $this->textWidth($line . $char, $font, $size) > $width) { $lines[] = $line; $line = ''; }
                    $line .= $char;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    public function text(string $text, string $font, float $size, float $x, float $baseline): void
    {
        if ($text === '') { return; }
        $this->pages[count($this->pages) - 1] .= sprintf("BT /F%d %.2F Tf %.2F %.2F Td <%s> Tj ET\n",
            array_search($font, array_keys($this->fonts), true) + 1, $size, $x, $this->height - $baseline, $this->fonts[$font]->encode(mb_scrub($text, 'UTF-8')));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $thickness): void
    {
        $this->pages[count($this->pages) - 1] .= sprintf("%.2F w %.2F %.2F m %.2F %.2F l S\n", $thickness, $x1, $this->height - $y1, $x2, $this->height - $y2);
    }

    /** Draws a JPEG file; other image types are skipped. */
    public function jpeg(string $path, float $x, float $y, float $width, float $height): void
    {
        $info = is_file($path) ? @getimagesize($path) : false;
        if (!$info || $info[2] !== IMAGETYPE_JPEG) { return; }
        $this->images[$path] ??= ['data' => file_get_contents($path), 'width' => $info[0], 'height' => $info[1],
            'colors' => [1 => '/DeviceGray', 3 => '/DeviceRGB', 4 => '/DeviceCMYK /Decode [1 0 1 0 1 0 1 0]'][$info['channels'] ?? 3] ?? '/DeviceRGB'];
        $this->pages[count($this->pages) - 1] .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im%d Do Q\n",
            $width, $height, $x, $this->height - $y - $height, array_search($path, array_keys($this->images), true) + 1);
    }

    public function output(): string
    {
        $objects = [1 => '', 2 => ''];
        $add = function (string $body) use (&$objects): int { $objects[] = $body; return count($objects); };
        $stream = function (string $data, string $dictionary = '') use ($add): int {
            if (function_exists('gzcompress')) { $data = gzcompress($data); $dictionary .= ' /Filter /FlateDecode'; }
            return $add('<<' . $dictionary . ' /Length ' . strlen($data) . " >>\nstream\n" . $data . "\nendstream");
        };
        $text = fn (string $value) => '(' . strtr($value, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ')';

        $fontRefs = '';
        foreach (array_values($this->fonts) as $index => $font) {
            $name = '/' . strtr(substr(md5(implode(',', array_keys($font->used))), 0, 6), '0123456789abcdef', 'ABCDEFGHIJKLMNOP') . '+ReportFont' . ($index + 1);
            $subset = $font->subset();
            $file = $stream($subset, ' /Length1 ' . strlen($subset));
            $descriptor = $add('<< /Type /FontDescriptor /FontName ' . $name . ' /Flags 4 /FontBBox [' . implode(' ', array_map([$font, 'scaled'], $font->bbox))
                . '] /ItalicAngle 0 /Ascent ' . $font->scaled($font->ascent) . ' /Descent ' . $font->scaled($font->descent) . ' /CapHeight ' . $font->scaled($font->ascent)
                . ' /StemV 80 /FontFile2 ' . $file . ' 0 R >>');
            $descendant = $add('<< /Type /Font /Subtype /CIDFontType2 /BaseFont ' . $name . ' /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >>'
                . ' /FontDescriptor ' . $descriptor . ' 0 R /DW 1000 /W [ ' . $font->glyphWidths() . '] /CIDToGIDMap /Identity >>');
            // Lets readers copy and search the text.
            $map = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
                . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n";
            foreach (array_chunk($font->used, 100, true) as $chunk) {
                $map .= count($chunk) . " beginbfchar\n";
                foreach ($chunk as $glyph => $code) { $map .= sprintf("<%04X> <%s>\n", $glyph, strtoupper(bin2hex(mb_convert_encoding(mb_chr($code, 'UTF-8'), 'UTF-16BE', 'UTF-8')))); }
                $map .= "endbfchar\n";
            }
            $map .= "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
            $fontRefs .= '/F' . ($index + 1) . ' ' . $add('<< /Type /Font /Subtype /Type0 /BaseFont ' . $name . ' /Encoding /Identity-H /DescendantFonts [' . $descendant
                . ' 0 R] /ToUnicode ' . $stream($map) . ' 0 R >>') . ' 0 R ';
        }
        $imageRefs = '';
        foreach (array_values($this->images) as $index => $image) {
            $imageRefs .= '/Im' . ($index + 1) . ' ' . $add('<< /Type /XObject /Subtype /Image /Width ' . $image['width'] . ' /Height ' . $image['height'] . ' /ColorSpace '
                . $image['colors'] . ' /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($image['data']) . " >>\nstream\n" . $image['data'] . "\nendstream") . ' 0 R ';
        }
        $kids = '';
        foreach ($this->pages as $content) {
            $kids .= $add('<< /Type /Page /Parent 2 0 R /Contents ' . $stream($content) . ' 0 R >>') . ' 0 R ';
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [ ' . $kids . '] /Count ' . count($this->pages) . sprintf(' /MediaBox [0 0 %.2F %.2F]', $this->width, $this->height)
            . ' /Resources << /Font << ' . $fontRefs . '>> /XObject << ' . $imageRefs . '>> >> >>';
        $info = $add('<< /Title ' . $text($this->title) . ' /Author ' . $text($this->author) . ' /CreationDate (D:' . date('YmdHis') . ') >>');

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach ($offsets as $offset) { $pdf .= sprintf("%010d 00000 n \n", $offset); }
        return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . ' /Root 1 0 R /Info ' . $info . " 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    }
}
