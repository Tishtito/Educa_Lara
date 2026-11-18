{{--
    The "Modern" report card. Ported from design/report-cards/modern/; the
    region-to-payload map is in design/report-cards/README.md. Prints exactly
    what the Classic card prints, from the same payload — only the look differs.
    Every class is under the rm- prefix so its stylesheet can share a document
    with another template's.
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
<div class="rm-page{{ $isLast ? ' rm-page-last' : '' }}">
    <table class="rm-header">
        <tr>
            <td class="rm-logo">@if ($logo)<img src="{{ $logo }}" alt="">@endif</td>
            <td class="rm-school">
                <div class="rm-school-name">{{ $school['name'] }}</div>
                @if ($school['motto'])
                    <div class="rm-motto">{{ $school['motto'] }}</div>
                @endif
            </td>
            <td class="rm-contact">
                @foreach ($schoolLines as $line)
                    <div class="rm-contact-line">{{ $line }}</div>
                @endforeach
            </td>
        </tr>
    </table>
    <table class="rm-title">
        <tr>
            <td>
                <div class="rm-title-text">{{ $isTerm ? 'Term Performance Report' : 'Performance Report' }}</div>
            </td>
            <td class="rm-title-right">
                @unless ($isTerm)
                    <div class="rm-title-line">{{ $card['exam']['name'] }}</div>
                @endunless
                <div class="rm-title-line">@if ($card['term']){{ $card['term']['name'] }} &middot; @endif{{ 'Year: '.$card['academic_year'] }}</div>
            </td>
        </tr>
    </table>
    <table class="rm-pupil">
        <tr>
            <td class="rm-pupil-name">
                <div class="rm-label">Report for</div>
                <div class="rm-student">{{ $card['student']['name'] }}</div>
            </td>
            <td class="rm-pupil-fact">
                <div class="rm-label">Assessment No</div>
                <div class="rm-fact">{{ $card['student']['assessment_no'] }}</div>
            </td>
            <td class="rm-pupil-fact">
                <div class="rm-label">Grade</div>
                <div class="rm-fact">{{ $card['class']['name'] }}</div>
            </td>
            <td class="rm-pupil-wide">
                <div class="rm-label">Tutor</div>
                <div class="rm-fact">{{ $card['class']['class_teacher'] ?? 'Not Assigned' }}</div>
            </td>
        </tr>
    </table>
    <table class="rm-table{{ $isTerm ? ' rm-term' : '' }}">
        <thead>
            <tr>
                <th>#</th>
                <th>SUBJECTS</th>
                @if ($isTerm)
                    @foreach ($card['columns'] as $column)
                        <th class="rm-right">{{ mb_strtoupper($column['type_label']) }}</th>
                        <th class="rm-right">LEVEL</th>
                    @endforeach
                @else
                    <th class="rm-right">PERCENTAGE (%)</th>
                    <th class="rm-right">PERFORMANCE LEVEL</th>
                @endif
            </tr>
        </thead>
        <tbody>
            {{-- Strand (paper) rows come before the subject they roll up into; the
                 subject row closes the group. --}}
            @foreach ($card['rows'] as $row)
                <tr class="{{ $row['kind'] === 'paper' ? 'rm-paper' : '' }}">
                    <td class="rm-no">{{ $row['kind'] === 'subject' ? $rowNo++ : '' }}</td>
                    <td class="rm-subject">{{ $row['name'] }}</td>
                    @foreach ($row['cells'] as $cell)
                        <td class="rm-mark">{{ $mark($cell, $row['kind'] === 'paper') }}</td>
                        <td class="rm-level">{{ $row['kind'] === 'paper' ? '—' : ($cell['band_label'] ?? '-') }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td class="rm-no"></td>
                <td class="rm-total-label">TOTAL MARK</td>
                @foreach ($card['totals'] as $total)
                    <td class="rm-mark">{{ $total['total_marks'] === null ? '-' : rtrim(rtrim(number_format($total['total_marks'], 2, '.', ''), '0'), '.') }}</td>
                    <td class="rm-level">{{ $total['mean_band'] ?? '' }}</td>
                @endforeach
            </tr>
        </tfoot>
    </table>
    <div class="rm-remarks">
        <div class="rm-label">Class Teacher's Remarks</div>
        @if (filled($card['entry']['class_teacher_remarks']))
            <div class="rm-remarks-text">{!! nl2br(e($card['entry']['class_teacher_remarks'])) !!}</div>
        @else
            <div class="rm-rule"></div>
            <div class="rm-rule"></div>
        @endif
    </div>
    <table class="rm-facts">
        <tr>
            @foreach ($facts as $label => $value)
                <td class="{{ $loop->first ? 'rm-first' : '' }}">
                    <div class="rm-label">{{ $label }}</div>
                    @if ($value !== null)
                        <div class="rm-value">{{ $value }}</div>
                    @else
                        <div class="rm-rule"></div>
                    @endif
                </td>
            @endforeach
        </tr>
    </table>
    <table class="rm-sign">
        <tr>
            <td>
                <div class="rm-sign-line"></div>
                <div class="rm-label">Head Teacher Signature</div>
                @if (filled($card['head_teacher']))
                    <div class="rm-sign-name">{{ $card['head_teacher'] }}</div>
                @endif
            </td>
            <td class="rm-sign-last">
                <div class="rm-sign-line"></div>
                <div class="rm-label">Parents Signature</div>
            </td>
        </tr>
    </table>
    <div class="rm-footer">{{ $card['footer'] }}</div>
</div>
