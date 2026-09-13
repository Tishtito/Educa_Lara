# Educa — Architecture

Educa is a multi-school (SaaS) rewrite of `Educa_vol_1`, a PHP school-management
system deployed as four near-duplicate codebases against three unrelated MySQL
databases. This document records the decisions that shape the rewrite and why
each one exists, because most of them are responses to a specific defect in the
old system rather than general good practice.

---

## 1. Where the old system was

Four sibling deployments — `lower-classes/`, `upper-classes/`,
`Upper-classes-combined/`, `JSS/` — each with three portals (admin,
class-teacher, examiner), each portal a hand-rolled router + controllers with
SQL written inline. ~35,000 lines of PHP, of which roughly one quarter is
unique; the rest is copies.

The fork was forced by one table. `exam_results` carried **one column per
subject**, and the subject set differed per deployment:

| Deployment | `exam_results` subject columns |
|---|---|
| lower-classes | `Math, English, LS/SP, GRM, WRI, RDG, Kiswahili, KUS/KUZ, KUS, LUG, KUA, Enviromental, Creative, Religious` |
| upper-classes | `Math, English, Kiswahili, SciTech, AgricNutri, Creative, CRE, SST, CA_SST_CRE` |
| Upper-classes-combined | `Math, Gramma, Compo, English, Lugha, Insha, Kiswahili, SciTech, AgricNutri, Creative, CRE, SST` |
| JSS | `Math, Paper1English, Paper2English, English, paper1Kiswahili, paper2Kiswahili, Kiswahili, Technical, Agriculture, Creative, Religious, SST, Science` |

Every marklist, report and analytics controller string-concatenated its own
column list into SQL, so each had to be forked four ways. Normalising that table
is what collapses the four codebases into one.

Multi-school was achieved by copying the directory tree and creating another
database. There was no `schools` table, no roles table, and no authorization
beyond "which portal directory did you log into".

---

## 2. Tenancy

**Single database, shared schema, `school_id` discriminator.** Not
database-per-tenant, and not `stancl/tenancy` — the kernel is hand-written in
`app/Domain/Tenancy/`.

Every tenant-owned table carries
`foreignId('school_id')->constrained()->cascadeOnDelete()`, and **every unique
index on such a table is prefixed with `school_id`** — for example
`unique(['school_id', 'admission_no'])`. That prefixing is what lets two schools
each have an admission number `ADM-001`.

### The three model categories

| Category | Examples | `school_id` | Scoped? |
|---|---|---|---|
| Tenant-owned | `Student`, `Exam`, `SchoolClass`, `Subject` | required | yes, via `BelongsToSchool` |
| Platform reference | `Level`, `Grade`, `Role` | absent | no — shared by every tenant |
| Nullable-tenant | `GradingScale`, `SubjectAlias` | nullable; NULL = platform default | no — filtered explicitly |
| Auth | `User` | nullable; NULL = platform superadmin | no — see below |

`User` is deliberately not scoped: authentication has to find the user *before* a
tenant exists, and a superadmin belongs to no tenant. Its uniqueness comes from
`school_key` (= `COALESCE(school_id, 0)`, maintained by a model hook) and the
`unique(['school_key', 'username'])` index. The tenant check for users is done
by `ResolveTenant`, which 403s when the hostname's school and the user's school
disagree.

### Enforcement

`TenantScope` (`app/Domain/Tenancy/Scopes/TenantScope.php`) is a global Eloquent
scope applied by the `BelongsToSchool` trait, which also stamps `school_id` on
create. **It fails closed**: with no tenant resolved it applies
`whereRaw('1 = 0')` rather than returning every school's rows. That default is
the single most important behaviour in the codebase and is pinned by
`tests/Feature/TenantScopeFailsClosedTest.php`.

`TenantManager` **must be bound as a singleton** (it is, in
`AppServiceProvider::register()`). `TenantScope` resolves it from the container
on every query; without the binding, auto-wiring returns a fresh instance and the
tenant set by middleware is invisible to the scope. It is also cleared between
queued jobs, so a long-lived worker never inherits the previous job's tenant.

### The repository rule

Global scopes are **Eloquent-only**. `DB::table()`, `DB::select()` and raw joins
bypass them completely — and the marklist is inherently a large raw join. So:

> All raw SQL lives in a class under a `Repositories/` directory that takes
> `int $schoolId` as a **required constructor argument**.

