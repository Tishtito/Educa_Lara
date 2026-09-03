<?php

declare(strict_types=1);

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * TenantScope's docblock states that raw SQL "lives in repository classes that
 * take school_id as a required constructor argument, enforced by an architecture
 * test". This is that test.
 *
 * The rule exists because global scopes are Eloquent-only: DB::table() and raw
 * joins bypass TenantScope entirely, and the marklist is inherently a large raw
 * join. Confining that code to repositories which cannot be constructed without
 * a school_id is what keeps the bypass from becoming a leak.
 */
class ArchitectureTest extends TestCase
{
    private const RAW_QUERY_PATTERNS = [
        'DB::table(',
        'DB::select(',
        'DB::statement(',
        'DB::update(',
        'DB::delete(',
        'DB::insert(',
    ];

    public function test_raw_queries_appear_only_in_repositories(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnder(base_path('app')) as $file) {
            $path = $file->getPathname();

            if (str_contains($path, DIRECTORY_SEPARATOR.'Repositories'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $contents = file_get_contents($path);

            foreach (self::RAW_QUERY_PATTERNS as $pattern) {
                if (str_contains($contents, $pattern)) {
                    $offenders[] = str_replace(base_path().'/', '', $path)." uses {$pattern}";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Raw queries bypass TenantScope. Move these into a repository that takes school_id:'],
            $offenders
        )));
    }

    public function test_every_repository_requires_a_school_id(): void
    {
        $repositories = [];

        foreach ($this->phpFilesUnder(base_path('app')) as $file) {
            if (! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Repositories'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $repositories[] = $file->getPathname();
        }

        $this->assertNotEmpty($repositories, 'No repositories found; this test would pass vacuously.');

        foreach ($repositories as $path) {
            $contents = file_get_contents($path);
            $relative = str_replace(base_path().'/', '', $path);

            $this->assertMatchesRegularExpression(
                '/__construct\s*\([^)]*\$schoolId/s',
                $contents,
                "{$relative} must take \$schoolId as a constructor argument."
            );
        }
    }

    /** @return list<SplFileInfo> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }
}
