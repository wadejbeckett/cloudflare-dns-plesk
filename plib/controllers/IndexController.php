<?php

declare(strict_types=1);

use Noiz\CloudflareDns\Cloudflare\Client;

/**
 * Admin settings page for the Cloudflare DNS Sync extension.
 *
 * Holds the Cloudflare connection settings (API token, account ID) and the
 * per-domain sync controls — each domain has an instant on/off toggle, and
 * activating one pushes it to Cloudflare straight away.
 */
class IndexController extends pm_Controller_Action
{
    public function init()
    {
        parent::init();

        if (!pm_Session::getClient()->isAdmin()) {
            throw new pm_Exception('Permission denied');
        }

        // Register the autoloader for the panel-agnostic Cloudflare core.
        require_once __DIR__ . '/../library/autoload.php';

        $this->view->pageTitle = 'Cloudflare DNS Sync';
    }

    public function indexAction()
    {
        $form = $this->buildSettingsForm();

        if ($this->getRequest()->isPost() && $form->isValid($this->getRequest()->getPost())) {
            try {
                $this->saveSettings($form);
                $this->_status->addMessage('info', 'Settings saved.');
            } catch (pm_Exception $e) {
                $this->_status->addMessage('error', $e->getMessage());
            }
            $this->_helper->json(['redirect' => pm_Context::getBaseUrl()]);
            return;
        }

        $this->showConnectionStatus();

        $this->view->form = $form;
        $this->view->domains = $this->buildDomainList();
        $this->view->autoEnable = $this->isAutoEnabled();
        $this->view->toggleDomainUrl = pm_Context::getActionUrl('index', 'toggle-domain');
        $this->view->toggleAutoenableUrl = pm_Context::getActionUrl('index', 'toggle-autoenable');
        $this->view->domainStatusUrl = pm_Context::getActionUrl('index', 'domain-status');
        $this->view->resyncDomainUrl = pm_Context::getActionUrl('index', 'resync-domain');
        $this->view->resyncAllUrl = pm_Context::getActionUrl('index', 'resync-all');
    }

    /**
     * AJAX: activate or deactivate a single domain. Activating it also pushes
     * the domain to Cloudflare; the sync runs in the background, so the
     * response reports "pending" and the page polls domainStatusAction.
     */
    public function toggleDomainAction()
    {
        if (!$this->getRequest()->isPost()) {
            throw new pm_Exception('Permission denied');
        }

        $domain = trim((string) $this->getRequest()->getParam('domain'));
        $enable = (string) $this->getRequest()->getParam('enabled') === '1';

        if (!in_array($domain, $this->listDomainNames(), true)) {
            $this->_helper->json(['success' => false, 'message' => 'Unknown domain.']);
            return;
        }

        $enabled = $this->getEnabledDomains();
        if ($enable && !in_array($domain, $enabled, true)) {
            $enabled[] = $domain;
        } elseif (!$enable) {
            $enabled = array_values(array_diff($enabled, [$domain]));
        }
        pm_Settings::set('enabled_domains', json_encode(array_values($enabled)));

        if (!$enable) {
            // Deactivating only stops future syncs — the Cloudflare zone is
            // left intact, so just clear the stored status. Also drop the
            // cached Cloudflare zone ID so a future re-activation re-resolves
            // it (the zone may have been moved/deleted in CF in the meantime).
            pm_Settings::set('status_' . $domain, '');
            pm_Settings::set('cf_zone_id_' . $domain, '');
            $this->_helper->json([
                'success' => true,
                'enabled' => false,
                'pending' => false,
                'status' => $this->describeStatus(false, null),
            ]);
            return;
        }

        // Mark the domain as seen so the auto-enable cron doesn't re-enrol
        // it after a future UI deactivation. Idempotent.
        $seenRaw = (string) pm_Settings::get('seen_domains', '');
        $seen = $seenRaw !== '' ? (json_decode($seenRaw, true) ?: []) : [];
        if (!in_array($domain, $seen, true)) {
            $seen[] = $domain;
            pm_Settings::set('seen_domains', json_encode(array_values(array_unique($seen))));
        }

        // Activating syncs the domain. Fire the background trigger and let
        // the front-end poll for status (see triggerSync).
        $this->triggerSync($domain);

        $status = $this->getDomainStatus($domain);
        $this->_helper->json([
            'success' => true,
            'enabled' => true,
            'pending' => $status === null,
            'status' => $this->describeStatus(true, $status),
        ]);
    }

