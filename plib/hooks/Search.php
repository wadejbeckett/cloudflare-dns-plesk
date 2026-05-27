<?php

declare(strict_types=1);

/**
 * Registers this extension with Plesk's top-bar search so admins can
 * jump to the settings page by typing "cloudflare" / "dns sync" / etc.
 *
 * Returns instances of a NAMED SearchIndex class (see
 * plib/library/SearchIndex.php) — an anonymous class fails on lookup
 * because Plesk's Lucene-backed search serialises the hit-class name
 * into the index and re-instantiates it later.
 */
class Modules_CloudflareDnsSync_Search extends pm_Hook_Search
{
    public function getIndex()
    {
        return [new Modules_CloudflareDnsSync_SearchIndex()];
    }
}
