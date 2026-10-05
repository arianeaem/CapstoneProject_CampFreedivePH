<?php

namespace App\Support\Xlsx;

/**
 * One sheet. Rows are lists of cells. A cell is a value (auto style) or [value, styleId].
 */
class XlsxSheet
{
    public array $rows = [];
    public array $widths = [];
    public ?int $freezeRow = null;
    public ?array $filter = null; // [headerRowIndex(1-based), columnCount, lastRow]

    public function __construct(public string $name)
    {
    }

    public function widths(array $widths): static
    {
        $this->widths = $widths;

        return $this;
    }

    public function row(array $cells = []): static
    {
        $this->rows[] = $cells;

        return $this;
    }

    public function blank(): static
    {
        return $this->row([]);
    }

    /** Title at the top of every export. */
    public function titleBlock(string $title, string $period, string $generated): static
    {
        return $this->row([[$title, XlsxWorkbook::TITLE]])
            ->row([[$period, XlsxWorkbook::SUBTITLE]])
            ->row([[$generated, XlsxWorkbook::SUBTITLE]])
            ->blank();
    }

    public function section(string $title, ?string $note = null): static
    {
        $this->row([[$title, XlsxWorkbook::SECTION]]);
        if ($note) {
            $this->row([[$note, XlsxWorkbook::NOTE]]);
        }

        return $this;
    }

    public function header(array $labels): static
    {
        return $this->row(array_map(fn ($l) => [$l, XlsxWorkbook::HEADER], $labels));
    }

    /** List sheet: header on row 1, frozen, with filters. */
    public function table(array $labels, iterable $rows): static
    {
        $this->header($labels);
        $headerRow = count($this->rows);
        foreach ($rows as $r) {
            $this->row($r);
        }
        $this->freezeRow = $headerRow;
        $this->filter = [$headerRow, count($labels), max($headerRow, count($this->rows))];

        return $this;
    }

    public function filterRef(bool $absolute = false): string
    {
        [$row, $cols, $last] = $this->filter;
        $d = $absolute ? '$' : '';

        return $d . 'A' . $d . $row . ':' . $d . self::col($cols - 1) . $d . $last;
    }

    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }

        return $s;
    }

    public static function esc(string $v): string
    {
        // Remove characters that are not allowed in XML
        $v = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $v) ?? '';

        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public function toXml(): string
    {
        $cols = '';
        foreach ($this->widths as $i => $w) {
            $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }

        $pane = $this->freezeRow
            ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $this->freezeRow . '" topLeftCell="A' . ($this->freezeRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            : '<sheetViews><sheetView workbookViewId="0" showGridLines="0"/></sheetViews>';

        $xml = '';
        foreach ($this->rows as $r => $cells) {
            $rowNum = $r + 1;
            $xml .= '<row r="' . $rowNum . '">';
            foreach (array_values($cells) as $c => $cell) {
                [$value, $style] = is_array($cell) ? [$cell[0], $cell[1] ?? XlsxWorkbook::TEXT] : [$cell, null];
                if ($value === null || $value === '') {
                    if ($style !== null && $style !== XlsxWorkbook::TEXT) {
                        $xml .= '<c r="' . self::col($c) . $rowNum . '" s="' . $style . '"/>';
                    }
                    continue;
                }
                $ref = self::col($c) . $rowNum;
                if ($value instanceof \DateTimeInterface) {
                    // Excel serial date
                    $serial = 25569 + ($value->getTimestamp() + $value->getOffset()) / 86400;
                    $xml .= '<c r="' . $ref . '" s="' . ($style ?? XlsxWorkbook::DATE) . '"><v>' . floor($serial) . '</v></c>';
                } elseif (is_int($value) || is_float($value)) {
                    $xml .= '<c r="' . $ref . '" s="' . ($style ?? (is_int($value) ? XlsxWorkbook::NUMBER : XlsxWorkbook::PESO)) . '"><v>' . $value . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" s="' . ($style ?? XlsxWorkbook::TEXT) . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc((string) $value) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        $filter = $this->filter ? '<autoFilter ref="' . $this->filterRef() . '"/>' : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $pane . ($cols ? '<cols>' . $cols . '</cols>' : '') . '<sheetData>' . $xml . '</sheetData>' . $filter
            . '<pageSetup orientation="landscape"/></worksheet>';
    }
}
