<?php
namespace HaloAudit\Checks\Ai;

use HaloAudit\BaseCheck;
use HaloAudit\Context;
use HaloAudit\Result;

final class SchemaTypes extends BaseCheck
{
    public const ID = 'schema_types';
    public const GROUP = 'ai';
    public const SEVERITY = 2;
    public const TITLE = 'Schema types';
    public const WHY = 'AI assistants trust a page more when it states who publishes it and what kind of page it is.';
    public const FIX_WARN = 'Add the missing half: one schema type for your organisation or site, and one for this page (for example Article, Course or Product).';
    public const FIX_FAIL = 'Add schema for your organisation (Organization) and for this page (for example Article, Course or Product).';

    private const SITE_TYPES = [
        'Organization', 'WebSite', 'Corporation', 'LocalBusiness', 'NGO',
        'EducationalOrganization', 'CollegeOrUniversity', 'School', 'HighSchool',
    ];

    /** Building blocks of other schema. They never say what kind of page this is. */
    private const HELPER_TYPES = [
        'BreadcrumbList', 'ListItem', 'ItemList', 'ImageObject', 'SearchAction', 'EntryPoint', 'ReadAction',
        'PropertyValueSpecification', 'SiteNavigationElement', 'WPHeader', 'WPFooter', 'WPSideBar', 'ContactPoint', 'PostalAddress',
    ];

    public function run(Context $c): Result
    {
        $ld = $c->page->jsonLd();
        $types = $ld['types'];
        // The publisher may be nested (Article.publisher), so site types count at any depth.
        // The page type must be a thing the page itself describes, not a nested helper.
        $site = array_intersect($types, self::SITE_TYPES);
        $page = array_diff($ld['top_types'], self::SITE_TYPES, self::HELPER_TYPES);
        $evidence = $types === [] ? 'No schema types found' : 'Found: ' . implode(', ', array_slice($types, 0, 8));

        if ($site !== [] && $page !== []) {
            return Result::pass($evidence);
        }
        return ($site !== [] || $page !== []) ? Result::warn($evidence) : Result::fail($evidence);
    }
}