`tests/Feature/ArchitectureTest.php` enforces both halves: no raw query helper
outside `Repositories/`, and no repository without a `$schoolId` constructor
parameter. `MarklistRepository` is the first example.

### Resolution

`app/Http/Middleware/ResolveTenant.php`, aliased `tenant`. Because the API uses
**bearer tokens** rather than subdomain cookies, precedence is:

1. Hostname found in `school_domains` (production subdomains, and the compat
   window where several legacy hostnames map onto one tenant).
2. Otherwise the authenticated user's `school_id`.
3. Platform superadmins (`school_id` NULL) must name a school with an
   `X-School` header carrying its `uuid` or `slug`. Without one, no tenant is
   set and the fail-closed scope returns nothing — which is correct.
4. Hostname school ≠ user's school → **403**.

A suspended school is refused.

**Ordering is load-bearing, in both directions.** `tenant` must run **after**
`auth:sanctum` (it needs the user), so it is listed per route group rather than
appended to the API group, whose middleware runs before route middleware. It must
also run **before** route model binding: `SubstituteBindings` sits in the API
group, and when it ran first, `{exam}` was looked up with no tenant, the
fail-closed scope hid it, and every `GET /api/exams/{exam}` returned 404 — for
the school's own exams too. `bootstrap/app.php` fixes this with
`prependToPriorityList(before: SubstituteBindings::class, ...)`, giving
auth → tenant → bindings. `ExamsApiTest::test_it_shows_the_schools_own_exam`
pins it.

---

## 3. Authentication and authorization

- **Sanctum bearer tokens.** `POST /api/auth/login` returns a plain-text token.
- **Credentials are `(school, username, password)`**, not `(username, password)`
  — `username` only identifies a person within one school. The school may be
  given explicitly (slug or uuid) or derived from the hostname.
- Unknown user and wrong password return the **same** 422, so the endpoint
  cannot be used to enumerate usernames. Login is rate-limited (`throttle:6,1`).
- **Self-service registration is not carried over.** Legacy exposed an open
  `POST /signup/register` on all three portals of every deployment: anyone could
  create an admin account. Users are created by a school admin.
- Roles are `super_admin`, `school_admin`, `class_teacher`, `examiner`, gated by
  `EnsureUserHasRole` (alias `role`). A `super_admin` passes every check.

---

## 4. The examination model

The heart of the conversion. Four wide tables become two narrow ones.

### `exam_subject_scores` — two kinds of row

| Kind | `subject_paper_id` | `is_derived` | Meaning |
|---|---|---|---|
| entered | NOT NULL | false | one per paper an examiner marks |
| derived | NULL | true | the rolled-up subject score |

A single-paper subject still gets both. That uniformity is what removes the
four-way fork: every read path selects derived rows only and never needs to know
how many papers a subject has.

`max_marks` is **snapshotted onto the score at entry time**, not looked up.
Legacy recomputed aggregates against the *current* `marks_out_of`, so an admin
correcting a typo in it retroactively changed percentages on report cards
already issued.

### Aggregation is data, not code

`level_subjects.aggregation_rule` carries one of five `AggregationRule` cases.
The legacy behaviours map onto it exactly:

| Rule | Legacy source |
|---|---|
| `single` | upper-classes, and every non-split subject |
| `percentage_of_combined_max` | lower-classes (RDG+GRM → English) and JSS (Paper1+Paper2, hardcoded 50/50) |
| `sum` | Upper-classes-combined (Gramma+Compo → English, Lugha+Insha → Kiswahili) |
| `average`, `weighted_sum` | unused by any deployment; configurable without code |

The rule lives on `level_subjects` rather than `subjects` because the same
subject aggregates differently per level.

### `exam_student_results` keeps both `total_marks` and `mean_marks`

Upper-classes-combined stored a **mean** in the column named `total_marks` (its
`SubjectController.php:449` divides by subject count) while every other
deployment stored a sum. Collapsing them would silently rewrite every UCC report.

### Legacy-compatibility fields

- `exams.tie_policy = legacy_ordinal` — legacy ranked with `$rank = $index + 1`,
  no tie handling, totals cast to `(int)`. Imported exams keep that so their
  positions match the report cards parents already hold. New exams may not
  select it (rejected by `StoreExamRequest`).
- `exams.mean_policy` — legacy's mean queries required every subject column to be
  non-null, so a pupil missing one paper vanished from the class mean. That was
  never a decision; it fell out of the SQL. Now explicit.
