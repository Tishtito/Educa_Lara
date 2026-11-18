# Report card templates — design previews

Static HTML/CSS previews of the report card templates a school administrator
can choose between (`ReportTemplate`: `classic` | `modern` | `formal`). Each
`<slug>/single.html` and `<slug>/term.html` is the eventual Blade template with
sample values filled in and loops unrolled; `<slug>/card.css` is the stylesheet
to ship as `resources/views/reports/templates/<slug>/card.css`.

**All sample data is synthetic.** The school, pupil, teachers and marks are
invented (CLAUDE.md rule 16). The logo is a generated placeholder PNG inlined
as a data URI, exactly where the renderer inlines the real one.

## Templates

| Slug | Prefix | Picker description |
|------|--------|--------------------|
| `classic` | `rp-` | The original Elimu Pass report card: blue header band and shaded mark columns. |
| `modern` | `rm-` | A clean, open layout with light rules, a pupil summary panel and a bold totals bar. |
| `formal` | `rf-` | A compact, fully ruled black-and-white form in a serif typeface; photocopies cleanly. |

- **classic** is `resources/views/reports/report-card.css` byte for byte (as at
  `HEAD`), with the Blade expressions of `card.blade.php` replaced by sample
  values. It is here only for side-by-side comparison. See "Classic does not
  fit the worst case" below — its sample is smaller than the other two.
- **modern** — Helvetica. Logo and school name on the left (name as typed, not
  upper-cased), contact lines on the right, a pupil panel with small-caps
  labels, an unfilled marks table on hairline rules, totals in a solid bar,
  remarks behind a side rule, fee and dates as four tiles, signature lines with
  the label beneath.
- **formal** — Times. Everything inside one double-ruled frame; centred
  upper-case school name; pupil particulars, marks, remarks and fee/dates/
  signatures as bordered label/value grids. Blank values are empty boxes rather
  than fill-in lines. Uses no colour at all.

All three show exactly the same information with the same label wording. No
template adds or drops a field, and none needs a new setting.

## Constraints (dompdf 3.x, as configured in `ReportCardRenderer`)

- Tables for layout. No flexbox, grid, float, CSS variables, `calc`, web fonts
  or remote assets (remote loading, PHP and JavaScript are off in the renderer).
- Built-in fonts only: Helvetica, Times, Courier. Sizes in px against A4
  portrait at 96 DPI (content box about 703 x 1032 px).
- `@page { margin: 1.2cm }` is identical in every stylesheet. Several
  templates' stylesheets can be inlined into one document, so page rules cannot
  differ.
- **Class-prefix rule.** Every selector in a template's stylesheet is under
  that template's prefix (`rm-`, `rf-`; classic keeps `rp-`). No bare element
  selectors and no class name shared between templates. Classic is the one
  exception already in production: it styles `body` and uses `.report-page` /
  `.report-page-last`.
- Because of that rule the new stylesheets do **not** style `body`. They set
  font, size and colour on their own wrapper (`.rm-page`, `.rf-page`). The
  previews zero the body margin inline; in the app the document shell should do
  it (`body { margin: 0; padding: 0 }` in `document.blade.php`), since
  classic's `body` rule is only present when a classic card is in the document.
  Without it dompdf's default 8px body margin applies — the cards still fit,
  with 16px less headroom and width.