    /**
     * AJAX: resync a single already-activated domain. Fires the same
     * single-zone trigger used on activation, then the front-end polls
     * domain-status for the outcome. The activation modal is NOT shown —
     * the domain is already on the activation list, so the DNSSEC /
     * registrar-NS prerequisites were acknowledged on the original
     * activation.
     */
    public function resyncDomainAction()
    {
        if (!$this->getRequest()->isPost()) {
            throw new pm_Exception('Permission denied');
        }

        $domain = trim((string) $this->getRequest()->getParam('domain'));
        if (!in_array($domain, $this->listDomainNames(), true)) {
            $this->_helper->json(['success' => false, 'message' => 'Unknown domain.']);
            return;
        }
        if (!in_array($domain, $this->getEnabledDomains(), true)) {
            $this->_helper->json(['success' => false, 'message' => 'Domain is not activated for sync.']);
            return;
        }

        $this->triggerSync($domain);
        $status = $this->getDomainStatus($domain);

        $this->_helper->json([
            'success' => true,
            'pending' => $status === null,
            'status' => $this->describeStatus(true, $status),
        ]);
    }

    /**
     * AJAX: resync every activated domain. Fires one background trigger
     * per domain; the front-end then polls each row independently via the
     * existing domain-status endpoint.
     */
    public function resyncAllAction()
    {
        if (!$this->getRequest()->isPost()) {
            throw new pm_Exception('Permission denied');
        }

        $enabled = $this->getEnabledDomains();
        foreach ($enabled as $domain) {
            $this->triggerSync($domain);
        }

        $this->_helper->json([
            'success' => true,
            'domains' => array_values($enabled),
        ]);
    }

    /** AJAX: report a domain's current sync status (polled while a sync runs). */
    public function domainStatusAction()
    {
        $domain = trim((string) $this->getRequest()->getParam('domain'));
        if (!in_array($domain, $this->listDomainNames(), true)) {
            $this->_helper->json(['success' => false, 'message' => 'Unknown domain.']);
            return;
        }
        $enabled = in_array($domain, $this->getEnabledDomains(), true);
        $status = $this->getDomainStatus($domain);

        $this->_helper->json([
            'success' => true,
            'pending' => $enabled && $status === null,
            'status' => $this->describeStatus($enabled, $status),
        ]);
    }

    /** AJAX: toggle the server-wide "auto-enable new domains" setting. */
    public function toggleAutoenableAction()
    {
        if (!$this->getRequest()->isPost()) {
            throw new pm_Exception('Permission denied');
        }

        $on = (string) $this->getRequest()->getParam('enabled') === '1';
        pm_Settings::set('auto_enable_new_domains', $on ? '1' : '');

        $this->_helper->json(['success' => true, 'enabled' => $on]);
    }

    private function buildSettingsForm()
    {
        $form = new pm_Form_Simple();

        $hasToken = ((string) pm_Settings::get('api_token')) !== '';

        $form->addElement('password', 'api_token', [
            'label' => 'Cloudflare API token',
            'description' => $hasToken
                ? 'A token is saved. To replace it, tick "Change the API token", then enter the new one.'
                : 'A scoped token with Zone DNS Edit and Zone Read permissions.',
            'autocomplete' => 'new-password',
        ]);

        if ($hasToken) {
            // Lock the field once a token is stored: a disabled input is not
            // autofilled by the browser and is not submitted, so a stray
            // autofill cannot overwrite a working token. The "Change the API
            // token" checkbox unlocks it — see views/scripts/index/index.phtml.
            $tokenField = $form->getElement('api_token');
            $tokenField->setAttrib('disabled', 'disabled');
            $tokenField->setAttrib('placeholder', 'token saved — locked');

            $form->addElement('checkbox', 'change_token', [
                'label' => 'Change the API token',
                'description' => 'Unlocks the field above so you can enter a new token.',
            ]);
        }

        $form->addElement('text', 'account_id', [
            'label' => 'Cloudflare account ID',
            'value' => pm_Settings::get('account_id'),
            'description' => 'Required so the extension can create Cloudflare zones for the domains you activate.',
        ]);

        $form->addControlButtons([
            'sendTitle' => 'Save',
            'cancelLink' => pm_Context::getModulesListUrl(),
        ]);

        return $form;
    }

    private function saveSettings($form)
    {
        $storedToken = (string) pm_Settings::get('api_token');

        // Only accept a new token when the admin explicitly asked to change it.
        // This stops a browser autofill in the token field from silently
        // overwriting a working token on an otherwise unrelated save.
        $changeToken = $form->getElement('change_token') !== null
            ? (bool) $form->getValue('change_token')
            : ($storedToken === '');

        $token = $storedToken;
        if ($changeToken) {
            $entered = trim((string) $form->getValue('api_token'));
            if ($entered !== '') {
                $token = $entered;
            }
        }

        // Verify against Cloudflare only when the token actually changes.
        if ($token !== $storedToken && $token !== '' && !(new Client($token))->verifyToken()) {
            throw new pm_Exception('That Cloudflare API token could not be verified — settings were not saved.');
        }

        pm_Settings::set('api_token', $token);
        pm_Settings::set('account_id', trim((string) $form->getValue('account_id')));
    }

