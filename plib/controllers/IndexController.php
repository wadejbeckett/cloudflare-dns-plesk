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

        $form->addElement('password', 'api_token', [
            'label' => 'Cloudflare API token',
            'description' => 'A scoped token with Zone DNS Edit and Zone Read permissions. Leave blank to keep the current token.',
        ]);
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
        $token = trim((string) $form->getValue('api_token'));
        if ($token === '') {
            // Blank means "keep the current token".
            $token = (string) pm_Settings::get('api_token');
        }

        if ($token !== '' && !(new Client($token))->verifyToken()) {
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
