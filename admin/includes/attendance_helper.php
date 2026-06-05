<?php

function normalize_attendance_status_code($status)
{
    $s = strtolower(trim((string) $status));
    $s = preg_replace('/\s+/', ' ', $s);

    if (in_array($s, ['present', 'p'], true)) {
        return 'P';
    }
    if (in_array($s, ['absent', 'a'], true)) {
        return 'A';
    }
    if (in_array($s, ['half day', 'half-day', 'halfday', 'hd', 'h'], true)) {
        return 'HD';
    }
    if (str_starts_with($s, 'half')) {
        return 'HD';
    }

    $upper = strtoupper($s);
    if ($upper === 'HD') {
        return 'HD';
    }
    if ($upper === 'P' || $upper === 'A') {
        return $upper;
    }

    return '';
}

function attendance_code_css_class($code)
{
    return match ($code) {
        'P' => 'att-code-p',
        'A' => 'att-code-a',
        'HD' => 'att-code-hd',
        default => 'att-code-unknown',
    };
}

function attendance_code_label($code)
{
    return match ($code) {
        'P' => 'Present',
        'A' => 'Absent',
        'HD' => 'Half day',
        default => 'Other',
    };
}

function build_attendance_date_map($result)
{
    $map = [];
    if (!$result) {
        return $map;
    }
    while ($row = $result->fetch_assoc()) {
        $map[$row['attendance_date']] = $row['status'];
    }
    return $map;
}

function count_attendance_codes(array $date_map)
{
    $counts = ['P' => 0, 'A' => 0, 'HD' => 0, 'other' => 0];
    foreach ($date_map as $status) {
        $code = normalize_attendance_status_code($status);
        if (isset($counts[$code])) {
            $counts[$code]++;
        } else {
            $counts['other']++;
        }
    }
    return $counts;
}

function employee_view_period_url($emp_id, $month, $year)
{
    return 'employee_view.php?emp_id=' . urlencode($emp_id)
        . '&month=' . (int) $month
        . '&year=' . (int) $year;
}

function get_adjacent_period($month, $year, $delta_months)
{
    $ts = mktime(0, 0, 0, (int) $month + (int) $delta_months, 1, (int) $year);
    return [(int) date('n', $ts), (int) date('Y', $ts)];
}

function render_attendance_calendar($year, $month, array $attendance_by_date, $today_day = 0, array $holidays_map = [], $editable = false, array $attendance_detail = [])
{
    $days_in_month = (int) date('t', mktime(0, 0, 0, $month, 1, $year));
    $calendar_start_dow = (int) date('w', mktime(0, 0, 0, $month, 1, $year));
    $weekdays = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];

    ob_start();
    ?>
    <div class="att-calendar att-calendar-compact">
        <div class="att-cal-weekdays">
            <?php foreach ($weekdays as $wd): ?>
                <span><?php echo $wd; ?></span>
            <?php endforeach; ?>
        </div>
        <div class="att-cal-grid">
            <?php for ($i = 0; $i < $calendar_start_dow; $i++): ?>
                <div class="att-cal-cell att-cal-cell-empty" aria-hidden="true"></div>
            <?php endfor; ?>
            <?php for ($day = 1; $day <= $days_in_month; $day++): ?>
                <?php
                $date_key = sprintf('%d-%02d-%02d', $year, $month, $day);
                $raw_status = $attendance_by_date[$date_key] ?? null;
                $code = $raw_status !== null ? normalize_attendance_status_code($raw_status) : '';
                $has_record = $raw_status !== null;
                $is_holiday = isset($holidays_map[$date_key]);
                $is_today = ($today_day > 0 && $day === $today_day);
                $cell_class = 'att-cal-cell';
                if ($editable) {
                    $cell_class .= ' att-cal-cell-clickable';
                }
                if ($is_today) {
                    $cell_class .= ' att-cal-today';
                }
                if ($is_holiday) {
                    $cell_class .= ' att-cal-holiday';
                }
                if (!$has_record) {
                    $cell_class .= ' att-cal-no-record';
                }
                $title = $has_record
                    ? $date_key . ' — ' . attendance_code_label($code) . ' (' . $raw_status . ')'
                    : ($is_holiday ? $date_key . ' — ' . ($holidays_map[$date_key]['name'] ?? 'Holiday') : $date_key . ' — No record');
                $data_attrs = '';
                if ($editable) {
                    $detail = $attendance_detail[$date_key] ?? null;
                    $leave_type = $detail['leave_type'] ?? 'CL';
                    $ot_hrs = $detail['overtime_hours'] ?? '0';
                    $data_attrs = ' data-date="' . htmlspecialchars($date_key) . '"'
                        . ' data-status="' . htmlspecialchars($raw_status ?? 'Present') . '"'
                        . ' data-leave-type="' . htmlspecialchars($leave_type) . '"'
                        . ' data-overtime="' . htmlspecialchars((string) $ot_hrs) . '"'
                        . ' role="button" tabindex="0"';
                }
                ?>
                <div class="<?php echo $cell_class; ?>" title="<?php echo htmlspecialchars($title); ?>"<?php echo $data_attrs; ?>>
                    <span class="att-cal-day-num"><?php echo $day; ?></span>
                    <?php if ($is_holiday && !$has_record): ?>
                        <span class="att-cal-code att-cal-holiday-code">HO</span>
                    <?php elseif ($has_record && $code !== ''): ?>
                        <span class="att-cal-code <?php echo attendance_code_css_class($code); ?>"><?php echo htmlspecialchars($code); ?></span>
                    <?php elseif ($has_record): ?>
                        <span class="att-cal-code att-code-unknown" title="<?php echo htmlspecialchars($raw_status); ?>">?</span>
                    <?php else: ?>
                        <span class="att-cal-code att-cal-dash">—</span>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
