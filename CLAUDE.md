# Educa

Multi-school (SaaS) Laravel 13 rewrite of `Educa_vol_1`, a PHP school-management
system that shipped as four near-duplicate codebases against three separate
MySQL databases.

**Read `docs/ARCHITECTURE.md` before changing anything.** Most of the design here
is a response to a specific defect in the old system, and the reasoning is not
recoverable from the code alone.

## The rules that matter

1. **Tenancy fails closed.** `TenantScope` applies `whereRaw('1 = 0')` when no
   tenant is resolved. Never "fix" an empty result by loosening it.
2. **`TenantManager` must stay a singleton** (`AppServiceProvider::register()`).
   Unbind it and every scoped query silently returns nothing.
3. **Raw SQL only in `Repositories/`, and every repository takes `int $schoolId`
   as a required constructor argument.** Global scopes do not reach
   `DB::table()`. `tests/Feature/ArchitectureTest.php` enforces both halves.
4. **`Rule::exists` is not tenant-scoped** — it uses the query builder, not
   Eloquent. Add `->where('school_id', $schoolId)` to every exists rule against a
   tenant-owned table.
5. **`tenant` runs after `auth:sanctum` and before route model binding.** It is
   listed per route group (never appended to the API group), and
   `bootstrap/app.php` prioritises it ahead of `SubstituteBindings`. Get either
   wrong and bound models 404 for their own school.
6. **Resolve grade bands only through `GradingScale::bandFor()`.** It rounds
   half-up first; bands are whole-number ranges and a raw decimal like 50.50
   falls between them.
7. **MySQL identifiers max out at 64 chars.** Name composite indexes explicitly
   (`esr_exam_class_total_idx` style) — SQLite never complains.
8. **Derived data is output, never input.** Derived subject rows, result
   aggregates, positions and `exam_statistics` are rebuilt by
   `ExamComputationService` from entered paper rows. Never write them from a
   read path or by hand — change the entered marks and recompute.
9. **Tenant-owned factories pass `school_id` explicitly.** Do not rely on
   `BelongsToSchool` stamping it from the ambient tenant.

## Conventions

- `declare(strict_types=1)` in every file.
- Models use `protected $guarded = ['id']`; migrations are the authority on
  columns.
- Comments explain *why*, and cite the legacy file and line where a decision
  reverses a specific old behaviour. Keep that habit — it is how the conversion
  stays reviewable.
- PHPUnit, not Pest. Tests run against MySQL (`educa_lara_test`), not SQLite.
- `./vendor/bin/pint` before committing.

## Legacy source

`../Educa_vol_1` — `lower-classes/` is the most current deployment and the best
reference for behaviour.
