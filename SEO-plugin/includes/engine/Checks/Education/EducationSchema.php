<?php
namespace HaloAudit\Checks\Education;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Education;
use HaloAudit\Result;

final class EducationSchema extends BaseCheck
{
    public const ID = 'education_schema';
    public const GROUP = 'education';
    public const SEVERITY = 2;
    public const TITLE = 'Education schema';
    public const WHY = 'Education schema tells search engines and AI assistants that you are an institution and what you teach.';
    public const FIX_WARN = 'Add CollegeOrUniversity or School schema for the institution, and Course schema on course pages.';

    private const PAGE_TYPES = ['Course', 'EducationalOccupationalProgram', 'EducationEvent'];

    public function run(Context $c): Result
    {
        $found = array_intersect($c->page->jsonLd()['types'], array_merge(Education::INSTITUTION_TYPES, self::PAGE_TYPES));
        return $found !== [] ? Result::pass('Found: ' . implode(', ', $found)) : Result::warn('No education schema types found');
    }
}