    private function showConnectionStatus()
    {
        $token = (string) pm_Settings::get('api_token');
        if ($token === '') {
            $this->_status->addMessage('info', 'Enter a Cloudflare API token below to start syncing.');
            return;
        }

        try {
            $connected = (new Client($token))->verifyToken();
        } catch (Exception $e) {
            $connected = false;
        }

        if ($connected) {
            $this->_status->addMessage('info', 'Connected to Cloudflare.');
        } else {
            $this->_status->addMessage('error', 'The stored Cloudflare API token is no longer valid.');
        }
    }

    /** One display row per server domain: name, enabled flag, status. */
    private function buildDomainList()
    {
        $enabled = $this->getEnabledDomains();
        $rows = [];
        foreach ($this->listDomainNames() as $name) {
            $isEnabled = in_array($name, $enabled, true);
            $status = $this->getDomainStatus($name);
            $rows[] = [
                'name' => $name,
                'enabled' => $isEnabled,
                'pending' => $isEnabled && $status === null,
                'status' => $this->describeStatus($isEnabled, $status),
            ];
        }
        return $rows;
    }

    /** All main domains on this server, sorted alphabetically. */
    private function listDomainNames()
    {
        // `true` = main domains only. Regular subdomains and domain aliases
        // share the parent's DNS zone, so they have no zone of their own to
        // sync — listing them here would either fail (Cloudflare rejects an
        // overlapping zone in the same account) or just produce a row whose
        // toggle does nothing. Standalone-subdomain-zones are a deliberate
        // "Later" item on the roadmap.
        $names = [];
        foreach (pm_Domain::getAllDomains(true) as $domain) {
            $names[] = $domain->getName();
        }
        sort($names);
        return $names;
    }

    /** The administrator's per-domain activation list. */
    private function getEnabledDomains()
    {
        $list = json_decode((string) pm_Settings::get('enabled_domains', '[]'), true);
        return is_array($list) ? $list : [];
    }

    private function isAutoEnabled()
    {
        return ((string) pm_Settings::get('auto_enable_new_domains', '')) !== '';
    }

    /**
     * Trigger an immediate sync for $domain in the background and clear
     * its recorded status so the front-end's poll sees "pending" until
     * the sync completes. Invokes sync-poll.php with the domain as
     * argv[1] so only that one zone is reconciled.
     *
     * The trailing `&` detaches the script so this method returns
     * immediately. As a side-effect `exec()` cannot observe the
     * sub-process's eventual exit code — the detached shell returns 0 to
     * us synchronously regardless. If the script crashes before writing
     * a status, the row stays "pending" until the front-end's 2-minute
     * poll-status timeout surfaces it as "Still syncing — refresh the
     * page".
     */
    private function triggerSync($domain)
    {
        pm_Settings::set('status_' . $domain, '');
        $cmd = sprintf(
            '/opt/psa/bin/extension --exec cloudflare-dns-sync sync-poll.php %s '
                . '< /dev/null > /dev/null 2>&1 &',
            escapeshellarg($domain)
        );
        @exec($cmd);
    }

    /** The last recorded sync outcome for a domain, or null if never synced. */
    private function getDomainStatus($domain)
    {
        $raw = (string) pm_Settings::get('status_' . $domain);
        if ($raw === '') {
            return null;
        }
        $status = json_decode($raw, true);
        return is_array($status) ? $status : null;
    }

    /** Turns a raw status record into display text plus a CSS state class. */
    private function describeStatus($enabled, $status)
    {
        if (!$enabled) {
            return ['text' => 'Not syncing', 'class' => 'muted'];
        }
        if (!is_array($status)) {
            return ['text' => 'Enabled — not yet synced', 'class' => 'muted'];
        }
        if (empty($status['ok'])) {
            return ['text' => 'Sync failed: ' . ($status['error'] ?? 'unknown error'), 'class' => 'error'];
        }
        $n = (int) ($status['records'] ?? 0);
        $k = (int) ($status['skipped_count'] ?? 0);
        $c = (int) ($status['conflicts_count'] ?? 0);
        $text = 'Synced — ' . $n . ' record' . ($n === 1 ? '' : 's');
        if ($k > 0) {
            $text .= ', ' . $k . ' skipped';
        }
        if ($c > 0) {
            $text .= ', ' . $c . ' conflict' . ($c === 1 ? '' : 's');
        }
        return ['text' => $text, 'class' => 'ok'];
    }
}
