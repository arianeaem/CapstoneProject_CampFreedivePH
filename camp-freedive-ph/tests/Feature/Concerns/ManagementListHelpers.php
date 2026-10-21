<?php

namespace Tests\Feature\Concerns;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use ZipArchive;

/** Shared setup and XLSX reading for the management list export/print tests. */
trait ManagementListHelpers
{
    protected User $owner;
    protected User $admin;
    protected User $coach;

    protected function makeStaff(): void
    {
        $make = fn (string $role, string $name) => User::factory()->create(['role' => $role, 'status' => 'active', 'must_change_password' => false, 'name' => $name]);
        $this->owner = $make('owner', 'Olive Owner');
        $this->admin = $make('admin', 'Adam Admin');
        $this->coach = $make('coach', 'Cora Coach');
    }

    /** Raw XML of one worksheet (1 = Summary, 2 = list). */
    protected function sheetXml(TestResponse $response, int $sheet): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'Response is not a valid XLSX file');
        $xml = $zip->getFromName("xl/worksheets/sheet{$sheet}.xml");
        $zip->close();
        unlink($path);
        $this->assertNotFalse($xml, "Worksheet {$sheet} missing");

        return $xml;
    }

    /** Worksheet as rows of cell text (numbers as written). */
    protected function sheetRows(TestResponse $response, int $sheet): array
    {
        preg_match_all('/<row [^>]*>(.*?)<\/row>/s', $this->sheetXml($response, $sheet), $rows);

        return array_map(function ($row) {
            // Empty cells are not written, so place each value by its column letter
            preg_match_all('/<c r="([A-Z]+)\d+"[^>]*?(?:\/>|>(?:<is><t[^>]*>(.*?)<\/t><\/is>|<v>(.*?)<\/v>)<\/c>)/s', $row, $cells, PREG_SET_ORDER);
            $out = [];
            foreach ($cells as $c) {
                $col = 0;
                foreach (str_split($c[1]) as $ch) {
                    $col = $col * 26 + (ord($ch) - 64);
                }
                $out[$col - 1] = html_entity_decode(($c[2] ?? '') !== '' ? $c[2] : ($c[3] ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            $width = $out ? max(array_keys($out)) + 1 : 0;

            return array_map(fn ($i) => $out[$i] ?? '', range(0, max(0, $width - 1)));
        }, $rows[1]);
    }

    /** List sheet as [header, ...rows]. */
    protected function listSheet(TestResponse $response): array
    {
        return $this->sheetRows($response, 2);
    }

    protected function assertXlsxDownload(TestResponse $response, string $module): void
    {
        $response->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('content-type'));
        $this->assertStringContainsString("camp-freediveph-{$module}-", $response->headers->get('content-disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('cache-control'));
        $this->assertStringStartsWith('PK', $response->getContent());
    }
}
