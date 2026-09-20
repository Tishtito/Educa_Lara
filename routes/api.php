<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Academics\AcademicYearController;
use App\Http\Controllers\Api\Academics\SchoolClassController;
use App\Http\Controllers\Api\Academics\StreamController;
use App\Http\Controllers\Api\AnalysisController;
use App\Http\Controllers\Api\Auth\AccountLinkController;
use App\Http\Controllers\Api\Auth\GoogleAuthController;
use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Billing\BillingController;
use App\Http\Controllers\Api\Billing\MpesaWebhookController;
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
use App\Http\Controllers\Api\People\RoleController;
use App\Http\Controllers\Api\People\StaffController;
use App\Http\Controllers\Api\People\StudentController;
use App\Http\Controllers\Api\People\StudentImportController;
use App\Http\Controllers\Api\Platform\QueueHealthController;
use App\Http\Controllers\Api\Platform\SchoolController as PlatformSchoolController;
use App\Http\Controllers\Api\Platform\SubscriptionController as PlatformSubscriptionController;
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

    // Continue with Google (GoogleAuthController). 404 while no Google client is configured.
    Route::post('google', [GoogleAuthController::class, 'login'])->middleware('throttle:10,1,google');
    Route::post('invitations/{token}/google', [AccountLinkController::class, 'acceptInvitationWithGoogle'])->middleware('throttle:6,1,invitation-google');

    Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('password', [AuthController::class, 'changePassword'])->middleware('throttle:6,1,password-change');
        Route::delete('google', [GoogleAuthController::class, 'disconnect']);

        // Your own signed-in devices (SessionController).
        Route::get('sessions', [SessionController::class, 'index']);
        Route::delete('sessions', [SessionController::class, 'destroyOthers']);
        Route::delete('sessions/{session}', [SessionController::class, 'destroy'])->whereNumber('session');
    });
});

