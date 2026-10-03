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
        $filename = 'camp-freediveph-' . $type . '-' . $start->format('Ymd') . '-to-' . $end->format('Ymd') . '.csv';

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
                    if (!$isOwner) {
                        fputcsv($handle, ['Error', 'Unauthorized. Financial exports are restricted to Owners.']);
                        break;
                    }
                    $this->exportFinancialsCsv($handle, $start, $end);
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
     * Export Financial Transactions CSV.
     */
    protected function exportFinancialsCsv($handle, Carbon $start, Carbon $end): void
    {
        fputcsv($handle, [
            'Payment ID',
            'Date & Time',
            'Booking Reference',
            'Lead Guest Name',
            'Guest Email',
            'Guest Phone',
            'Class Package',
            'Payment Type',
            'Amount (PHP)',
            'Payment Method',
            'Status',
            'Reference Number',
        ]);

        $payments = Payment::with(['booking.participants'])
            ->whereIn('status', ['completed', 'paid', 'refunded'])
            ->whereBetween('created_at', [$start, $end])
            ->latest('created_at')
            ->get();

        foreach ($payments as $p) {
            $booking = $p->booking;
            fputcsv($handle, [
                $p->id,
                $p->created_at->format('Y-m-d H:i:s'),
                $booking?->booking_number ?? ('#' . $p->booking_id),
                $booking?->contact_name ?? 'N/A',
                $booking?->contact_email ?? 'N/A',
                $booking?->contact_phone ?? 'N/A',
                ucfirst($booking?->class_type ?? 'N/A'),
                ucwords(str_replace('_', ' ', $p->payment_type ?? 'N/A')),
                number_format($p->amount, 2, '.', ''),
                strtoupper($p->payment_method ?? 'GCASH'),
                ucfirst($p->status),
                $p->reference_number ?? 'N/A',
            ]);
        }
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
