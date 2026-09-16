<?php

declare(strict_types=1);

use App\Domain\People\Models\Role;
use App\Http\Controllers\Api\Academics\AcademicYearController;
use App\Http\Controllers\Api\Academics\SchoolClassController;
use App\Http\Controllers\Api\Academics\StreamController;
use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\Auth\AccountLinkController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Curriculum\LevelSubjectController;
use App\Http\Controllers\Api\Curriculum\SubjectController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExamComputationController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ExamLifecycleController;
use App\Http\Controllers\Api\ExamSetupController;
use App\Http\Controllers\Api\Grading\GradingScaleController;
use App\Http\Controllers\Api\MarkingController;
use App\Http\Controllers\Api\MarklistController;
use App\Http\Controllers\Api\People\AssignmentController;
use App\Http\Controllers\Api\People\StaffController;
use App\Http\Controllers\Api\People\StudentController;
use App\Http\Controllers\Api\People\StudentImportController;
use App\Http\Controllers\Api\Platform\QueueHealthController;
use App\Http\Controllers\Api\Platform\SchoolController as PlatformSchoolController;
use App\Http\Controllers\Api\Portal\ClassPupilsController;
use App\Http\Controllers\Api\Portal\MeController;
use App\Http\Controllers\Api\ReferenceDataController;
use App\Http\Controllers\Api\ReportCardBatchController;
use App\Http\Controllers\Api\ReportCardController;
use App\Http\Controllers\Api\ReportCardSettingsController;
use App\Http\Controllers\Api\ReportEntryController;
use App\Http\Controllers\Api\SchoolProfileController;
use App\Http\Controllers\Api\SelectedExamController;
use App\Http\Controllers\Api\TermController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Replaces the ten separate JSON backends of the legacy system (four admin,
| three class-teacher, three examiner), each of which was a copy of the same
| routes pointed at a different database.
|
| MIDDLEWARE ORDER MATTERS. 'tenant' must follow 'auth:sanctum': with bearer
| tokens the authenticated user is the primary tenant signal, and route
| middleware runs in the order listed. Any authenticated group written without
| 'tenant' will still be safe — TenantScope fails closed and returns nothing —
| but it will be useless, which is the intended failure mode.
|
*/

Route::prefix('auth')->group(function (): void {
    // Throttled: legacy had no rate limiting on login at all, and its login form
    // was reachable on every deployment's public hostname.
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:6,1,login');

    // Emailed links (AccountLinkController). Public: the token is the credential.
    // Every throttle here names its own counter (the third argument): unnamed
    // ones share one counter per IP, so opening an invitation and signing in
    // would use up the forgot-password allowance — and a school's staff share an IP.
    Route::get('invitations/{token}', [AccountLinkController::class, 'showInvitation'])->middleware('throttle:20,1,invitation');
    Route::post('invitations/{token}/accept', [AccountLinkController::class, 'acceptInvitation'])->middleware('throttle:6,1,invitation-accept');
    Route::post('forgot-password', [AccountLinkController::class, 'forgotPassword'])->middleware('throttle:5,1,forgot-password');
    Route::get('reset-password/{token}', [AccountLinkController::class, 'showReset'])->middleware('throttle:20,1,password-reset');
    Route::post('reset-password/{token}', [AccountLinkController::class, 'reset'])->middleware('throttle:6,1,password-reset-set');

    Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('password', [AuthController::class, 'changePassword'])->middleware('throttle:6,1,password-change');
    });
});

