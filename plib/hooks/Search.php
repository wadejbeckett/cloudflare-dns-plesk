<?php

declare(strict_types=1);

/**
 * Registers this extension with Plesk's top-bar search so admins can
 * jump to the settings page by typing "cloudflare" / "dns sync" / etc.
 */
class Modules_CloudflareDnsSync_Search extends pm_Hook_Search
{
    public function getIndex()
    {
        return [
            new class extends pm_SearchIndex {
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
            },
        ];
    }
}