- `exam_student_results.legacy_total_marks` / `legacy_grade_position` /
  `legacy_stream_position` — carried by the ETL so `legacy:verify` can compare
  without the source database online. **Dropped after cutover.**

### The computation pipeline

`ExamComputationService::compute(Exam)` turns entered paper marks into
everything the reports read. It is triggered explicitly — `POST
/api/exams/{exam}/compute` (school admin) or `php artisan exams:compute {id}` —
never as a side effect of viewing a page, which is what legacy did.

**Inputs are only the entered rows.** Derived subject rows, result aggregates
and positions, and `exam_statistics` are all rebuilt from scratch in one
transaction, under a row lock on the exam, so a run is idempotent and concurrent
runs serialise. `legacy_*` columns are never written. It runs as the exam's own
school whatever the ambient tenant, so console, queue and HTTP agree.

Allowed while the exam is `marking` or `locked` (recomputing a locked exam is
harmless — its marks cannot change). `draft`, `open`, `published` and
`archived` are refused, the API answering 409: a published exam has report
cards in circulation.

| Service | Pure? | Does |
|---|---|---|
| `AggregationService` | yes | papers → subject score, per `aggregation_rule` |
| `RankingService` | yes | scores → positions, per `tie_policy` |
| `StatisticsService` | yes | results → `exam_statistics` rows, per `mean_policy` |
| `GradeResolver` | no (one per school) | picks the scale, resolves the band |
| `ExamComputationService` | no | loads, orchestrates, writes |

Rules, and the legacy behaviour each settles:

- **A paper with no entered row leaves its subject pending**: no derived score,
  student not complete. lower-classes rejected RDG without GRM;
  Upper-classes-combined treated a missing paper as 0, so a half-marked English
  looked like a genuinely low score.
- **An absent paper scores 0 but its max still counts.** Only when every paper
  is absent is the subject absent (null score, no band). Absent subjects are
  excluded from totals, means and subject statistics. Absent counts as entered,
  so an absentee is still complete.
- **A genuine 0 is a score.** UCC skipped any subject whose sum was 0.
- **Positions rank `total_marks`**: grade position within the grade, stream
  position within the class (legacy "streams" are today's classes), subject
  position within grade and subject. Ties compare at 2 decimal places. Under
  `legacy_ordinal`, ties break by ascending id — legacy's tiebreak was MySQL row
  order, which is not reproducible; imported exams carry `legacy_*` positions
  for exact comparison.
- **Overall statistics summarise `mean_marks`, not totals**, because totals are
  not comparable across levels that sit different numbers of subjects.
- **Scale precedence**: exam paper scale → level-subject scale → school default
  for the level → school default → platform default for the level → platform
  default. `legacy_broken` scales are never chosen, and a scale id belonging to
  another school is ignored rather than trusted.

`DemoSchoolSeeder` enters only paper marks and then runs the real pipeline, so
the rows the marklist reads come from the same code a school uses.

### Exam lifecycle

`draft <-> open -> marking <-> locked -> published -> archived`, moved only by
`POST /api/exams/{exam}/transition` (school admin) through `ExamLifecycleService`.
Legacy `ExamsController::update` set any status on any exam.

- **→ open**: the exam must cover at least one class. If no subjects were chosen
  it takes every active subject at its classes' levels, each paper at its
  curriculum default marks-out-of. A hand-made selection is left alone.
- **→ locked**: results are computed synchronously.
- **→ published**: stamps `published_at`. Nothing leaves `published` except
  `archived`; correcting published results is a data fix, not a button.

Only `marking` accepts marks. Marks-out-of may change in `draft`, `open` and
`marking`, and is frozen from `locked` on.

### Mark entry

A **mark sheet** is one exam × one class × one subject. `MarkingController`
over `MarksheetService` (the exam's rules) and `MarkingAccess` (who may touch
which sheet):

| Endpoint | Who |
|---|---|
| `GET /api/exams/{exam}/marking` — sheets I can open, with progress | admin, examiner, class teacher |
| `GET /api/exams/{exam}/marksheet?class_id=&level_subject_id=` | as above, per sheet |
| `PUT /api/exams/{exam}/marksheet` — bulk save | admin, assigned examiner |
| `PUT /api/exams/{exam}/max-marks` | admin; an examiner only for one class they mark |
| `GET`/`PUT /api/me/selected-exam` | anyone signed in |

**Access is the full triple — class, subject, and the exam's academic year.**
Legacy JSS checked `(examiner, subject)` and ignored the class, so an examiner
assigned English in one Grade 7 class could mark English in all of them; its
Paper1/Paper2 branch skipped the check entirely. A term-scoped assignment only
applies to that term's exams. Class teachers of the class can view, not enter.

Saving is **all or nothing**. Each row is `{enrolment_id, subject_paper_id,
raw_score, is_absent}`:

- The max comes only from the exam's configuration (class override → exam-wide
  → curriculum default) and is **snapshotted** onto the score. JSS used a
  marks-out-of sent by the client.
