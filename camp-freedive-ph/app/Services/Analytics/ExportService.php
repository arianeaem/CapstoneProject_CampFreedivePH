<?php

namespace App\Services\Analytics;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Payment;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportService
{
    /**
     * Stream CSV export depending on requested export type and date range.
     */
    public function streamCsv(string $type, array $range, bool $isOwner = true): StreamedResponse
    {
        $start = $range['start'];
        $end = $range['end'];
        $exportName = $type === 'financials' ? 'revenue' : $type;
        $filename = 'camp-freediveph-' . $exportName . '-' . $start->format('Ymd') . '-to-' . $end->format('Ymd') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($type, $start, $end, $isOwner) {
            $handle = fopen('php://output', 'w');
            // Write UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            switch ($type) {
                case 'financials':
                case 'revenue':
                    if (!$isOwner) {
                        fputcsv($handle, ['Error', 'Unauthorized. Revenue exports are restricted to Owners.']);
                        break;
                    }
                    $this->exportRevenueCsv($handle, $start, $end);
                    break;

                case 'batches':
                    $this->exportBatchesCsv($handle, $start, $end);
                    break;

                case 'divers':
                default:
                    $this->exportDiversCsv($handle, $start, $end);
                    break;
            }

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * Export the owner revenue report as labeled CSV sections.
     */
    protected function exportRevenueCsv($handle, Carbon $start, Carbon $end): void
    {
        $paidStatuses = ['completed', 'paid'];
        $payments = Payment::with('booking')
            ->whereIn('status', array_merge($paidStatuses, ['refunded', 'partially_refunded']))
            ->latest('created_at')
            ->get();
        $gross = (float) $payments->whereIn('status', $paidStatuses)->sum('amount');
        $refunds = (float) $payments->whereIn('status', ['refunded', 'partially_refunded'])->sum('amount_refunded');
        $net = max(0, $gross - $refunds);
        $durationDays = max(1, $start->diffInDays($end) + 1);
        $weeks = max(1, (int) ceil($durationDays / 7));
        $months = max(1, $start->copy()->startOfMonth()->diffInMonths($end->copy()->startOfMonth()) + 1);
        $priorDuration = max(1, $durationDays);
        $priorStart = $start->copy()->subDays($priorDuration)->startOfDay();
        $priorEnd = $start->copy()->subSecond();
        $priorGross = (float) Payment::whereIn('status', $paidStatuses)->whereBetween('created_at', [$priorStart, $priorEnd])->sum('amount');
        $priorRefunds = (float) Payment::where('status', 'refunded')->whereBetween('updated_at', [$priorStart, $priorEnd])->sum('amount_refunded');
        $priorNet = max(0, $priorGross - $priorRefunds);
        $growth = $priorNet > 0 ? (($net - $priorNet) / $priorNet) * 100 : null;

        $this->section($handle, 'Revenue Report', [
            ['Period Start', $start->toDateString()],
            ['Period End', $end->toDateString()],
            ['Gross Revenue (PHP)', number_format($gross, 2, '.', '')],
            ['Refunds (PHP)', number_format($refunds, 2, '.', '')],
            ['Net Revenue (PHP)', number_format($net, 2, '.', '')],
        ]);

        $this->section($handle, 'Monthly/Yearly Total Revenue and Growth (%)', [['Period', 'Revenue (PHP)', 'Growth vs Previous Period (%)']]);
        $monthly = $payments->whereIn('status', $paidStatuses)->groupBy(fn ($p) => $p->created_at->format('Y-m'));
        foreach ($monthly as $period => $rows) {
            $amount = (float) $rows->sum('amount');
            $previous = (float) Payment::whereIn('status', $paidStatuses)
                ->whereBetween('created_at', [Carbon::createFromFormat('Y-m', $period)->subMonth()->startOfMonth(), Carbon::createFromFormat('Y-m', $period)->subMonth()->endOfMonth()])
                ->sum('amount');
            fputcsv($handle, [$period, number_format($amount, 2, '.', ''), $previous > 0 ? round((($amount - $previous) / $previous) * 100, 1) : 'N/A']);
        }
        fputcsv($handle, ['YEAR TOTAL', number_format($gross, 2, '.', ''), $growth === null ? 'N/A' : round($growth, 1)]);

        $this->section($handle, 'Weekly/Monthly Average Revenue', [
            ['Metric', 'Amount (PHP)'],
            ['Weekly Average Revenue', number_format($gross / $weeks, 2, '.', '')],
            ['Monthly Average Revenue', number_format($gross / $months, 2, '.', '')],
        ]);
        $this->section($handle, 'Actual vs. Last Comparison', [
            ['Metric', 'Actual Period', 'Previous Equivalent Period', 'Growth (%)'],
            ['Net Revenue', number_format($net, 2, '.', ''), number_format($priorNet, 2, '.', ''), $growth === null ? 'N/A' : round($growth, 1)],
        ]);

        $carpoolFee = (float) (app(\App\Services\SystemSettingService::class)->get('addons.carpool_fee_per_head', 1200) ?? 1200);
        $boatFee = (float) (app(\App\Services\SystemSettingService::class)->get('addons.boat_dive_fee_per_head', 600) ?? 600);
        $this->section($handle, 'Individual Revenue Chart for Other Services', [
            ['Service', 'Bookings', 'Participants', 'Estimated Revenue (PHP)'],
            ['Carpool', $this->bookingCount($start, $end, ['pickup_option' => 'carpool']), $this->participantCount($start, $end, fn ($q) => $q->where('pickup_option', 'carpool')), number_format($this->participantCount($start, $end, fn ($q) => $q->where('pickup_option', 'carpool')) * $carpoolFee, 2, '.', '')],
            ['Boat Dive', $this->bookingCount($start, $end, ['boat_dive' => true]), $this->participantCount($start, $end, fn ($q) => $q->where('boat_dive', true)), number_format($this->participantCount($start, $end, fn ($q) => $q->where('boat_dive', true)) * $boatFee, 2, '.', '')],
        ]);

        $this->section($handle, 'Revenue by Class', [['Class', 'Revenue (PHP)', 'Revenue Share (%)']]);
        foreach (['discovery', 'fundive', 'refinement'] as $class) {
            $amount = (float) Payment::whereIn('status', $paidStatuses)->whereBetween('created_at', [$start, $end])->whereHas('booking', fn ($q) => $q->where('class_type', $class))->sum('amount');
            fputcsv($handle, [ucfirst($class), number_format($amount, 2, '.', ''), $gross > 0 ? round(($amount / $gross) * 100, 1) : 0]);
        }

        $this->section($handle, 'Quota/Breakeven', [
            ['Metric', 'Result'],
            ['Configured revenue quota', 'Not configured'],
            ['Configured breakeven target', 'Not configured'],
            ['Camp result', $net > 0 ? 'Camp earned revenue for this period' : 'No positive revenue recorded for this period'],
        ]);

        $this->section($handle, 'Overall Historical Transaction List for Payments and Refunds', [[
            'Payment ID', 'Date & Time', 'Booking Reference', 'Lead Guest Name', 'Class Package',
            'Payment Type', 'Amount (PHP)', 'Refunded (PHP)', 'Payment Method', 'Status', 'Reference Number',
        ]]);

        foreach ($payments as $p) {
            $booking = $p->booking;
            fputcsv($handle, [
                $p->id,
                $p->created_at->format('Y-m-d H:i:s'),
                $booking?->booking_number ?? ('#' . $p->booking_id),
                $booking?->contact_name ?? 'N/A',
                ucfirst($booking?->class_type ?? 'N/A'),
                ucwords(str_replace('_', ' ', $p->payment_type ?? 'N/A')),
                number_format($p->amount, 2, '.', ''),
                number_format($p->amount_refunded ?? 0, 2, '.', ''),
                strtoupper($p->payment_method ?? 'GCASH'),
                ucfirst($p->status),
                $p->reference_number ?? 'N/A',
            ]);
        }
    }

    protected function section($handle, string $title, array $rows): void
    {
        fputcsv($handle, [$title]);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fputcsv($handle, []);
    }

    protected function bookingCount(Carbon $start, Carbon $end, array $filters): int
    {
        return Booking::where('status', '!=', 'pending_downpayment')
            ->whereBetween('created_at', [$start, $end])
            ->where($filters)
            ->count();
    }

    protected function participantCount(Carbon $start, Carbon $end, \Closure $filter): int
    {
        return BookingParticipant::whereHas('booking', function ($q) use ($start, $end, $filter) {
            $q->where('status', '!=', 'pending_downpayment')
                ->whereBetween('created_at', [$start, $end]);
            $filter($q);
        })->count();
    }

    /**
     * Export Batches & Operational Performance CSV.
     */
    protected function exportBatchesCsv($handle, Carbon $start, Carbon $end): void
    {
        fputcsv($handle, [
            'Batch Reference',
            'Course Package',
            'Start Date',
            'End Date',
            'Status',
            'Total Booked Guests',
            'Max Slot Capacity',
            'Fill Rate (%)',
            'Assigned Coaches',
            'Coaches Count',
            'Recommended Coaches',
            'Coach Safety Ratio Compliance',
            'Weather & Safety Risk',
        ]);

        $batches = Batch::whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
            ->with(['bookings' => fn($q) => $q->where('status', '!=', 'pending_downpayment')->with('participants.assignment.coach'), 'riskAssessment'])
            ->orderBy('start_date', 'asc')
            ->get();

        $coachRatio = (int) (app(\App\Services\SystemSettingService::class)->get('camp_operations.coach_student_ratio', 4) ?? 4);

        foreach ($batches as $b) {
            $paxCount = $b->total_participants_count;
            $distinctCoaches = $b->assigned_coaches;
            $coachesCount = $distinctCoaches->count();
            $maxCap = $b->max_capacity ?: 20;
            $occupancy = $maxCap > 0 ? round(($paxCount / $maxCap) * 100, 1) : 0;
            $coachNames = $distinctCoaches->pluck('name')->implode('; ') ?: 'None';
            $requiredCoaches = $paxCount > 0 ? (int) ceil($paxCount / $coachRatio) : 0;
            $isCompliant = ($paxCount === 0 || $coachesCount >= $requiredCoaches) ? 'COMPLIANT' : 'NEEDS_COACHES';

            fputcsv($handle, [
                $b->display_name ?? ('Batch #' . $b->id),
                ucfirst($b->class_type ?? 'Discovery'),
                $b->start_date ? Carbon::parse($b->start_date)->format('Y-m-d') : 'N/A',
                $b->end_date ? Carbon::parse($b->end_date)->format('Y-m-d') : 'N/A',
                ucfirst($b->status),
                $paxCount,
                $maxCap,
                $occupancy . '%',
                $coachNames,
                $coachesCount,
                $requiredCoaches,
                $isCompliant,
                ucwords(str_replace('_', ' ', $b->risk_classification ?? $b->riskAssessment?->overall_risk_rating ?? 'Safe')),
            ]);
        }
    }

    /**
     * Export Diver Roster & Demographic CSV.
     */
    protected function exportDiversCsv($handle, Carbon $start, Carbon $end): void
    {
        fputcsv($handle, [
            'Participant ID',
            'Booking Reference',
            'Guest Name',
            'Lead Contact Name',
            'Contact Email',
            'Contact Phone',
            'Age',
            'Swimming Ability',
            'Medical Conditions / Notes',
            'Course Package',
            'Camp Start Date',
            'Camp End Date',
            'Pickup Option',
            'Pickup Hub / Location',
            'Boat Dive (Add-on)',
            'Booking Status',
            'Payment Status',
        ]);

        $participants = BookingParticipant::whereHas('booking', function ($q) use ($start, $end) {
            $q->whereBetween('created_at', [$start, $end]);
        })->with(['booking'])->latest('id')->get();

        foreach ($participants as $part) {
            $b = $part->booking;
            fputcsv($handle, [
                $part->id,
                $b?->booking_number ?? ('#' . $part->booking_id),
                $part->name ?? ($part->first_name . ' ' . $part->last_name),
                $b?->contact_name ?? 'N/A',
                $b?->contact_email ?? 'N/A',
                $b?->contact_phone ?? 'N/A',
                $part->age ?? 'N/A',
                ucwords(str_replace('_', ' ', $part->swimmer_status ?? 'N/A')),
                $part->health_condition ?: 'None',
                ucfirst($b?->class_type ?? 'N/A'),
                $b?->start_date ? Carbon::parse($b->start_date)->format('Y-m-d') : 'N/A',
                $b?->end_date ? Carbon::parse($b->end_date)->format('Y-m-d') : 'N/A',
                ucwords(str_replace('_', ' ', $b?->pickup_option ?? 'N/A')),
                $b?->pickup_location ?: 'N/A',
                $b?->boat_dive ? 'Yes (+₱600)' : 'No',
                ucwords(str_replace('_', ' ', $b?->status ?? 'N/A')),
                ucwords(str_replace('_', ' ', $b?->payment_status ?? 'N/A')),
            ]);
        }
    }
}
