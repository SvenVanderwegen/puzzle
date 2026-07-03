Burnfront — weekly analytics digest
Window: {{ $report['window_start'] }} to {{ $report['window_end'] }} (UTC, 7 complete days).

Cohorts
- Activation (solve_complete on the first_seen day): {{ $ratio($report['activation']) }}
- Median time to first solve: {{ $minutes($report['median_ttfs_seconds']) }}
- D1 retention: {{ $ratio($report['d1']) }}
- D7 retention: {{ $ratio($report['d7']) }}
- Day-3 account conversion: {{ $ratio($report['day3_conversion']) }}

Play
@foreach ($report['weekdays'] as $day)
- {{ $day['weekday'] }} {{ $day['date'] }}: {{ $day['solves'] }} {{ $day['solves'] === 1 ? 'solve' : 'solves' }}, {{ $day['starts'] }} {{ $day['starts'] === 1 ? 'start' : 'starts' }}
@endforeach
- Hint stages per solve: {{ $report['hint_stages_per_solve'] === null ? 'n/a (no solves)' : sprintf('%.2f', $report['hint_stages_per_solve']) }}
- Share rate (share_clicked per solve_complete): {{ $ratio($report['share']) }}

Frontend errors (top {{ count($report['top_errors']) }}, this window)
@forelse ($report['top_errors'] as $error)
- {{ $error['count'] }} × {{ $error['message'] }}
@empty
- None filed.
@endforelse

— Burnfront dispatch