- Marks are range-checked 0..max; an absent row may not carry a mark, and needs
  a max to exist too (a null snapshot would break the whole exam's computation).
- The enrolment must be in this class for the exam's year. Legacy looked the
  enrolment up by `student_id` alone.
- `raw_score: null` with `is_absent: false` **clears** the mark, returning the
  subject to pending.
- Rows whose value has not changed are not rewritten, so re-saving a whole
  sheet keeps who entered each mark (`entered_by`, `entered_at`).

**Changing marks-out-of under existing marks is refused (409)** unless the
request sets `apply_to_existing`, which re-snapshots those marks and rescales
them; no existing mark may exceed the new max. An exam-wide change leaves
classes that have their own override alone. Legacy let any examiner change it
at any time, silently changing what entered marks meant.

### Recomputation after saves

A save that changes anything stamps `exams.marks_changed_at` and dispatches
`App\Jobs\ComputeExamResults` on the dedicated `results` queue, after the
transaction commits. The job is `ShouldBeUniqueUntilProcessing` on the exam id,
so a burst of saves collapses into one run while a save made during a run still
queues another. Its lock expires after 5 minutes (`uniqueFor`): a job lost before
a worker starts it would otherwise hold the lock for a day and silently swallow
every later dispatch for that exam.

Results carry their own freshness. `ExamComputationService` stamps
`exams.results_computed_at` with the moment it **starts** reading marks, both at
microsecond precision, so a mark saved mid-run still reads as stale. The
marking, marksheet, marklist and exam responses expose `results_up_to_date`.

`exams:recompute-stale` (every 5 minutes) re-queues any computable exam still
stale after a 2-minute grace, releasing a lost job's lock first. Lost jobs,
failed jobs and downtime heal on their own.

The job holds the exam **id**, not the model: `SerializesModels` would re-fetch
the exam through `TenantScope` on a worker with no tenant, fail closed, and
throw.

**Operating it** — workers, scheduler, alerting, deploys — is in
[deployment-vps.md](./deployment-vps.md).

---

## 5. Grading

`grading_scales` + `grade_bands` replace `point_boundaries`.

Legacy was broken two ways at once. Its 48 seeded rows (four per subject) all had
`min_marks = 0, max_marks = 100`, and the lookup was
`... BETWEEN min_marks AND max_marks LIMIT 1` **with no `ORDER BY`** — so the
first row always won and **every pupil in the school read "EXCEEDING
EXPECTATIONS"**.

`GradingScale::validateBands()` returns human-readable problems for overlaps,
gaps and inverted ranges. `PlatformGradingScaleSeeder` asserts it returns empty
before finishing, and `GradingScaleValidateBandsTest` pins it against the exact
legacy shape.

The platform default (`school_id` NULL) is four contiguous bands: BE-1 0–25,
AE-2 26–50, ME-3 51–75, EE-4 76–100.

### Marks are rounded half-up before band lookup

Bands are whole-number ranges but scores are decimals, and aggregation produces
fractions — a two-paper English at 50.50 falls between AE-2's 50 and ME-3's 51
and matches no band. **Always resolve through `GradingScale::bandFor()`**, which
rounds half-up first; never compare a raw score against `min_marks`/`max_marks`
directly.

Half-up is what legacy did implicitly: it wrote the float from
`((RDG + GRM) / combinedMax) * 100` into the `INT` column `exam_results.English`,
and MySQL rounds on insert. Matching it keeps imported pupils in the band their
printed report card shows. `GradingScaleValidateBandsTest` checks that every
hundredth from 0.00 to 100.00 resolves.

---

## 6. Other legacy defects the schema closes

- **Cross-year result bleed.** Legacy declared `exam_results.student_class_id`
  NOT NULL but then read by `student_id` alone, so historical results leaked
  across academic years. Results now hang off `enrolment_id`, with
  `unique(['exam_id', 'enrolment_id'])`.
- **Free-text class names.** `students.class` was a VARCHAR with nothing keeping
  it agreeing with `classes.class_name`. `class_aliases` is the permanent
  reconciler for the five legacy columns that held such strings.
- **Four incompatible examiner models.** JSS had `examiner_subject_classes`
  triples; upper-classes had `examiner_subjects` × `examiner_classes`;
  lower-classes and UCC had only `examiners.class_assigned`, so an examiner
  implicitly marked every subject in their class. All collapse onto
  `examiner_assignments`, with `origin` tagging the inferred ones.
- **Write-on-read.** `MarkListController` recomputed totals and positions and
  wrote them back to `exam_results` on every page load. Viewing a marklist
  mutated it. The new endpoint is strictly read-only
  (`MarklistApiTest::test_reading_a_marklist_does_not_write_to_it`).

---

## 7. Layout

```
app/Domain/
  Academics/    AcademicYear, Term, Level, Grade, Stream, SchoolClass, ClassAlias
  Curriculum/   Subject, LevelSubject, SubjectPaper, SubjectAlias + AggregationRule
  Examination/  Exam, ExamSubject, ExamSubjectPaper, ExamStudentResult,
                ExamSubjectScore, ExamStatistic + enums + Repositories/
  Grading/      GradingScale, GradeBand
  People/       User, Role, Student, StudentEnrolment, assignments, UserPreference
  Tenancy/      School, SchoolDomain, SchoolSetting, TenantManager, TenantScope,
                BelongsToSchool
app/Http/       Controllers/Api, Middleware, Requests, Resources
```

Models use `protected $guarded = ['id']`; the migrations are the authority on
columns. Factories mirror the domain namespaces under
`database/factories/Domain/...`.

**Tenant-owned factories pass `school_id` explicitly.** Relying on
`BelongsToSchool`'s stamping would attach rows to whichever school the test
happened to have resolved.

---

## 8. Conversion status

Verified 2026-09-13: `migrate:fresh --seed` clean on MySQL, 209/209 tests
passing, the computation, marking and queue-operations code mutation-checked (31 injected bugs, all caught),
and the slice exercised by hand against `php artisan serve`: login, tenant
isolation between two seeded schools, marklist, role refusals, superadmin
`X-School`, token revocation, computing exams through the API and console, and the
full marking workflow (create → open → mark → queue worker recomputes → lock →
publish → edits refused) with a real database queue worker, and the checked-in
Supervisor config run under a real supervisord through an outage: stale flags,
`/up` failing, one alert mail, recovery mail, a lost job healed, graceful
`queue:restart`.

| Area | State |
|---|---|
| Schema (30+ tables) | done, migrates on MySQL |
| Domain models + tenancy kernel | done |
| Tenancy resolution + enforcement | done, tested |
| Sanctum auth, roles | done, tested |
| Reference + demo seeders, factories | done |
| Exams API, marklist read endpoint | done, tested |
| Computation pipeline (aggregation, grading, ranking, statistics) + compute endpoint and command | done, tested |
| Exam lifecycle, mark entry, marks-out-of, selected exam, queued recompute | done, tested |
| Queue operations: dedicated workers, stale-results self-healing, heartbeats, `/up`, ops email, Supervisor + cron config | done, tested, run under real Supervisor |
| Reports / report cards / PDF | not started — legacy `lower-classes` reports are still changing on `Dev` and must be tracked |
| Legacy ETL + `legacy:verify` | not started |
| Analytics (MSS, stream lists) | not started |
| SMS | not started — legacy only ever appended to `logs/sms.log`; no gateway existed |
| Fees, attendance, timetable, parent/student portals | absent from legacy; new build, not conversion |

Remaining conversion work, in the order it unblocks the most: reports and
report cards (PDF), then the legacy ETL, then analytics.

---

## 9. Running it

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed     # MySQL: educa_lara
php artisan test                     # MySQL: educa_lara_test
composer run dev                     # serve + queue:listen on every queue + logs + vite
```

Seeded logins (password `password` for all): `superadmin` (platform, needs an
`X-School` header), and `admin` / `teacher` / `examiner` in each of the `gatimu`
and `riverside` demo schools.

```bash
TOKEN=$(curl -s -X POST localhost:8000/api/auth/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"username":"admin","password":"password","school":"gatimu"}' \
  | python3 -c 'import sys,json; print(json.load(sys.stdin)["data"]["token"])')

curl -s localhost:8000/api/exams -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json'
```
