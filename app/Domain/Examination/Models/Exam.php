<?php

declare(strict_types=1);

namespace App\Domain\Examination\Models;

use App\Domain\Academics\Models\AcademicYear;
use App\Domain\Academics\Models\Level;
use App\Domain\Academics\Models\SchoolClass;
use App\Domain\Academics\Models\Term;
use App\Domain\Examination\Enums\ExamStatus;
use App\Domain\Examination\Enums\TiePolicy;
use App\Domain\Tenancy\Concerns\BelongsToSchool;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Exam extends Model
{
    use BelongsToSchool;
    use HasFactory;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ExamStatus::class,
            'tie_policy' => TiePolicy::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'exam_classes', 'exam_id', 'class_id')
            ->withTimestamps();
    }

    /** The subjects selected for this exam at creation time. */
    public function examSubjects(): HasMany
    {
        return $this->hasMany(ExamSubject::class);
    }

    /** Per-paper marks-out-of for this exam. */
    public function paperSettings(): HasMany
    {
        return $this->hasMany(ExamSubjectPaper::class);
    }

    public function studentResults(): HasMany
    {
        return $this->hasMany(ExamStudentResult::class);
    }

    public function subjectScores(): HasMany
    {
        return $this->hasMany(ExamSubjectScore::class);
    }

    public function statistics(): HasMany
    {
        return $this->hasMany(ExamStatistic::class);
    }

    /** Marks may only be entered or edited while the exam is being marked. */
    public function acceptsScoreEntry(): bool
    {
        return $this->status === ExamStatus::MARKING;
    }

    /** True for exams carried in from the legacy system by the ETL. */
    public function isLegacyImport(): bool
    {
        return $this->tie_policy === TiePolicy::LEGACY_ORDINAL;
    }
}
