@props(['heatmapData' => [], 'year' => now()->year])

@php
    $mapped = [];
    foreach ($heatmapData as $entry) {
        if (isset($entry['date'])) {
            $mapped[$entry['date']] = $entry;
        }
    }

    $start = \Illuminate\Support\Carbon::create($year, 1, 1)->startOfWeek(\Illuminate\Support\Carbon::SUNDAY);
    $end = \Illuminate\Support\Carbon::create($year, 12, 31)->endOfWeek(\Illuminate\Support\Carbon::SATURDAY);

    $colors = [
        'present' => '#1f883d',
        'absent' => '#ebedf0',
        'late' => '#e3b341',
        'very_late' => '#fb8f44',
        'half_day' => '#bf8700',
    ];
@endphp

<div style="overflow-x: auto; border: 1px solid var(--color-border-default); border-radius: 6px; padding: 12px; background: #fff;">
    <div style="display: flex; gap: 4px; min-width: 860px;">
        @php $cursor = $start->copy(); @endphp
        @while($cursor->lte($end))
            <div style="display: flex; flex-direction: column; gap: 4px;">
                @for($i = 0; $i < 7; $i++)
                    @php
                        $dateKey = $cursor->toDateString();
                        $entry = $mapped[$dateKey] ?? null;
                        $status = $entry['status'] ?? ($cursor->isWeekend() ? 'weekend' : 'absent');
                        $color = $colors[$status] ?? '#ebedf0';
                    @endphp
                    <div title="{{ $dateKey }} - {{ $status }}"
                         style="width: 11px; height: 11px; border-radius: 2px; background: {{ $cursor->year === $year ? $color : 'transparent' }};"></div>
                    @php $cursor->addDay(); @endphp
                @endfor
            </div>
        @endwhile
    </div>
</div>