// 'password.changed': a user who must set a new password (legacy imports) can
// reach only the auth routes above until they do.
//
// PERMISSIONS, NOT ROLES. Every action names the permission it needs
// (`can:<slug>`, App\Domain\Access\PermissionRegistry). What each role holds is
// per school and editable (docs/permissions.md). The permission decides what
// KIND of action; controllers still ask the access services WHICH records —
// an examiner with enter_marks still marks only their assigned class x subject.
Route::middleware(['auth:sanctum', 'tenant', 'password.changed'])->group(function (): void {
    // Open to any signed-in staff: lookup lists for building forms, the exam
    // list, and the signed-in person's own assignments and chosen exam.
    Route::get('levels', [ReferenceDataController::class, 'levels']);
    Route::get('grades', [ReferenceDataController::class, 'grades']);
    Route::get('academic-years', [ReferenceDataController::class, 'academicYears']);
    Route::get('streams', [ReferenceDataController::class, 'streams']);
    Route::get('classes', [ReferenceDataController::class, 'classes']);
    Route::get('exams', [ExamController::class, 'index']);
    Route::get('exams/{exam}', [ExamController::class, 'show']);
    Route::get('me/assignments', [MeController::class, 'assignments']);
    Route::get('me/exams', [MeController::class, 'exams']);
    Route::get('me/selected-exam', [SelectedExamController::class, 'show']);
    Route::put('me/selected-exam', [SelectedExamController::class, 'update']);

    // Overview
    Route::get('dashboard', DashboardController::class)->middleware('can:view_dashboard');
    Route::get('exams/{exam}/stream-list', [AnalysisController::class, 'streamList'])->middleware(['can:view_exam_analysis', 'subscribed']);
    Route::get('exams/{exam}/statistics', [AnalysisController::class, 'statistics'])->middleware(['can:view_exam_analysis', 'subscribed']);

    // School profile, logo and report card settings
    Route::middleware('can:manage_school_profile')->group(function (): void {
        Route::get('school/profile', [SchoolProfileController::class, 'show']);
        Route::patch('school/profile', [SchoolProfileController::class, 'update']);
        Route::get('school/logo', [SchoolProfileController::class, 'logo']);
        Route::post('school/logo', [SchoolProfileController::class, 'uploadLogo']);
        Route::delete('school/logo', [SchoolProfileController::class, 'deleteLogo']);
        Route::get('school/report-card-settings', [ReportCardSettingsController::class, 'show']);
        Route::put('school/report-card-settings', [ReportCardSettingsController::class, 'update']);
    });

    // Academic calendar
    Route::middleware('can:manage_academic_calendar')->group(function (): void {
        Route::post('academic-years', [AcademicYearController::class, 'store']);
        Route::patch('academic-years/{academicYear}', [AcademicYearController::class, 'update']);
        Route::post('academic-years/{academicYear}/make-current', [AcademicYearController::class, 'makeCurrent']);
        Route::delete('academic-years/{academicYear}', [AcademicYearController::class, 'destroy']);
        Route::get('terms', [TermController::class, 'index']);
        Route::post('terms', [TermController::class, 'store']);
        Route::patch('terms/{term}', [TermController::class, 'update']);
        Route::post('terms/{term}/make-current', [TermController::class, 'makeCurrent']);
    });

    // Streams and classes
    Route::middleware('can:manage_classes')->group(function (): void {
        Route::post('streams', [StreamController::class, 'store']);
        Route::patch('streams/{stream}', [StreamController::class, 'update']);
        Route::delete('streams/{stream}', [StreamController::class, 'destroy']);
        Route::post('classes', [SchoolClassController::class, 'store']);
        Route::patch('classes/{schoolClass}', [SchoolClassController::class, 'update']);
        Route::delete('classes/{schoolClass}', [SchoolClassController::class, 'destroy']);
    });

    // Curriculum
    Route::middleware('can:manage_subjects')->group(function (): void {
        Route::get('subjects', [SubjectController::class, 'index']);
        Route::post('subjects', [SubjectController::class, 'store']);
        Route::patch('subjects/{subject}', [SubjectController::class, 'update']);
        Route::delete('subjects/{subject}', [SubjectController::class, 'destroy']);
        Route::get('level-subjects', [LevelSubjectController::class, 'index']);
        Route::post('level-subjects', [LevelSubjectController::class, 'store']);
        Route::post('level-subjects/defaults', [LevelSubjectController::class, 'applyDefaults']);
        Route::patch('level-subjects/{levelSubject}', [LevelSubjectController::class, 'update']);
        Route::delete('level-subjects/{levelSubject}', [LevelSubjectController::class, 'destroy']);
    });

    Route::middleware('can:manage_grading_scales')->group(function (): void {
        Route::get('grading-scales', [GradingScaleController::class, 'index']);
        Route::post('grading-scales', [GradingScaleController::class, 'store']);
        Route::patch('grading-scales/{gradingScale}', [GradingScaleController::class, 'update'])->whereNumber('gradingScale');
        Route::put('grading-scales/{gradingScale}/bands', [GradingScaleController::class, 'updateBands'])->whereNumber('gradingScale');
        Route::post('grading-scales/{gradingScale}/make-default', [GradingScaleController::class, 'makeDefault'])->whereNumber('gradingScale');
        Route::delete('grading-scales/{gradingScale}', [GradingScaleController::class, 'destroy'])->whereNumber('gradingScale');
    });

    // THE SUBSCRIPTION FEATURES. 'subscribed' answers 402 once the school's
    // subscription has lapsed (docs/billing.md); the rest of the app stays open
    // so the school can still pay and reach its own records.
    Route::middleware('subscribed')->group(function (): void {
        // Exams. Set-up changes shape marking and are draft-only (ExamSetupService).
        Route::post('exams', [ExamController::class, 'store'])->middleware('can:create_exams');
        Route::get('exams/{exam}/setup', [ExamSetupController::class, 'show'])->middleware('can:update_exams');
        Route::patch('exams/{exam}', [ExamSetupController::class, 'update'])->middleware('can:update_exams');
        Route::put('exams/{exam}/subjects', [ExamSetupController::class, 'updateSubjects'])->middleware('can:update_exams');
        Route::delete('exams/{exam}', [ExamSetupController::class, 'destroy'])->middleware('can:delete_exams');
        Route::post('exams/{exam}/transition', ExamLifecycleController::class)->middleware('can:change_exam_status');
        Route::post('exams/{exam}/compute', ExamComputationController::class)->middleware('can:compute_exam_results');

        // Marking. MarkingAccess then decides per sheet (class x subject x the
        // exam's year); mark_any_class lifts that to every sheet.
        Route::get('exams/{exam}/marking', [MarkingController::class, 'index'])->middleware('can:view_marksheets');
        Route::get('exams/{exam}/marksheet', [MarkingController::class, 'show'])->middleware('can:view_marksheets');
        Route::put('exams/{exam}/marksheet', [MarkingController::class, 'update'])->middleware('can:enter_marks');
        Route::put('exams/{exam}/max-marks', [MarkingController::class, 'setMaxMarks'])->middleware('can:set_max_marks');

        // Mark lists and report cards. ReportAccess then allows the class teacher of
        // the class in the exam's year; access_any_class lifts that to every class.
        Route::get('exams/{exam}/marklist', MarklistController::class)->middleware('can:view_marklists');
        Route::middleware('can:view_report_cards')->group(function (): void {
            Route::get('exams/{exam}/report-cards', [ReportCardController::class, 'index']);
            Route::get('exams/{exam}/report-cards/{enrolment}', [ReportCardController::class, 'show']);
            Route::get('exams/{exam}/report-cards/{enrolment}/pdf', [ReportCardController::class, 'pdf']);
            Route::post('exams/{exam}/report-card-batches', [ReportCardBatchController::class, 'store']);
            Route::get('report-card-batches/{batch}', [ReportCardBatchController::class, 'show']);
            Route::get('report-card-batches/{batch}/download', [ReportCardBatchController::class, 'download']);
        });
        Route::get('exams/{exam}/report-entries', [ReportEntryController::class, 'index'])->middleware('can:edit_report_entries');
        Route::put('exams/{exam}/report-entries', [ReportEntryController::class, 'update'])->middleware('can:edit_report_entries');
    });

    // Staff accounts, and who teaches and marks what.
    Route::get('staff', [StaffController::class, 'index'])->middleware('can:view_staff');
    Route::post('staff', [StaffController::class, 'store'])->middleware('can:create_staff');
    Route::patch('staff/{staff}', [StaffController::class, 'update'])->whereNumber('staff')->middleware('can:update_staff');
    Route::post('staff/{staff}/reset-password', [StaffController::class, 'resetPassword'])->whereNumber('staff')->middleware('can:reset_staff_passwords');
    Route::post('staff/{staff}/sign-out', [StaffController::class, 'signOut'])->whereNumber('staff')->middleware('can:sign_out_staff');
    Route::post('staff/{staff}/invitation', [StaffController::class, 'sendInvitation'])->whereNumber('staff')->middleware('can:invite_staff');
    Route::delete('staff/{staff}/invitation', [StaffController::class, 'cancelInvitation'])->whereNumber('staff')->middleware('can:invite_staff');
    Route::delete('staff/{staff}', [StaffController::class, 'destroy'])->whereNumber('staff')->middleware('can:delete_staff');

    // The school's own subscription and M-Pesa payments (BillingController).
    Route::middleware('can:manage_billing')->group(function (): void {
        Route::get('billing', [BillingController::class, 'show']);
        Route::post('billing/stk', [BillingController::class, 'stk'])->middleware('throttle:5,1,billing-stk');
        Route::get('billing/payments/{payment}', [BillingController::class, 'payment'])->whereNumber('payment');
    });

    // Roles and what they may do, per school (RoleService).
    Route::get('permissions', [RoleController::class, 'permissions'])->middleware('can:view_roles');
    Route::get('roles', [RoleController::class, 'index'])->middleware('can:view_roles');
    Route::middleware('can:manage_roles')->group(function (): void {
        Route::post('roles', [RoleController::class, 'store']);
        Route::patch('roles/{role}', [RoleController::class, 'update'])->whereNumber('role');
        Route::put('roles/{role}/permissions', [RoleController::class, 'setPermissions'])->whereNumber('role');
        Route::post('roles/{role}/reset', [RoleController::class, 'reset'])->whereNumber('role');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role');
    });

    Route::middleware('can:manage_assignments')->group(function (): void {
        Route::get('class-teacher-assignments', [AssignmentController::class, 'classTeachers']);
        Route::put('classes/{schoolClass}/class-teachers', [AssignmentController::class, 'setClassTeachers']);
        Route::get('examiner-assignments', [AssignmentController::class, 'examiners']);
        Route::put('classes/{schoolClass}/examiners', [AssignmentController::class, 'setExaminers']);
        Route::post('assignments/copy-year', [AssignmentController::class, 'copyYear']);
    });

    // Pupils, school-wide.
    Route::get('students', [StudentController::class, 'index'])->middleware('can:view_students');
    Route::get('students/{student}', [StudentController::class, 'show'])->middleware('can:view_students');
    Route::get('students/{student}/results', [StudentController::class, 'results'])->middleware('can:view_students');
    Route::post('students', [StudentController::class, 'store'])->middleware('can:create_students');
    Route::post('students/import', [StudentImportController::class, 'import'])->middleware('can:create_students');
    Route::patch('students/{student}', [StudentController::class, 'update'])->middleware('can:update_students');
    Route::post('students/{student}/move', [StudentController::class, 'move'])->middleware('can:move_students');
    Route::post('students/{student}/status', [StudentController::class, 'status'])->middleware('can:change_student_status');
    Route::delete('students/{student}', [StudentController::class, 'destroy'])->middleware('can:delete_students');
    Route::get('promotions/preview', [StudentImportController::class, 'previewPromotion'])->middleware('can:promote_students');
    Route::post('promotions', [StudentImportController::class, 'promote'])->middleware('can:promote_students');

    // The pupils of the classes the signed-in teacher teaches (staff portal).
    // ClassTeachingAccess limits them to the teacher's own classes.
    Route::prefix('my')->group(function (): void {
        Route::get('classes/{schoolClass}/students', [ClassPupilsController::class, 'index'])->middleware('can:view_class_pupils');
        Route::post('classes/{schoolClass}/students', [ClassPupilsController::class, 'store'])->middleware('can:admit_class_pupils');
        Route::get('students/{student}', [ClassPupilsController::class, 'show'])->middleware('can:view_class_pupils');
        Route::get('students/{student}/results', [ClassPupilsController::class, 'results'])->middleware('can:view_class_pupils');
        Route::post('students/{student}/move', [ClassPupilsController::class, 'move'])->middleware('can:move_class_pupils');
    });
});