// 'password.changed': a user who must set a new password (legacy imports) can
// reach only the auth routes above until they do.
Route::middleware(['auth:sanctum', 'tenant', 'password.changed'])->group(function (): void {
    // Lookup lists for building forms. Every role: a class teacher picking a
    // class needs the same list an admin does.
    Route::get('levels', [ReferenceDataController::class, 'levels']);
    Route::get('grades', [ReferenceDataController::class, 'grades']);
    Route::get('academic-years', [ReferenceDataController::class, 'academicYears']);
    Route::get('streams', [ReferenceDataController::class, 'streams']);
    Route::get('classes', [ReferenceDataController::class, 'classes']);

    Route::get('exams', [ExamController::class, 'index']);
    Route::get('exams/{exam}', [ExamController::class, 'show']);
    Route::post('exams', [ExamController::class, 'store'])
        ->middleware('role:'.Role::SCHOOL_ADMIN);

    Route::post('exams/{exam}/transition', ExamLifecycleController::class)
        ->middleware('role:'.Role::SCHOOL_ADMIN);

    // Marking. Role gates who may reach these at all; MarkingAccess then decides
    // per sheet (class x subject x the exam's academic year).
    Route::middleware('role:'.Role::SCHOOL_ADMIN.','.Role::EXAMINER.','.Role::CLASS_TEACHER)->group(function (): void {
        Route::get('exams/{exam}/marking', [MarkingController::class, 'index']);
        Route::get('exams/{exam}/marksheet', [MarkingController::class, 'show']);
    });

    Route::middleware('role:'.Role::SCHOOL_ADMIN.','.Role::EXAMINER)->group(function (): void {
        Route::put('exams/{exam}/marksheet', [MarkingController::class, 'update']);
        Route::put('exams/{exam}/max-marks', [MarkingController::class, 'setMaxMarks']);
    });

    // Report cards. Role gates reach; ReportAccess then allows an admin, or the
    // class teacher of the class in the exam's year. Examiners do not issue cards.
    Route::middleware('role:'.Role::SCHOOL_ADMIN.','.Role::CLASS_TEACHER)->group(function (): void {
        Route::get('exams/{exam}/report-cards', [ReportCardController::class, 'index']);
        Route::get('exams/{exam}/report-cards/{enrolment}', [ReportCardController::class, 'show']);
        Route::get('exams/{exam}/report-cards/{enrolment}/pdf', [ReportCardController::class, 'pdf']);

        Route::post('exams/{exam}/report-card-batches', [ReportCardBatchController::class, 'store']);
        Route::get('report-card-batches/{batch}', [ReportCardBatchController::class, 'show']);
        Route::get('report-card-batches/{batch}/download', [ReportCardBatchController::class, 'download']);

        Route::get('exams/{exam}/report-entries', [ReportEntryController::class, 'index']);
        Route::put('exams/{exam}/report-entries', [ReportEntryController::class, 'update']);
    });

    Route::middleware('role:'.Role::SCHOOL_ADMIN)->group(function (): void {
        Route::get('dashboard', DashboardController::class);
        Route::get('exams/{exam}/stream-list', [AnalysisController::class, 'streamList']);
        Route::get('exams/{exam}/statistics', [AnalysisController::class, 'statistics']);
        Route::get('school/profile', [SchoolProfileController::class, 'show']);
        Route::patch('school/profile', [SchoolProfileController::class, 'update']);
        Route::get('school/logo', [SchoolProfileController::class, 'logo']);
        Route::post('school/logo', [SchoolProfileController::class, 'uploadLogo']);
        Route::delete('school/logo', [SchoolProfileController::class, 'deleteLogo']);

        // Exam set-up after creation. Classes, subjects and rules change only
        // while an exam is a draft (ExamSetupService).
        Route::patch('exams/{exam}', [ExamSetupController::class, 'update']);
        Route::delete('exams/{exam}', [ExamSetupController::class, 'destroy']);
        Route::get('exams/{exam}/setup', [ExamSetupController::class, 'show']);
        Route::put('exams/{exam}/subjects', [ExamSetupController::class, 'updateSubjects']);

        // Academic calendar, streams and classes. Lists are the lookup routes above.
        Route::post('academic-years', [AcademicYearController::class, 'store']);
        Route::patch('academic-years/{academicYear}', [AcademicYearController::class, 'update']);
        Route::post('academic-years/{academicYear}/make-current', [AcademicYearController::class, 'makeCurrent']);
        Route::delete('academic-years/{academicYear}', [AcademicYearController::class, 'destroy']);
        Route::post('terms', [TermController::class, 'store']);
        Route::post('terms/{term}/make-current', [TermController::class, 'makeCurrent']);
        Route::post('streams', [StreamController::class, 'store']);
        Route::patch('streams/{stream}', [StreamController::class, 'update']);
        Route::delete('streams/{stream}', [StreamController::class, 'destroy']);
        Route::post('classes', [SchoolClassController::class, 'store']);
        Route::patch('classes/{schoolClass}', [SchoolClassController::class, 'update']);
        Route::delete('classes/{schoolClass}', [SchoolClassController::class, 'destroy']);

        // Curriculum and grading.
        Route::get('subjects', [SubjectController::class, 'index']);
        Route::post('subjects', [SubjectController::class, 'store']);
        Route::patch('subjects/{subject}', [SubjectController::class, 'update']);
        Route::delete('subjects/{subject}', [SubjectController::class, 'destroy']);
        Route::get('level-subjects', [LevelSubjectController::class, 'index']);
        Route::post('level-subjects', [LevelSubjectController::class, 'store']);
        Route::post('level-subjects/defaults', [LevelSubjectController::class, 'applyDefaults']);
        Route::patch('level-subjects/{levelSubject}', [LevelSubjectController::class, 'update']);
        Route::delete('level-subjects/{levelSubject}', [LevelSubjectController::class, 'destroy']);
        Route::get('grading-scales', [GradingScaleController::class, 'index']);
        Route::post('grading-scales', [GradingScaleController::class, 'store']);
        Route::patch('grading-scales/{gradingScale}', [GradingScaleController::class, 'update'])->whereNumber('gradingScale');
        Route::put('grading-scales/{gradingScale}/bands', [GradingScaleController::class, 'updateBands'])->whereNumber('gradingScale');
        Route::post('grading-scales/{gradingScale}/make-default', [GradingScaleController::class, 'makeDefault'])->whereNumber('gradingScale');
        Route::delete('grading-scales/{gradingScale}', [GradingScaleController::class, 'destroy'])->whereNumber('gradingScale');

        // People: staff accounts, who teaches and marks what, pupils.
        Route::get('staff', [StaffController::class, 'index']);
        Route::post('staff', [StaffController::class, 'store']);
        Route::patch('staff/{staff}', [StaffController::class, 'update'])->whereNumber('staff');
        Route::post('staff/{staff}/reset-password', [StaffController::class, 'resetPassword'])->whereNumber('staff');
        Route::post('staff/{staff}/invitation', [StaffController::class, 'sendInvitation'])->whereNumber('staff');
        Route::delete('staff/{staff}/invitation', [StaffController::class, 'cancelInvitation'])->whereNumber('staff');
        Route::delete('staff/{staff}', [StaffController::class, 'destroy'])->whereNumber('staff');
        Route::get('class-teacher-assignments', [AssignmentController::class, 'classTeachers']);
        Route::put('classes/{schoolClass}/class-teachers', [AssignmentController::class, 'setClassTeachers']);
        Route::get('examiner-assignments', [AssignmentController::class, 'examiners']);
        Route::put('classes/{schoolClass}/examiners', [AssignmentController::class, 'setExaminers']);
        Route::post('assignments/copy-year', [AssignmentController::class, 'copyYear']);
        Route::get('students', [StudentController::class, 'index']);
        Route::post('students', [StudentController::class, 'store']);
        Route::post('students/import', [StudentImportController::class, 'import']);
        Route::get('students/{student}', [StudentController::class, 'show']);
        Route::patch('students/{student}', [StudentController::class, 'update']);
        Route::delete('students/{student}', [StudentController::class, 'destroy']);
        Route::post('students/{student}/move', [StudentController::class, 'move']);
        Route::post('students/{student}/status', [StudentController::class, 'status']);
        Route::get('students/{student}/results', [StudentController::class, 'results']);
        Route::get('promotions/preview', [StudentImportController::class, 'previewPromotion']);
        Route::post('promotions', [StudentImportController::class, 'promote']);

        Route::get('terms', [TermController::class, 'index']);
        Route::patch('terms/{term}', [TermController::class, 'update']);
        Route::get('school/report-card-settings', [ReportCardSettingsController::class, 'show']);
        Route::put('school/report-card-settings', [ReportCardSettingsController::class, 'update']);
    });

    // Staff portal: what I teach and mark, and the exams that involve me.
    Route::get('me/assignments', [MeController::class, 'assignments']);
    Route::get('me/exams', [MeController::class, 'exams']);

    // A class teacher's pupils. ClassTeachingAccess limits each call to the
    // teacher's own classes; admins pass the role gate and every check.
    Route::middleware('role:'.Role::CLASS_TEACHER)->prefix('my')->group(function (): void {
        Route::get('classes/{schoolClass}/students', [ClassPupilsController::class, 'index']);
        Route::post('classes/{schoolClass}/students', [ClassPupilsController::class, 'store']);
        Route::get('students/{student}', [ClassPupilsController::class, 'show']);
        Route::get('students/{student}/results', [ClassPupilsController::class, 'results']);
        Route::post('students/{student}/move', [ClassPupilsController::class, 'move']);
    });

    Route::get('me/selected-exam', [SelectedExamController::class, 'show']);
    Route::put('me/selected-exam', [SelectedExamController::class, 'update']);

    Route::post('exams/{exam}/compute', ExamComputationController::class)
        ->middleware('role:'.Role::SCHOOL_ADMIN);

    Route::get('exams/{exam}/marklist', MarklistController::class)
        ->middleware('role:'.Role::SCHOOL_ADMIN.','.Role::CLASS_TEACHER);
});

// Platform operations. Superadmin only, and deliberately without 'tenant': these
// describe shared infrastructure, not a school.
Route::middleware(['auth:sanctum', 'password.changed', 'role:'.Role::SUPER_ADMIN])->prefix('platform')->group(function (): void {
    Route::get('queue-health', QueueHealthController::class);
    Route::get('schools', [PlatformSchoolController::class, 'index']);
    Route::post('schools', [PlatformSchoolController::class, 'store']);
    Route::patch('schools/{uuid}', [PlatformSchoolController::class, 'update'])->whereUuid('uuid');
});
