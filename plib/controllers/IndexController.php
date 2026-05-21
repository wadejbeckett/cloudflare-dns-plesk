<?php

use Noiz\CloudflareDns\Cloudflare\Client;

/**
 * Admin settings page for the Cloudflare DNS Sync extension.
 *
 * Lets an administrator enter the Cloudflare API token and account ID, and
 * choose which domains are synced to Cloudflare — per-domain activation, with
 * an optional auto-enable for newly added domains.
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

        if (!$this->listDomainNames()) {
            $this->_status->addMessage('info', 'No domains on this server yet. Add a domain in Plesk, then return here to activate it for syncing.');
        }

        $this->view->form = $form;
    }

    /**
     * Triggers Plesk to re-push every DNS zone, which the registered custom
     * backend then reconciles into Cloudflare.
     */
    public function resyncAction()
    {
        if (!$this->getRequest()->isPost()) {
            throw new pm_Exception('Permission denied');
        }

        try {
            pm_ApiCli::call('dns', ['--sync-all-zones']);
            $this->_status->addMessage('info', 'Resync started — every DNS zone is being pushed to Cloudflare.');
        } catch (pm_Exception $e) {
            $this->_status->addMessage('error', 'Resync failed: ' . $e->getMessage());
        }

        $this->_helper->json(['redirect' => pm_Context::getBaseUrl()]);
    }

    private function buildSettingsForm()
    {
        $form = new pm_Form_Simple();

        $hasToken = ((string) pm_Settings::get('api_token')) !== '';

        $form->addElement('password', 'api_token', [
            'label' => 'Cloudflare API token',
            'description' => $hasToken
                ? 'A token is saved. To replace it, tick "Change the API token" below, then enter the new one.'
                : 'A scoped token with Zone DNS Edit and Zone Read permissions.',
            'autocomplete' => 'new-password',
        ]);

        if ($hasToken) {
            // Lock the field once a token is stored. A disabled input is not
            // autofilled by the browser and is not submitted, so a stray
            // autofill can never silently overwrite a working token. The
            // checkbox re-enables it — see views/scripts/index/index.phtml.
            $tokenField = $form->getElement('api_token');
            $tokenField->setAttrib('disabled', 'disabled');
            $tokenField->setAttrib('placeholder', 'token saved — locked');

            $form->addElement('checkbox', 'change_token', [
                'label' => 'Change the API token',
                'description' => 'Tick to unlock the field above and enter a new token.',
            ]);
        }
        $form->addElement('text', 'account_id', [
            'label' => 'Cloudflare account ID',
            'value' => pm_Settings::get('account_id'),
            'description' => 'Required so the extension can create Cloudflare zones for the domains you activate.',
        ]);

        $domains = $this->listDomainNames();
        if ($domains) {
            $enabled = array_values(array_intersect($this->getEnabledDomains(), $domains));
            $form->addElement('multiCheckbox', 'enabled_domains', [
                'label' => 'Domains to sync',
                'multiOptions' => array_combine($domains, $domains),
                'value' => $enabled,
                'description' => 'Only the ticked domains are pushed to Cloudflare. Unticking a domain stops syncing it; its Cloudflare zone is left intact.',
            ]);
        }

        $form->addElement('checkbox', 'auto_enable_new_domains', [
            'label' => 'Auto-enable new domains',
            'checked' => ((string) pm_Settings::get('auto_enable_new_domains', '')) !== '',
            'description' => 'When on, a domain added in Plesk starts syncing automatically. When off (default), activate each domain in the list above.',
        ]);

        $form->addControlButtons([
            'sendTitle' => 'Save',
            'cancelLink' => pm_Context::getModulesListUrl(),
        ]);

        return $form;
    }

    /** All domain names on this server, sorted alphabetically. */
    private function listDomainNames()
    {
        $names = [];
        foreach (pm_Domain::getAllDomains() as $domain) {
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

        // Verify only when the token actually changes — saving the domain list
        // must not depend on, or be blocked by, a Cloudflare round-trip.
        if ($token !== $storedToken && $token !== '' && !(new Client($token))->verifyToken()) {
            throw new pm_Exception('That Cloudflare API token could not be verified — settings were not saved.');
        }

        pm_Settings::set('api_token', $token);
        pm_Settings::set('account_id', trim((string) $form->getValue('account_id')));
        pm_Settings::set('auto_enable_new_domains', $form->getValue('auto_enable_new_domains') ? '1' : '');

        if ($form->getElement('enabled_domains') !== null) {
            $selected = array_values(array_unique(array_map('strval', (array) $form->getValue('enabled_domains'))));
            pm_Settings::set('enabled_domains', json_encode($selected));
        }
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
}
