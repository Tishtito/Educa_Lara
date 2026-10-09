{{--
    The "Formal" report card. Ported from design/report-cards/formal/; the
    region-to-payload map is in design/report-cards/README.md. Prints exactly
    what the Classic card prints, from the same payload — only the look differs.
    Every class is under the rf- prefix so its stylesheet can share a document
    with another template's. A value not filled in is an empty box here, where
    the other templates print a line.
--}}
@php
    // The same helpers as the Classic card: a template changes how a card
    // looks, never how a value is written.

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

    // Label => typed value (null prints a blank to fill in by hand).
    $facts = [
        'Fee balance' => $money($card['entry']['fee_balance']),
        'Next Term Feeding Amount' => $money($card['term']['next_term_feeding_fee'] ?? null),
        'Closing Date' => $date($card['term']['closing_date'] ?? null),
        'Opening Date' => $date($card['term']['opening_date'] ?? null),
    ];
    $rowNo = 1;
@endphp
<div class="rf-page{{ $isLast ? ' rf-page-last' : '' }}">
<div class="rf-frame">
    <table class="rf-header">
        <tr>
            <td class="rf-logo">@if ($logo)<img src="{{ $logo }}" alt="">@endif</td>
            <td class="rf-school">
                <div class="rf-school-name">{{ mb_strtoupper($school['name']) }}</div>
                @foreach ($schoolLines as $line)
                    <div class="rf-school-line">{{ $line }}</div>
                @endforeach
                @if ($school['motto'])
                    <div class="rf-motto">{{ $school['motto'] }}</div>
                @endif
            </td>
            {{-- Balances the logo cell, so the school name is centred on the page. --}}
            <td class="rf-gutter"></td>
        </tr>
    </table>
    <div class="rf-title">{{ $isTerm ? 'Term Performance Report' : 'Performance Report' }}</div>
    <div class="rf-title-line">
        @unless ($isTerm)
            {{ $card['exam']['name'] }} &mdash;
        @endunless
        @if ($card['term'])
            {{ $card['term']['name'] }} &mdash;
        @endif
        Year: {{ $card['academic_year'] }}
    </div>
    <table class="rf-pupil">
        <tr>
            <td class="rf-key">REPORT FOR:</td>
            <td class="rf-student">{{ $card['student']['name'] }}</td>
            <td class="rf-key">Assessment No:</td>
            <td>{{ $card['student']['assessment_no'] }}</td>
        </tr>
        <tr>
            <td class="rf-key">Grade:</td>
            <td>{{ $card['class']['name'] }}</td>
            <td class="rf-key">Tutor:</td>
            <td>{{ $card['class']['class_teacher'] ?? 'Not Assigned' }}</td>
        </tr>
    </table>
    <table class="rf-table{{ $isTerm ? ' rf-term' : '' }}">
        <thead>
            <tr>
                <th class="rf-centre">#</th>
                <th>SUBJECTS</th>
                @if ($isTerm)
                    @foreach ($card['columns'] as $column)
                        <th class="rf-centre">{{ mb_strtoupper($column['type_label']) }}</th>
                        <th class="rf-centre">LEVEL</th>
                    @endforeach
                @else
                    <th class="rf-centre">PERCENTAGE (%)</th>
                    <th class="rf-centre">PERFORMANCE LEVEL</th>
                @endif
            </tr>
        </thead>
        <tbody>
            {{-- Strand (paper) rows come before the subject they roll up into; the
                 subject row closes the group. --}}
            @foreach ($card['rows'] as $row)
                <tr class="{{ $row['kind'] === 'paper' ? 'rf-paper' : '' }}">
                    <td class="rf-no">{{ $row['kind'] === 'subject' ? $rowNo++ : '' }}</td>
                    <td class="rf-subject">{{ $row['name'] }}</td>
                    @foreach ($row['cells'] as $cell)
                        <td class="rf-mark">{{ $mark($cell, $row['kind'] === 'paper') }}</td>
                        <td class="rf-level">{{ $row['kind'] === 'paper' ? '—' : ($cell['band_label'] ?? '-') }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td class="rf-no"></td>
                <td>TOTAL MARK</td>
                @foreach ($card['totals'] as $total)
                    <td class="rf-mark">{{ $total['total_marks'] === null ? '-' : rtrim(rtrim(number_format($total['total_marks'], 2, '.', ''), '0'), '.') }}</td>
                    <td class="rf-level">{{ $total['mean_band'] ?? '' }}</td>
                @endforeach
            </tr>
        </tfoot>
    </table>
    <table class="rf-remarks">
        <tr>
            <td class="rf-remarks-head">Class Teacher's Remarks:</td>
        </tr>
        <tr>
            <td>
                @if (filled($card['entry']['class_teacher_remarks']))
                    <div class="rf-remarks-text">{!! nl2br(e($card['entry']['class_teacher_remarks'])) !!}</div>
                @else
                    <div class="rf-rule"></div>
                    <div class="rf-rule"></div>
                @endif
            </td>
        </tr>
    </table>
    <table class="rf-fields">
        @foreach (array_chunk($facts, 2, true) as $pair)
            <tr>
                @foreach ($pair as $label => $value)
                    <td class="rf-key">{{ $label }}:</td>
                    <td class="rf-val">{{ $value }}</td>
                @endforeach
            </tr>
        @endforeach
        <tr class="rf-sign">
            <td class="rf-key">
                Head Teacher Signature:
                @if (filled($card['head_teacher']))
                    <div class="rf-sign-name">({{ $card['head_teacher'] }})</div>
                @endif
            </td>
            <td></td>
            <td class="rf-key">Parents Signature:</td>
            <td></td>
        </tr>
    </table>
    <div class="rf-footer">{{ $card['footer'] }}</div>
</div>
</div>
