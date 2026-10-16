<?php
namespace HaloAudit;

final class Education
{
    public const INSTITUTION_TYPES = [
        'CollegeOrUniversity', 'EducationalOrganization', 'School',
        'HighSchool', 'MiddleSchool', 'ElementarySchool', 'Preschool',
    ];

    /** @param string[] $types JSON-LD types found on the page */
    public static function detect(string $host, array $types): bool
    {
        if (preg_match('/(\.edu|\.edu\.[a-z]{2}|\.ac\.[a-z]{2})$/', strtolower($host))) {
            return true;
        }
        return array_intersect($types, self::INSTITUTION_TYPES) !== [];
    }
}
