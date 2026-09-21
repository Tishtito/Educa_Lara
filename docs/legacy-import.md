# Importing a school from legacy Educa

How a school running the old PHP system (`Educa_vol_1`) is brought into Educa:
repeatably while legacy is still in use, then once more at cutover.

One legacy school is **three databases** — lower classes (Grades 1-3), upper
classes (4-6) and JSS (7-9) — and becomes **one tenant with three sections**.

> **The legacy data is children's personal data.** Restore and import it only on
> a machine you control. Never commit a dump, never copy real names into tests
> or fixtures, and keep reports where the commands put them
> (`storage/app/private/legacy/`, git-ignored).

---

## The loop

```
restore dumps ─► legacy:import --dry-run ─► legacy:review --export ─► school answers
      ▲                                                                   │
      │                                  legacy:review --apply ◄──────────┘
      │                                           │
      │                                  legacy:import ─► legacy:verify
      │                                                         │
      └──────────── re-export legacy and repeat until cutover ◄─┘
```

Every run is idempotent. The importer records each target row in
`legacy_migration_map` with a checksum of its source row, so a run with no
legacy changes changes nothing, and a run after changes updates, adds and
removes exactly what changed in legacy. The school's answers live in
`legacy_decisions` and are reused by every later run.

---

## 1. Restore the legacy databases (once per export)

Export the three databases from cPanel (phpMyAdmin → Export → SQL), then locally:

```bash
sudo mysql <<'SQL'
CREATE DATABASE IF NOT EXISTS legacy_lower CHARACTER SET utf8mb4;
CREATE DATABASE IF NOT EXISTS legacy_upper CHARACTER SET utf8mb4;
CREATE DATABASE IF NOT EXISTS legacy_jss   CHARACTER SET utf8mb4;
-- read-only: the importer can never write to legacy
CREATE USER IF NOT EXISTS 'educa_legacy'@'localhost' IDENTIFIED BY '<choose one>';
GRANT SELECT ON legacy_lower.* TO 'educa_legacy'@'localhost';
GRANT SELECT ON legacy_upper.* TO 'educa_legacy'@'localhost';
GRANT SELECT ON legacy_jss.*   TO 'educa_legacy'@'localhost';
SQL

sudo mysql legacy_lower < gkzkjole_lowersystem.sql
sudo mysql legacy_upper < gkzkjole_uppersystem.sql
sudo mysql legacy_jss   < gkzkjole_jss.sql
```

For a repeat run, drop and re-create the three databases before restoring the
new export. Then delete the dump files; they are not needed once restored.

`.env`:

```dotenv
LEGACY_DB_HOST=127.0.0.1
LEGACY_DB_USERNAME=educa_legacy
LEGACY_DB_PASSWORD=<the password above>
LEGACY_LOWER_DATABASE=legacy_lower
LEGACY_UPPER_DATABASE=legacy_upper
LEGACY_JSS_DATABASE=legacy_jss
```

Sources and their order live in `config/legacy.php`.

## 2. Dry run

```bash
php artisan legacy:import --school=gatimu --create-school="Gatimu School" --dry-run
```

Runs every step inside a transaction and rolls it back. The only things kept are
the **questions for the school** and the **quarantine list**. The report is
written to `storage/app/private/legacy/<school>/import-<time>.md`.

## 3. The school answers its questions

```bash
php artisan legacy:review --school=gatimu                       # list open questions
php artisan legacy:review --school=gatimu --export=review.csv   # to a spreadsheet
```

Fill in the `decision` column, leave everything else as exported:

| kind | what is asked | `accept` | `reject` | `custom` (+ `payload`) |
|---|---|---|---|---|
| `student_match` | Is this the same child in two sections? | one student | two students | — |
| `staff_conflict` | One username, two different names: same person? | one account | separate accounts (`tom.upper`, `tom.jss`) | — |
| `class_mapping` | A class string that is not a grade and stream (`6_test`) | — | not a class: its rows are skipped, and results of pupils recorded only in it are set aside (`result_in_rejected_class`) | `{"grade": 4, "stream": "East"}` |

```bash
php artisan legacy:review --school=gatimu --apply=review.csv --by="Deputy Head"
```

A blank decision leaves the question open. If legacy changed after the file
was exported and the importer now asks something different, that row is
refused ("the question has changed") — export again. The same happens on import:
an answer to a question that has since changed is not reused.

Confident student matches are merged without asking: the name is unique in both
sections **and** the child's last grade in one section is the section's top grade
in year *Y* and their first grade in the next is its bottom grade in *Y+1*
(Grade 3 in 2025 → Grade 4 in 2026). Everything else is asked.

## 4. Import

```bash
php artisan legacy:import --school=gatimu
```

Refused (exit 1, nothing kept) while any question is open or any **blocking**
quarantine item exists. Blocking items must be fixed **in legacy** (then
re-export), because the importer will not guess:

| Reason | Meaning | Fix in legacy |
|---|---|---|
| `unknown_column_with_data` | `exam_results` has a column the importer does not know, holding marks | Clear it, or add the subject to the section's profile (`app/Domain/LegacyImport/Profiles`) |
| `exam_year_unknown` | An exam with no `date_created` and no usable year | Set its date |
| `no_enrolment_for_exam_year` | A result for a pupil with no class row in any year | Add the pupil's class row |
| `duplicate_result_row` | Two results for one pupil in one exam | Delete the wrong one |

Accounted (non-blocking) items are listed in the report for the school and
counted by `legacy:verify`: `unmapped_mark_cell` (a combined English/Kiswahili mark
where that class used papers but the pupil has none), `result_in_rejected_class`,
`unparseable_class`, `class_outside_section`, `unknown_assigned_class`.

## 5. Verify

