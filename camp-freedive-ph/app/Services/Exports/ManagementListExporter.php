<?php

namespace App\Services\Exports;

use App\Services\AuditLogger;
use App\Support\Xlsx\XlsxWorkbook;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Excel export and printout for the Bookings, Batches and Coaches management lists.
 * The controllers pick the rows (same filters and sort as the list on screen); this only lays them out.
 */
class ManagementListExporter
{
    /** Bigger exports are refused with a message, never cut short. */
    public const MAX_EXPORT_ROWS = 10000;

    /** Printouts above this ask the user to narrow the filters or use Excel. */
    public const MAX_PRINT_ROWS = 200;

    /**
     * @param array $filters readable filter label => value (shown on the Summary sheet)
     * @param array $auditFilters non-sensitive filter key => value (written to the audit log)
     */
    public function download(Request $request, string $module, string $title, array $filters, array $auditFilters, array $columns, array $widths, Collection $rows): Response
    {
        abort_if($rows->count() > self::MAX_EXPORT_ROWS, 422, 'More than ' . number_format(self::MAX_EXPORT_ROWS) . ' records match. Narrow the filters and export again.');

        $user = $request->user();
        $now = Carbon::now('Asia/Manila');
        $book = new XlsxWorkbook();

        $summary = $book->addSheet('Summary')->widths([34, 60])->titleBlock(
            "Camp FreedivePH · {$title}",
            'Matching records: ' . number_format($rows->count()),
            'Made on ' . $now->format('M d, Y g:i A') . " by {$user->name} (" . ucfirst($user->role) . ')'
        );
        $summary->section('Filters applied')->header(['Filter', 'Value']);
        foreach ($filters as $label => $value) {
            $summary->row([$label, $value]);
        }

        $book->addSheet(ucfirst($module))->widths($widths)->table($columns, $rows);

        AuditLogger::log(
            strtoupper($module) . '_EXPORTED',
            "Exported the {$module} list to Excel ({$rows->count()} records). Filters: " . ($auditFilters ? collect($auditFilters)->map(fn ($v, $k) => "{$k}={$v}")->implode(', ') : 'none') . '.',
            $user,
            $user->name,
            $request
        );

        $filename = "camp-freediveph-{$module}-" . $now->format('Ymd-Hi') . '.xlsx';

        return response($book->toString(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * @param array $columns [label, css class] pairs
     * @param callable $row maps one record to its printed cells (only called when the printout is allowed)
     */
    public function print(string $title, bool $landscape, array $filters, array $columns, Collection $records, callable $row, string $backUrl): Response
    {
        $tooMany = $records->count() > self::MAX_PRINT_ROWS;
        $rows = $tooMany ? collect() : $records->values()->map($row);

        return response()->view('admin.lists.print', [
            'title' => $title,
            'landscape' => $landscape,
            'filters' => $filters,
            'columns' => $columns,
            'count' => $records->count(),
            'rows' => $rows,
            'tooMany' => $tooMany,
            'limit' => self::MAX_PRINT_ROWS,
            'backUrl' => $backUrl,
        ], $tooMany ? 422 : 200);
    }

    /** Audit-safe copy of the filters: values of known keys only, never the search text. */
    public static function auditFilters(Request $request, array $keys): array
    {
        return collect($keys)
            ->filter(fn ($k) => $request->filled($k))
            ->mapWithKeys(fn ($k) => [$k => $k === 'search' ? '(used)' : (string) $request->input($k)])
            ->all();
    }
}
