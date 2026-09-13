@php
    /** Marks print without a trailing .00, as legacy's integer columns did. */
    $mark = function (array $cell, bool $withMax = false): string {
        if ($cell['is_absent']) {
            return 'ABS';
        }
        if ($cell['score'] === null) {
            return '-';
        }
        $n = rtrim(rtrim(number_format($cell['score'], 2, '.', ''), '0'), '.');
        if ($withMax && $cell['max_marks'] !== null) {
            $n .= ' / '.rtrim(rtrim(number_format($cell['max_marks'], 2, '.', ''), '0'), '.');
        }
        return $n;
    };
    $money = fn (?float $v): ?string => $v === null ? null : 'KES '.number_format($v, 2);
    $date = fn (?string $v): ?string => $v === null ? null : \Illuminate\Support\Carbon::parse($v)->format('j M Y');

    $isTerm = $card['layout'] === 'term';
    $school = $card['school'];
    $schoolLines = array_filter([$school['address'], $school['phone'], $school['email']]);

    // Label => typed value (null prints legacy's blank fill-in line).
    $fields = [
        'Fee balance:' => $money($card['entry']['fee_balance']),
        'Next Term Feeding Amount:' => $money($card['term']['next_term_feeding_fee'] ?? null),
        'Closing Date:' => $date($card['term']['closing_date'] ?? null),
        'Opening Date:' => $date($card['term']['opening_date'] ?? null),
        'Head Teacher Signature:' => null,
        'Parents Signature:' => null,
    ];
    $rowNo = 1;
@endphp
<div class="report-page{{ $isLast ? ' report-page-last' : '' }}">
    <table class="rp-header">
        <tr>
            <td class="rp-logo">@if ($logo)<img src="{{ $logo }}" alt="">@endif</td>
            <td class="rp-school">
                <div class="rp-school-name">{{ mb_strtoupper($school['name']) }}</div>
                @foreach ($schoolLines as $line)
                    <div class="rp-school-line">{{ $line }}</div>
                @endforeach
                @if ($school['motto'])
                    <div class="rp-school-line"><em>{{ $school['motto'] }}</em></div>
                @endif
            </td>
        </tr>
    </table>

    <table class="rp-meta">
        <tr>
            <td>
                <div class="rp-label">REPORT FOR:</div>
                <div class="rp-student">{{ $card['student']['name'] }}</div>
                <div class="rp-meta-line">Adm No: {{ $card['student']['admission_no'] }}</div>
                <div class="rp-meta-line">Grade: {{ $card['class']['name'] }}</div>
                <div class="rp-meta-line">Tutor: {{ $card['class']['class_teacher'] ?? 'Not Assigned' }}</div>
            </td>
            <td class="rp-meta-right">
                <div class="rp-title">{{ $isTerm ? 'Term Performance Report' : 'Performance Report' }}</div>
                @unless ($isTerm)
                    <div class="rp-meta-line">{{ $card['exam']['name'] }}</div>
                @endunless
                @if ($card['term'])
                    <div class="rp-meta-line">{{ $card['term']['name'] }}</div>
                @endif
                <div class="rp-meta-line">Year: {{ $card['academic_year'] }}</div>
            </td>
        </tr>
    </table>

    <table class="rp-table{{ $isTerm ? ' rp-term' : '' }}">
        <thead>
            <tr>
                <th>#</th>
                <th>SUBJECTS</th>
                @if ($isTerm)
                    @foreach ($card['columns'] as $column)
                        <th class="rp-right">{{ mb_strtoupper($column['type_label']) }}</th>
                        <th class="rp-right">LEVEL</th>
                    @endforeach
                @else
                    <th class="rp-right">PERCENTAGE (%)</th>
                    <th class="rp-right">PERFORMANCE LEVEL</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach ($card['rows'] as $row)
                <tr class="{{ $row['kind'] === 'paper' ? 'rp-paper' : '' }}">
                    <td class="rp-no">{{ $row['kind'] === 'subject' ? $rowNo++ : '' }}</td>
                    <td class="rp-subject">{{ $row['name'] }}</td>
                    @foreach ($row['cells'] as $cell)
                        <td class="rp-mark">{{ $mark($cell, $row['kind'] === 'paper') }}</td>
                        <td class="rp-level">{{ $row['kind'] === 'paper' ? '—' : ($cell['band_label'] ?? '-') }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td></td>
                <td>TOTAL MARK</td>
                @foreach ($card['totals'] as $total)
                    <td class="rp-mark">{{ $total['total_marks'] === null ? '-' : rtrim(rtrim(number_format($total['total_marks'], 2, '.', ''), '0'), '.') }}</td>
                    <td class="rp-level">{{ $total['mean_band'] ?? '' }}</td>
                @endforeach
            </tr>
        </tfoot>
    </table>

    <div class="rp-remarks">
        @if (filled($card['entry']['class_teacher_remarks']))
            <div class="rp-field-label rp-has-value">Class Teacher's Remarks:</div>
            <div class="rp-remarks-text">{!! nl2br(e($card['entry']['class_teacher_remarks'])) !!}</div>
        @else
            <div class="rp-field-label">Class Teacher's Remarks:</div>
            <div class="rp-rule"></div>
            <div class="rp-rule"></div>
        @endif
    </div>

    <table class="rp-fields">
        @foreach (array_chunk($fields, 2, true) as $pair)
            <tr>
                @foreach ($pair as $label => $value)
                    <td>
                        @if ($value !== null)
                            <div class="rp-field-label rp-has-value">{{ $label }}</div>
                            <div class="rp-value">{{ $value }}</div>
                        @else
                            <div class="rp-field-label">
                                {{ $label }}
                                @if ($label === 'Head Teacher Signature:' && filled($card['head_teacher']))
                                    <span style="font-weight: normal">({{ $card['head_teacher'] }})</span>
                                @endif
                            </div>
                            <div class="rp-rule"></div>
                        @endif
                    </td>
                @endforeach
                @if (count($pair) === 1)
                    <td></td>
                @endif
            </tr>
        @endforeach
    </table>

    <div class="rp-footer">{{ $card['footer'] }}</div>
</div>