// Platform: across schools, so no 'tenant'. Only platform superadmins hold these.
Route::middleware(['auth:sanctum', 'password.changed'])->prefix('platform')->group(function (): void {
    Route::get('queue-health', QueueHealthController::class)->middleware('can:view_system_health');
    Route::get('schools', [PlatformSchoolController::class, 'index'])->middleware('can:manage_schools');
    Route::post('schools', [PlatformSchoolController::class, 'store'])->middleware('can:manage_schools');
    Route::patch('schools/{uuid}', [PlatformSchoolController::class, 'update'])->whereUuid('uuid')->middleware('can:manage_schools');

    // Subscriptions: prices, cycles, extensions, payments (SubscriptionController).
    Route::middleware('can:manage_subscriptions')->group(function (): void {
        Route::get('subscriptions', [PlatformSubscriptionController::class, 'index']);
        Route::get('schools/{uuid}/subscription', [PlatformSubscriptionController::class, 'show'])->whereUuid('uuid');
        Route::patch('schools/{uuid}/subscription', [PlatformSubscriptionController::class, 'update'])->whereUuid('uuid');
        Route::post('schools/{uuid}/subscription/payments', [PlatformSubscriptionController::class, 'recordPayment'])->whereUuid('uuid');
        Route::get('payments', [PlatformSubscriptionController::class, 'payments']);
        Route::post('payments/{payment}/assign', [PlatformSubscriptionController::class, 'assign'])->whereNumber('payment');
    });
});

// M-Pesa (Daraja) callbacks: no sign-in and no tenant; the URL carries
// MPESA_WEBHOOK_SECRET instead (MpesaWebhookController). Not throttled:
// Safaricom calls from a handful of IPs and retries what it cannot deliver.
//
// "pay", not "mpesa": Safaricom refuses to register a callback URL containing
// the words mpesa, safaricom, exe or sql ("Invalid ValidationURL - URL has the
// word MPESA").
Route::prefix('webhooks/pay/{secret}')->group(function (): void {
    Route::post('stk', [MpesaWebhookController::class, 'stk']);
    Route::post('c2b/validation', [MpesaWebhookController::class, 'validation']);
    Route::post('c2b/confirmation', [MpesaWebhookController::class, 'confirmation']);
});
