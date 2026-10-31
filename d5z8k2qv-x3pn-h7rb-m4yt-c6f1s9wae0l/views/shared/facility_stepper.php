<?php
/**
 * Facility Booking — shared render helpers (stepper, date/time range, row payload).
 *
 * Used by the request table (compact stepper per row) and by
 * FacilityController::ajaxRequestDetail (full stepper in the detail modal), so
 * both draw the same four steps from the same FacilityRequest::stepper() data.
 * Pure functions: no queries, no role checks.
 */

if (!function_exists('fb_stepper_html')) {

    /** Icon + plain-language word per step state. Colour is never the only signal. */
    function fb_step_state_meta($state) {
        $map = [
            'done'      => ['icon' => 'bi-check-circle-fill',   'class' => 'text-success',   'word' => 'Done'],
            'current'   => ['icon' => 'bi-arrow-right-circle-fill', 'class' => 'text-purple', 'word' => 'Current step'],
            'upcoming'  => ['icon' => 'bi-circle',              'class' => 'text-secondary', 'word' => 'Upcoming'],
            'declined'  => ['icon' => 'bi-x-circle-fill',       'class' => 'text-danger',    'word' => 'Declined'],
            'clarify'   => ['icon' => 'bi-question-circle-fill','class' => 'text-warning',   'word' => 'Returned for clarification'],
            'cancelled' => ['icon' => 'bi-slash-circle-fill',   'class' => 'text-dark',      'word' => 'Cancelled here'],
            'skipped'   => ['icon' => 'bi-dash-circle',         'class' => 'text-muted',     'word' => 'Not reached'],
        ];
        return $map[$state] ?? $map['upcoming'];
    }

    /**
     * @param array $steps    FacilityRequest::stepper() output
     * @param bool  $compact  true = one-line row version for the table
     */
    function fb_stepper_html(array $steps, $compact = false) {
        if (!$steps) return '';
        $fmtWhen = function ($w) { return $w ? date('M j, g:i A', strtotime($w)) : ''; };

        if ($compact) {
            $html = '<ol class="fb-stepper fb-stepper-compact list-unstyled d-flex align-items-center mb-0" aria-label="Approval progress">';
            foreach ($steps as $i => $s) {
                $m = fb_step_state_meta($s['state']);
                $tip = ($i + 1) . '. ' . $s['label'] . ': ' . $m['word']
                     . ($s['who'] ? ' (' . $s['who'] . ', ' . $fmtWhen($s['when']) . ')' : '');
                $html .= '<li class="fb-step fb-step-' . htmlspecialchars($s['state']) . '" title="' . htmlspecialchars($tip) . '" data-bs-toggle="tooltip">'
                       . '<i class="bi ' . $m['icon'] . ' ' . $m['class'] . '" aria-hidden="true"></i>'
                       . '<span class="visually-hidden">' . htmlspecialchars($tip) . '</span></li>';
                if ($i < count($steps) - 1) $html .= '<li class="fb-step-line" aria-hidden="true"></li>';
            }
            return $html . '</ol>';
        }

        $html = '<ol class="fb-stepper fb-stepper-full list-unstyled row g-2 mb-3" aria-label="Approval progress">';
        foreach ($steps as $i => $s) {
            $m = fb_step_state_meta($s['state']);
            $html .= '<li class="col-6 col-md-3"><div class="fb-step-card fb-step-' . htmlspecialchars($s['state']) . ' h-100">'
                   . '<div class="d-flex align-items-center gap-1">'
                   . '<i class="bi ' . $m['icon'] . ' ' . $m['class'] . '" aria-hidden="true"></i>'
                   . '<span class="fw-semibold small">' . ($i + 1) . '. ' . htmlspecialchars($s['label']) . '</span></div>'
                   . '<div class="fb-step-state ' . $m['class'] . '">' . htmlspecialchars($m['word']) . '</div>';
            if ($s['who']) {
                $html .= '<div class="fb-step-who text-muted">' . htmlspecialchars($s['who'])
                       . '<br>' . htmlspecialchars($fmtWhen($s['when'])) . '</div>';
            }
            $html .= '</div></li>';
        }
        return $html . '</ol>';
    }

    /** "Sun, Nov 2 · 9:00–11:00 AM" — date and time range together. */
    function fb_when_range($date, $start, $end) {
        $date = trim((string)$date);
        if ($date === '' || strtotime($date) === false) return '';
        $out = date('D, M j', strtotime($date));
        if (date('Y', strtotime($date)) !== date('Y')) $out .= ', ' . date('Y', strtotime($date));
        $start = trim((string)$start); $end = trim((string)$end);
        if ($start === '') return $out;
        $s = strtotime('2000-01-01 ' . $start);
        if ($s === false) return $out;
        if ($end === '' || ($e = strtotime('2000-01-01 ' . $end)) === false) {
            return $out . ' · ' . date('g:i A', $s);
        }
        $time = (date('A', $s) === date('A', $e))
            ? date('g:i', $s) . '–' . date('g:i A', $e)
            : date('g:i A', $s) . '–' . date('g:i A', $e);
        return $out . ' · ' . $time;
    }

    /** The JSON the workflow modals are opened with (row buttons + detail modal). */
    function fb_row_payload(array $r, $hard, $soft) {
        return [
            'id'       => (int)$r['id'],
            'event'    => $r['event_activity'],
            'ministry' => $r['ministry'],
            'facility' => $r['facility_name'],
            'date'     => $r['date_needed'],
            'start'    => $r['start_time'],
            'end'      => $r['end_time'],
            'hard'     => (int)$hard,
            'soft'     => (int)$soft,
            'status'   => $r['status'],
            'expired'  => !empty($r['is_expired']),
        ];
    }

    /**
     * The read-time "Expired" badge (icon + text, never colour alone), shown
     * beside the request's real status. '' when the request is not expired.
     */
    function fb_expired_badge(array $r) {
        if (empty($r['is_expired'])) return '';
        $m = FacilityRequest::EXPIRED_META;
        return '<span class="badge bg-' . $m['badge'] . ' fb-expired" title="The date has passed while this request was still pending. Nothing was changed automatically.">'
             . '<i class="bi ' . $m['icon'] . ' me-1" aria-hidden="true"></i>' . htmlspecialchars($m['label']) . '</span>';
    }
}
