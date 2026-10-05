<?php

namespace App\Support\Xlsx;

/**
 * Simple .xlsx writer for the report exports (no extra library needed).
 * All exports look the same: title at the top, maroon headers, peso / % / number formats,
 * bold totals, frozen headers and filters on the list sheets.
 * The zip is built by hand, so ext-zip is not needed.
 */
class XlsxWorkbook
{
    // Cell style ids (index into cellXfs in styles())
    public const TEXT = 0;
    public const TITLE = 1;
    public const SUBTITLE = 2;
    public const SECTION = 3;
    public const HEADER = 4;
    public const PESO = 5;
    public const PERCENT = 6;
    public const NUMBER = 7;
    public const TOTAL_TEXT = 8;
    public const TOTAL_PESO = 9;
    public const TOTAL_NUMBER = 10;
    public const NOTE = 11;
    public const DATE = 12;
    public const TOTAL_PERCENT = 13;

    /** @var array<int, array{name: string, rows: array, widths: array, freeze: ?int, filter: ?array}> */
    protected array $sheets = [];

    public function addSheet(string $name): XlsxSheet
    {
        $sheet = new XlsxSheet(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $name), 0, 31));
        $this->sheets[] = $sheet;

        return $sheet;
    }

    public function toString(): string
    {
        $files = [
            '[Content_Types].xml' => $this->contentTypes(),
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRels(),
            'xl/styles.xml' => $this->styles(),
        ];
        foreach ($this->sheets as $i => $sheet) {
            $files['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $sheet->toXml();
        }

        return $this->zip($files);
    }

    protected function contentTypes(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $s) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . $sheets . '</Types>';
    }

    protected function workbook(): string
    {
        $sheets = '';
        $names = '';
        foreach ($this->sheets as $i => $s) {
            $sheets .= '<sheet name="' . XlsxSheet::esc($s->name) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
            if ($s->filter) {
                $names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . XlsxSheet::esc($s->name) . '\'!' . $s->filterRef(true) . '</definedName>';
            }
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheets . '</sheets>' . ($names ? '<definedNames>' . $names . '</definedNames>' : '') . '</workbook>';
    }

    protected function workbookRels(): string
    {
        $rels = '';
        foreach ($this->sheets as $i => $s) {
            $rels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $rels .= '<Relationship Id="rId' . (count($this->sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    }

    /** Colors: maroon #780000, light maroon #F8EAEA, grey text #6E6E73. */
    protected function styles(): string
    {
        $numFmts = '<numFmts count="3"><numFmt numFmtId="164" formatCode="&quot;₱&quot;#,##0.00"/><numFmt numFmtId="165" formatCode="0.0&quot;%&quot;"/><numFmt numFmtId="166" formatCode="mmm d, yyyy"/></numFmts>';
        $fonts = '<fonts count="7">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'                                   // 0 body
            . '<font><b/><sz val="16"/><color rgb="FF780000"/><name val="Calibri"/></font>'          // 1 title
            . '<font><sz val="10"/><color rgb="FF6E6E73"/><name val="Calibri"/></font>'              // 2 subtitle / note
            . '<font><b/><sz val="12"/><color rgb="FF780000"/><name val="Calibri"/></font>'          // 3 section
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'          // 4 header
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'                                 // 5 total
            . '<font><i/><sz val="10"/><color rgb="FF6E6E73"/><name val="Calibri"/></font>'          // 6 note
            . '</fonts>';
        $fills = '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF780000"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF8EAEA"/></patternFill></fill></fills>';
        $borders = '<borders count="2"><border/><border><top style="thin"><color rgb="FF780000"/></top></border></borders>';
        $xf = fn ($font, $fill, $fmt, $border = 0, $align = '') => '<xf numFmtId="' . $fmt . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '"'
            . ($fmt ? ' applyNumberFormat="1"' : '') . ($font ? ' applyFont="1"' : '') . ($fill ? ' applyFill="1"' : '') . ($border ? ' applyBorder="1"' : '')
            . ($align ? ' applyAlignment="1"><alignment ' . $align . '/></xf>' : '/>');
        $cellXfs = [
            self::TEXT => $xf(0, 0, 0, 0, 'vertical="top"'),
            self::TITLE => $xf(1, 0, 0),
            self::SUBTITLE => $xf(2, 0, 0),
            self::SECTION => $xf(3, 3, 0),
            self::HEADER => $xf(4, 2, 0, 0, 'vertical="center" wrapText="1"'),
            self::PESO => $xf(0, 0, 164),
            self::PERCENT => $xf(0, 0, 165),
            self::NUMBER => $xf(0, 0, 3),
            self::TOTAL_TEXT => $xf(5, 0, 0, 1),
            self::TOTAL_PESO => $xf(5, 0, 164, 1),
            self::TOTAL_NUMBER => $xf(5, 0, 3, 1),
            self::NOTE => $xf(6, 0, 0, 0, 'wrapText="0"'),
            self::DATE => $xf(0, 0, 166),
            self::TOTAL_PERCENT => $xf(5, 0, 165, 1),
        ];
        ksort($cellXfs);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $numFmts . $fonts . $fills . $borders
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($cellXfs) . '">' . implode('', $cellXfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    /** Zip without compression (reports are small). */
    protected function zip(array $files): string
    {
        $data = '';
        $central = '';
        $time = ((date('H') << 11) | (date('i') << 5) | intdiv((int) date('s'), 2)) & 0xFFFF;
        $date = (((date('Y') - 1980) << 9) | (date('n') << 5) | date('j')) & 0xFFFF;

        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $len = strlen($content);
            $offset = strlen($data);
            // Local header: version, flags (UTF-8 names), method 0 (stored), time, date, crc, sizes, name length, extra length
            $data .= "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0x0800, 0, $time, $date, $crc, $len, $len, strlen($name), 0) . $name . $content;
            $central .= "PK\x01\x02" . pack('vv', 20, 20) . pack('vv', 0x0800, 0) . pack('vv', $time, $date)
                . pack('VVV', $crc, $len, $len) . pack('vvvvv', strlen($name), 0, 0, 0, 0) . pack('V', 0) . pack('V', $offset) . $name;
        }

        return $data . $central . "PK\x05\x06" . pack('vvvv', 0, 0, count($files), count($files))
            . pack('VV', strlen($central), strlen($data)) . pack('v', 0);
    }
}