- One pupil per A4 page in both layouts. Page breaks are on the wrapper:
  `page-break-after: always`, switched off by `<prefix>page-last` on the last
  card (same contract as classic's `report-page-last` / `$isLast`).
- Must survive a black-and-white photocopier: nothing is conveyed by colour
  alone. Absent is the text `ABS`, levels are text, paper rows are marked by
  indent and weight/italics.

## Sample data and one-page check

The worst case used for `modern` and `formal`: 12 subjects; in the term layout
three of them carry paper rows (2 + 2 + 3 = 7 paper rows, 19 table rows in
all); one absent cell (`ABS`); one subject with `counts_toward_total = false`;
a subject name long enough to wrap; a school name that wraps to two lines; a
long motto; a three-line remark containing a line break; a decimal mark.
`single.html` leaves the next-term feeding fee unset to show the blank variant.

Paper rows exist only in the term layout (`ReportCardBuilder::rows` adds them
only for `ReportLayout::TERM`), and they come *before* the subject row they
roll up into. The new templates leave that order alone and style the subject
row as the closing line of its group.

Rendered through the repo's dompdf with the renderer's options (headroom = how
many px of extra content fit before a second page appears):

| File | Pages | Headroom |
|------|-------|----------|
| `classic/single.html` (10 subjects) | 1 | ~22px |
| `classic/term.html` (6 subjects, 4 paper rows) | 1 | ~13px |
| `modern/single.html` | 1 | ~163px |
| `modern/term.html` | 1 | ~131px |
| `formal/single.html` | 1 | ~121px |
| `formal/term.html` | 1 | ~90px |

### Classic does not fit the worst case

With its stylesheet unchanged, classic spills onto a second page with the
sample above: the single layout fits 10 subjects (11 with a one-line school
name and a short remark), and the term layout fits about 8 subjects with no
paper rows, or 6 subjects with 4 paper rows. The classic previews therefore use
a cut-down subject list (totals recomputed) so that they are an honest
one-page rendition of today's card. This is a property of the existing design,
not something introduced here; it was left alone because classic must stay
unchanged.

## Porting to Blade

Keep the `@php` block at the top of the classic `card.blade.php` (`$mark`,
`$money`, `$date`, `$isTerm`, `$school`, `$schoolLines`, `$rowNo`); every
template uses the same helpers. Then substitute region by region.

### Value formatting (identical in all templates)

| Shown | Rule |
|-------|------|
| Subject mark | `$mark($cell)`: `ABS` if `is_absent`; `-` if `score` is null; else the score without trailing zeros |
| Paper mark | `$mark($cell, true)`: as above plus ` / max_marks` |
| Subject level | `$cell['band_label'] ?? '-'` |
| Paper level | literal `—` (papers are never banded) |
| Total | `-` if `total_marks` is null, else without trailing zeros |
| Mean band | `$total['mean_band'] ?? ''` (the abbreviation) |
| Money | `'KES '.number_format($v, 2)`, null stays null |
| Dates | `j M Y`, null stays null |
| Row number | `$rowNo++` on subject rows, empty on paper rows |

A column whose exam does not exist yet (`columns[i].available === false`) needs
no special case: its cells arrive with null scores and print `-`, its total
prints `-` and its mean band prints nothing.

`counts_toward_total` is in the payload but classic prints nothing for it, so
neither do the new templates.

### Region map

| Region | Payload | classic | modern | formal |
|--------|---------|---------|--------|--------|
| Card wrapper | `$isLast` | `div.report-page[.report-page-last]` | `div.rm-page[.rm-page-last]` | `div.rf-page[.rf-page-last]` > `div.rf-frame` |
| Logo | `$logo` (data URI; omit the `<img>` when null, keep the cell) | `td.rp-logo img` | `td.rm-logo img` | `td.rf-logo img` |
| School name | `school.name` | `.rp-school-name`, `mb_strtoupper` | `.rm-school-name`, as typed | `.rf-school-name`, `mb_strtoupper` |
| Contact lines | `$schoolLines` = non-empty of `school.address`, `school.phone`, `school.email`, one element each | `.rp-school-line` | `.rm-contact-line` in `td.rm-contact` | `.rf-school-line` |
| Motto | `school.motto` (omit the element when empty) | `.rp-school-line > em` | `.rm-motto` | `.rf-motto` |
| Title | `Term Performance Report` if `layout === 'term'`, else `Performance Report` | `.rp-title` | `.rm-title-text` | `.rf-title` |
| Exam name | `exam.name`, single layout only | `.rp-meta-line` | first `.rm-title-line` | start of `.rf-title-line`, followed by ` — ` |
| Term name | `term.name`, only when `term` is not null | `.rp-meta-line` | second `.rm-title-line`, followed by ` · ` | `.rf-title-line`, followed by ` — ` |
| Year | `Year: ` + `academic_year` | `.rp-meta-line` | same `.rm-title-line` as the term | end of `.rf-title-line` |
| "REPORT FOR" + name | `student.name` | `.rp-label`, `.rp-student` | `td.rm-pupil-name`: `.rm-label`, `.rm-student` | `td.rf-key`, `td.rf-student` |
| Assessment No | `student.assessment_no` | `.rp-meta-line` | `td.rm-pupil-fact`: `.rm-label`, `.rm-fact` | `td.rf-key` + `td` |
| Grade | `class.name` | `.rp-meta-line` | `td.rm-pupil-fact` | `td.rf-key` + `td` |
| Tutor | `class.class_teacher ?? 'Not Assigned'` | `.rp-meta-line` | `td.rm-pupil-wide` | `td.rf-key` + `td` |
| Marks table | add the term modifier when `$isTerm` | `table.rp-table[.rp-term]` | `table.rm-table[.rm-term]` | `table.rf-table[.rf-term]` |
| Column heads, term | per `columns[]`: `mb_strtoupper(type_label)`, then `LEVEL` | `th.rp-right` | `th.rm-right` | `th.rf-centre` |
| Column heads, single | `PERCENTAGE (%)`, `PERFORMANCE LEVEL` | `th.rp-right` | `th.rm-right` | `th.rf-centre` |
| Row | `rows[]`; paper modifier when `kind === 'paper'` | `tr[.rp-paper]` | `tr[.rm-paper]` | `tr[.rf-paper]` |
| Row cells | number, `rows[].name`, then per `cells[]` a mark and a level | `td.rp-no`, `.rp-subject`, `.rp-mark`, `.rp-level` | `td.rm-no`, `.rm-subject`, `.rm-mark`, `.rm-level` | `td.rf-no`, `.rf-subject`, `.rf-mark`, `.rf-level` |
| Totals row | `TOTAL MARK`, then per `totals[]`: `total_marks`, `mean_band` | `tfoot`: `td`, `td`, `.rp-mark`, `.rp-level` | `tfoot`: `td.rm-no`, `td.rm-total-label`, `.rm-mark`, `.rm-level` | `tfoot`: `td.rf-no`, `td`, `.rf-mark`, `.rf-level` |
| Remarks | `entry.class_teacher_remarks` via `nl2br(e(...))` | `.rp-remarks`: label + `.rp-remarks-text` | `div.rm-remarks`: `.rm-label` + `.rm-remarks-text` | `table.rf-remarks`: `td.rf-remarks-head` + `.rf-remarks-text` |
| Fee balance | `$money(entry.fee_balance)` | `.rp-fields` cell 1 | `table.rm-facts` cell 1 (`td.rm-first`) | `table.rf-fields` row 1, first key/value pair |
| Next Term Feeding Amount | `$money(term.next_term_feeding_fee ?? null)` | cell 2 | cell 2 | row 1, second pair |
| Closing Date | `$date(term.closing_date ?? null)` | cell 3 | cell 3 | row 2, first pair |
| Opening Date | `$date(term.opening_date ?? null)` | cell 4 | cell 4 | row 2, second pair |
| Head Teacher Signature | blank line; `head_teacher` shown only when filled | cell 5, name in a `span` after the label, in brackets | `table.rm-sign` cell 1: `.rm-sign-line`, `.rm-label`, `.rm-sign-name` (omit when empty) | `tr.rf-sign`: `td.rf-key` with `.rf-sign-name` in brackets (omit when empty) + empty `td` |
| Parents Signature | blank line | cell 6 | `td.rm-sign-last`: `.rm-sign-line`, `.rm-label` | `td.rf-key` + empty `td` |
| Footer | `footer` | `.rp-footer` | `.rm-footer` | `.rf-footer` |

### Blank variants (not all visible in the previews)

| Case | classic | modern | formal |
|------|---------|--------|--------|
| Remark empty (`! filled(...)`) | label without `rp-has-value`, then two `div.rp-rule` | keep `.rm-label`, replace `.rm-remarks-text` with two `<div class="rm-rule"></div>` | replace `.rf-remarks-text` with two `<div class="rf-rule"></div>` |
| Fee / feeding fee / date is null | label without `rp-has-value`, then `div.rp-rule` | replace `.rm-value` with `<div class="rm-rule"></div>` (shown in `modern/single.html`, feeding amount) | leave the `td.rf-val` empty (shown in `formal/single.html`) |
| `term` is null | term line omitted; feeding fee and both dates blank | drop the term name and the ` · `, keeping `Year: …` | drop the term name and its ` — `, keeping `Year: …` |
| `head_teacher` empty | no bracketed name | no `.rm-sign-name` | no `.rf-sign-name` |
| No logo | empty `td.rp-logo` | empty `td.rm-logo` | empty `td.rf-logo` (keep `td.rf-gutter`, which centres the name) |

In `modern` the four facts are an explicit row of four cells and the two
signatures a separate table, rather than classic's `array_chunk($fields, 2)`
loop; in `formal` they are three explicit rows. The label text is unchanged
apart from the trailing colon, which `modern` drops.

## Regenerating

The previews were generated and checked by scratch scripts kept outside the
repo. To re-check by hand, render a preview through dompdf with the options in
`ReportCardRenderer::options()` (chroot pointed at this directory), replacing
the `<link rel="stylesheet" href="card.css">` with the stylesheet inlined in a
`<style>` element, and confirm the page count is 1.

The first line of every preview, `<!-- @dsCard group="Report cards" -->`, marks
it as a card for the claude.ai/design sync. dompdf ignores it.
