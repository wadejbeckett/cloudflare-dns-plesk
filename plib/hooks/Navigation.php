<?php

declare(strict_types=1);

/**
 * Adds this extension to Plesk's sidebar under "Links to Additional Services".
 *
 * Class naming follows Plesk's convention (Modules_<CamelCaseId>_<Name>)
 * because Plesk auto-discovers hooks by scanning plib/hooks/*.php for
 * subclasses of pm_Hook_<Name> — the auto-loader expects this prefix.
 */
class Modules_CloudflareDnsSync_Navigation extends pm_Hook_Navigation
{
    public function getNavigation()
    {
        return [
            [
                'controller' => 'index',
                'action' => 'index',
                'title' => 'Cloudflare DNS Sync',
                'description' => 'One-way Plesk → Cloudflare DNS synchronisation, non-destructive.',
            ],
        ];
    }
}