```bash
php artisan legacy:verify --school=gatimu      # exit 0 only if every gate passes
```

**Gates** — must be exact:

| Gate | Every… |
|---|---|
| students | legacy student has a student record |
| staff | legacy admin, class teacher and examiner row has a user |
| exams | dated legacy exam was imported, with as many results as legacy rows minus quarantined rows |
| marks | non-null legacy mark cell is imported with the same value, **or** is a combined mark beside its imported papers, **or** is quarantined with a reason |
| open items | question is answered and blocking quarantine item cleared |

**Findings** — reported, never gating. Legacy's derived values were unreliable
and are recomputed by the new pipeline rather than copied:

- *totals*: match, "legacy summed every column" (the lower 2026 strand
  double count), or stale/unexplained;
- *combined marks*: legacy's stored English/Kiswahili against the papers;
- *placements*: `inferred_year` (no class row for the exam's year) and
  `resolved_duplicate` (two class rows in one year);
- quarantine counts by reason.

Examples of each finding are in the report
(`storage/app/private/legacy/<school>/verify-<time>.md`). Send the school the
lists they need to check; never send the file outside the school.

---

## What the import does with the data

| Legacy | Educa |
|---|---|
| Three databases | One school; levels lower primary, upper primary, junior secondary |
| `point_boundaries` | The section's own grading scale, set as the level default. Lowest band widened to 0 (JSS started at 1). Per-subject rows kept as a `legacy_broken` scale, never used |
| Exam `academic_year` (`0000`/`NULL`) | Ignored: the year is the year of `date_created` |
| Rollover rows | Legacy's year rollover copied each pupil's class into the new year before leavers were finished (all 80 lower-classes Grade 3 leavers have a 2026 `grade_3` row minutes before their 2026 `Completed` row). A class row in a year that also has a graduation marker and no results is ignored, and counted in the report |
| `student_classes` | One enrolment per pupil per year. Two rows in a year: the one that year's results link to wins, then the newest (`origin = resolved_duplicate`) |
| `exam_results.student_class_id` | Ignored: a result goes to the pupil's enrolment **in the exam's year** (legacy linked 2026 results to 2025 rows). No row that year → placement inferred from the nearest row (`origin = inferred_year`) |
| Class strings (`grade_2east`, `GRADE 3 EAST`, `7`) | One class per grade and stream, spellings kept as class aliases. `Completed` = left the section |
| `students.name` | First / middle / last, split losslessly. Assessment number `LEG-L-00042` (legacy had none); editable, never overwritten by a later run |
| Mark columns | Entered scores. Single-paper percentages out of 100; strands and papers raw, out of `marks_out_of` or the profile default (JSS papers 50). Stray lower columns (`LS/SP`, `WRI`, `Integrated`) kept as not-counted subjects |
| Curriculum changes | Chosen per exam **and class** by which columns hold marks: lower 2026 English = Reading + Grammar and Kiswahili = Kusoma + Lugha where those exist, otherwise the single mark |
| Totals, means, positions, combined marks | Recomputed. Legacy totals and positions kept on each result (`legacy_*`) for comparison. Ranking uses `legacy_ordinal` ties |
| Exam status | Completed → published with frozen report cards; Scheduled → locked (on `--final`: marking); Cancelled → archived; exam deleted from legacy → archived |
| `admins`, `class_teachers`, `examiners` | One user per username (per person when names differ and the school says so), roles combined. The legacy bcrypt hash is carried, from the most senior account, and the user must set a new password at first sign-in. `users` tables (unused by legacy) skipped |
| `class_assigned`, `examiner_subject_classes` | Latest year's class-teacher and examiner assignments. Lower/upper examiners were per class, not per subject: expanded to every subject of the class (`origin = expanded_from_class_assigned`) for the school to trim |

Staff who sign in with their legacy password get `must_change_password: true`
in the login response and a 403 with `password_change_required: true` from
everything except `GET /api/auth/me`, `POST /api/auth/logout` and
`POST /api/auth/password` until they choose a new one. A later import never
touches a password the person has already changed.

---

## Cutover

1. Rehearse: at least one full loop on a fresh export, verified, with the school
   having checked a sample of report cards per section against what legacy
   printed.
2. Agree a freeze time with the school. Announce it to staff.
3. At the freeze: put all three legacy portals into maintenance (or rename their
   `config.php` database credentials) so nothing more is written.
4. Export, restore (step 1), then:
   ```bash
   php artisan legacy:import --school=gatimu --dry-run     # expect no new questions
   php artisan legacy:import --school=gatimu --final
   php artisan legacy:verify --school=gatimu               # must exit 0
   ```
   `--final` opens exams still `Scheduled` in legacy for marking in Educa.
5. Set the school active, hand staff their sign-in (school, username, **old**
   password; they will be asked for a new one).
6. Keep the final export and the verification report, both private, until the
   school has run a full term on Educa. Then delete the local legacy databases
   and the `educa_legacy` user.

Do not run `legacy:import` again after staff have started working in Educa: a
re-run treats legacy as the source of truth for everything it imported.

---

## When something looks wrong

| Symptom | Cause | Fix |
|---|---|---|
| `legacy:import` exits 1 with no errors | Open questions or blocking quarantine | `legacy:review`, and the report's quarantine section |
| Every run asks the same question again | Legacy data about it keeps changing (e.g. a class row edited between exports) | Answer the new export's question |
| A child appears twice | Match was not confident and was rejected, or the names differ in spelling | Answer `accept` (spelling differences are not matched: fix the name in legacy) |
| A teacher cannot sign in | They changed usernames between sections, or were split by a `staff_conflict` rejection (`tom.jss`) | Tell them their new username |
| `verify` fails `marks` after an import that passed | Legacy changed after the import (a new export) | Import again, then verify |
