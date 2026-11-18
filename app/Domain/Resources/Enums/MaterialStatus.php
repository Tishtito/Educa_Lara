<?php

declare(strict_types=1);

namespace App\Domain\Resources\Enums;

/** Admins can upload a batch as drafts and publish when ready; teachers see only published. */
enum MaterialStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
