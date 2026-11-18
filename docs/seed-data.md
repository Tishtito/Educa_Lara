# Gatimu test seed

A full, **synthetic** test school layered onto the local database's Gatimu
Primary School (slug `gatimu`), for trying staff, pupils, class assignments and
timetabling end to end. Local and test databases only — every name is made up,
and none of it should ever reach a real school.

## Running it

```sh
php artisan migrate            # migrate:fresh --seed if Gatimu does not exist yet
php artisan db:seed --class='Database\Seeders\Gatimu\GatimuTestSchoolSeeder'
```

- **Adds only.** Every part upserts; nothing already in Gatimu is deleted, and
  accounts outside the roster are never touched. Safe to re-run — a second run
  changes nothing.
- **Refuses production** (`app()->isProduction()` throws).
- A roster username or email already used by another school's account is
  reported and skipped, never taken over.

Order: `StaffSeeder` → `ClassesAndPupilsSeeder` → `TimetableInputsSeeder`.

## What it contains

- **26 teachers** from `GatimuStaffRoster` (below). Everyone holds Teacher and
  Examiner; 18 are also Class Teacher.
- **18 classes**: Grade 1–9, streams Blue and Red, about 30 pupils each.
- **Class teachers and examiners** assigned to those classes.
- **Timetable inputs**: the subjects each teacher can teach, bell schedules and
  lessons per week per subject. **No timetable is generated** — generate one
  from the app.

| Username | Name | Class teacher of | Subjects (level: codes) |
|---|---|---|---|
| gwanjiku | Grace Wanjiku | Grade 1 Blue | LP: MAT ENG ENV |
| fatieno | Faith Atieno | Grade 1 Red | LP: MAT KIS CRE |
| mnjeri | Mercy Njeri | Grade 2 Blue | LP: ENG KIS CRA |
| lchebet | Lucy Chebet | Grade 2 Red | LP: ENV CRA CRE |
| amumbua | Ann Mumbua | Grade 3 Blue | LP: MAT ENG CRA |
| jachieng | Joyce Achieng | Grade 3 Red | LP: KIS ENV CRA |
| pmwangi | Peter Mwangi | Grade 4 Blue | UP: MAT SCI |
| ewambui | Esther Wambui | Grade 4 Red | UP: ENG CRE |
| homar | Hassan Omar | Grade 5 Blue | UP: KIS SST |
| dkiptoo | Daniel Kiptoo | Grade 5 Red | UP: SCI AGN |
| rnyambura | Rose Nyambura | Grade 6 Blue | UP: MAT AGN |
| sotieno | Samuel Otieno | Grade 6 Red | UP: ENG SST CRA |
| bkerubo | Beatrice Kerubo | — | UP: KIS CRA CRE |
| jkamau | John Kamau | Grade 7 Blue | JS: MAT PTS |
| mwairimu | Mary Wairimu | Grade 7 Red | JS: ENG CRE |
| iabdi | Ibrahim Abdi | Grade 8 Blue | JS: KIS SST |
| cmoraa | Catherine Moraa | Grade 8 Red | JS: ISC AGR |
| kouma | Kevin Ouma | Grade 9 Blue | JS: MAT ISC |
| pjepkosgei | Purity Jepkosgei | Grade 9 Red | JS: ENG CRA |
| mmutua | Moses Mutua | — | JS: PTS AGR |
| nwanjala | Naomi Wanjala | — | JS: KIS CRA CRE |
| vkiprono | Victor Kiprono | — | JS: SST ISC |
| emuthoni | Elizabeth Muthoni | — | LP/UP/JS: MAT |
| gnjoroge | George Njoroge | — | UP/JS: ENG |
| aakoth | Alice Akoth | — | LP/UP/JS: CRE |
| bkibet | Brian Kibet | — | UP: SCI; JS: ISC AGR |

LP = lower primary (Grade 1–3), UP = upper primary (Grade 4–6), JS = junior
secondary (Grade 7–9). Emails are `<username>@gatimu.educa.test`.

## Signing in

Every roster account has the password **`password`** and is not asked to change
it (a local testing convenience set by `StaffSeeder`).

- Admin app: `admin` / `password`
- Staff portal: any roster username, e.g. `gwanjiku` / `password`
