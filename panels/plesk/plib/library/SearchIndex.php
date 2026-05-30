<?php

declare(strict_types=1);

/**
 * Named SearchIndex entry consumed by plib/hooks/Search.php.
 *
 * Plesk's search engine (Zend Lucene) serialises hit-class names into
 * the search index and instantiates them again on lookup. An anonymous
 * class works at register-time but fails on lookup with "Class
 * pm_SearchIndex@anonymous not found", so this MUST be a named class
 * that Plesk's autoloader can resolve via the `Modules_<CamelCaseId>_…`
 * convention. Filename matches the class's path under plib/library/.
 */
class Modules_CloudflareDnsSync_SearchIndex extends pm_SearchIndex
{
    public function getIndexedFields()
    {
        return [
            'title' => 'Cloudflare DNS Sync',
            'text' => 'cloudflare dns sync zone records proxy orange-cloud',
        ];
    }

    public function getTitle()
    {
        return 'Cloudflare DNS Sync';
    }

    public function getDescription()
    {
        return 'Per-domain Plesk → Cloudflare DNS sync, proxy-preserving.';
    }

    public function getLink()
    {
        return pm_Context::getBaseUrl();
    }

    public function isVisible()
    {
        return pm_Session::getClient()->isAdmin();
    }
}
