# Teaching materials

Files a school shares with its teachers: schemes of work, notes, past papers,
slides. Admins upload and tag them in Elimu Pass Admin; teachers browse and
download them in Elimu Pass Portal. New build — legacy had nothing like it.

Code: `app/Domain/Resources/`, `TeachingMaterialController` (admin),
`Portal\MyMaterialsController` (portal). Tests: `TeachingMaterialsApiTest`.

## The model

| | |
|---|---|
| Section | `level_id`, required. Every material belongs to exactly one section (lower primary, upper primary, junior secondary). |
| Grades | `teaching_material_grades`, optional. **No grade rows = the whole section.** Every grade must be in the material's section. |
| Subject | `level_subject_id`, optional. Must be the school's own and taught in the material's section. |
| Status | `draft` or `published`. Teachers see only published. `published_at` is stamped on first publication only, so hiding and re-showing a file does not mark it "New" again. |
| Year | None. Materials are timeless, like classes. |

A grade filter returns materials tagged with that grade **plus** untagged
materials of its section (`TeachingMaterial::scopeForGrades`), so a lower
primary handbook shows under Grades 1, 2 and 3.

## Files

- **Private disk only** (`config('elimupass.materials.disk')`, default `local` =
  `storage/app/private`). Never the public disk: a public URL would let anyone
  with the link, including other schools, download the file. Files leave the
  server only through the two `download` routes (`ServesMaterialFiles`).
- Path: `schools/{school uuid}/materials/{material uuid}.{ext}`. The original
  file name lives only in the database. `disk` and `path` are stored per row,
  so moving to S3 is a config change.
- **Types:** PDF, Word, PowerPoint, Excel, JPEG, PNG. The extension and the type
  read from the content must agree (`TeachingMaterialRules::file`): a renamed
  `.exe`, or a PDF renamed `.png`, is refused. No video yet.
- **Size:** 20 MB per file (`MATERIALS_MAX_KB`). No per-school quota.
- **Duplicates:** uploads and replacements answer `duplicate_of` when the school
  already has a file with the same SHA-256. A warning, not a refusal.
- **Downloads:** named from the title, slugged; PDFs and images `inline`, the
  rest `attachment`; always `X-Content-Type-Options: nosniff` and
  `Cache-Control: private, no-store`.
- **Replacing** writes the new file first, updates the row, and deletes the old
  file after commit, so a failure never leaves a material with no file.
- **Deleting** is a soft delete, restorable for `MATERIALS_RETENTION_DAYS`
  (30). `materials:prune` (daily) then removes the row and the file.

## Who sees what

| Permission | Allows | Default holders |
|---|---|---|
| `manage_teaching_materials` | Everything in the admin app, drafts included | `school_admin` (automatically) |
| `view_teaching_materials` | Browse and download published materials in the portal | `class_teacher`, `examiner`, `teacher` |

Record rules live in `TeachingMaterialAccess`:

- Teachers see **every published material of their school**, any grade —
  teachers cover other grades, and the files are not sensitive. To restrict
  them to their own grades, change `visibleTo()`; it is the only place the rule
  lives.
- The portal **opens on "My grades"** (`myGradeIds`): the grades of the
  teacher's classes this year — class teacher, examiner, pinned subject
  teacher, or a lesson in the current published timetable (subject teachers are
  usually placed only by the generator). With none of those, the grades of the
  sections they ticked subjects at.

**Paid feature:** every route is inside `subscribed` (402 once the
subscription lapses), like exams and timetables. `SubscriptionLockTest` lists
them.

## API

Admin (`can:manage_teaching_materials`):

| | |
|---|---|
| `GET /teaching-materials?level_id=&grade_id=&level_subject_id=&status=&q=&deleted=1&page=` | Paged list |
| `GET /teaching-materials/summary` | Counts per section (with drafts) and per grade |
| `POST /teaching-materials` multipart `file, title?, description?, level_id, grade_ids[]?, level_subject_id?, status?` | Upload (default `published`), 30 a minute |
| `PATCH /teaching-materials/{id}` | Edit. Changing section without sending grades clears them |
| `POST /teaching-materials/{id}/file` | Replace the file |
| `DELETE /teaching-materials/{id}` / `POST …/restore` | Soft delete / undo |
| `GET /teaching-materials/{id}/download` | The file, drafts too |

Portal (`can:view_teaching_materials`):

| | |
|---|---|
| `GET /my/teaching-materials?level_id=&grade_id=&level_subject_id=&q=` | Published only. Without `level_id`/`grade_id`: the teacher's grades. `meta.my_grade_ids` |
| `GET /my/teaching-materials/summary` | Counts, `my_grade_ids`, `new_for_my_grades` (last 7 days) |
| `GET /my/teaching-materials/{id}/download` | The file; drafts and deleted answer 404 |

## Operations

- PHP `upload_max_filesize` and `post_max_size` **25M**, and nginx
  `client_max_body_size 25m`, or uploads near the limit fail before Laravel
  sees them (nginx answers 413).
- Back up `storage/app/private/schools/*/materials` with the database.
- After deploying: `php artisan migrate`, then `php artisan permissions:sync`
  (it gives `view_teaching_materials` to schools that edited the teacher roles,
  as `docs/permissions.md` describes).
