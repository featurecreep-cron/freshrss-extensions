<?php

class RightClickActionsExtension extends Minz_Extension {
    const DEFAULTS = [
        'zones' => [
            'header' => true,
            'body' => false,
            'sidebar_feed' => true,
            'sidebar_category' => true,
        ],
        'actions' => [
            'header' => [
                'toggle_read' => true,
                'star_toggle' => true,
                'open_new_tab' => true,
                'mark_older' => true,
                'mark_newer' => true,
                'filter_title' => true,
                'filter_star' => true,
                'filter_feed' => true,
            ],
            'body' => [
                'toggle_read' => false,
                'star_toggle' => false,
                'open_new_tab' => false,
                'mark_older' => false,
                'mark_newer' => false,
                'filter_title' => false,
                'filter_star' => false,
                'filter_feed' => false,
            ],
            'sidebar_feed' => [
                'mark_all_read' => true,
                'mark_all_unread' => true,
                'recently_read' => true,
                'open_settings' => true,
            ],
            'sidebar_category' => [
                'mark_all_read' => true,
                'mark_all_unread' => true,
                'recently_read' => true,
                'expand_all' => true,
                'collapse_all' => true,
                'add_subscription' => true,
                'manage_subscriptions' => true,
            ],
        ],
    ];

    public function init() {
        $this->registerController('rcafilter');
        Minz_View::appendStyle($this->getFileUrl('style.css'));
        Minz_View::appendScript($this->getFileUrl('script.js'));
        $this->registerHook('js_vars', [$this, 'addVariables']);
    }

    public function addVariables($vars) {
        $config = $this->getFullConfig();
        $vars[$this->getName()]['configuration'] = $config;
        $vars[$this->getName()]['csrf'] = FreshRSS_Auth::csrfToken();
        return $vars;
    }

    public function handleConfigureAction() {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            $config = self::DEFAULTS;

            // The form posts a hidden '0' before each checkbox's '1', and
            // paramBoolean() reads both — same result as the old `=== '1'`,
            // without Minz_Request::param(), which FreshRSS 1.29 deprecated and
            // which emits a notice on every save (the defect reported against
            // Sticky Reader in #14).
            foreach (array_keys($config['zones']) as $zone) {
                $config['zones'][$zone] = Minz_Request::paramBoolean('zone_' . $zone);
            }

            foreach ($config['actions'] as $zone => $actions) {
                foreach (array_keys($actions) as $action) {
                    // If zone is disabled, force all its actions to false
                    if (!$config['zones'][$zone]) {
                        $config['actions'][$zone][$action] = false;
                    } else {
                        $config['actions'][$zone][$action] = Minz_Request::paramBoolean('action_' . $zone . '_' . $action);
                    }
                }
            }

            $this->setUserConfiguration($config);
        }
    }

    public function getFullConfig() {
        $storedZones = $this->getUserConfigurationValue('zones');
        if ($storedZones === null) {
            return self::DEFAULTS;
        }

        $config = self::DEFAULTS;
        $storedActions = $this->getUserConfigurationValue('actions', []);

        foreach ($config['zones'] as $zone => $default) {
            if (isset($storedZones[$zone])) {
                $config['zones'][$zone] = (bool) $storedZones[$zone];
            }
        }

        foreach ($config['actions'] as $zone => $actions) {
            // If a stored action key doesn't exist in defaults (renamed/removed), skip it
            // If a default key doesn't exist in stored (new action), keep the default
            foreach ($actions as $action => $default) {
                if (isset($storedActions[$zone][$action])) {
                    $config['actions'][$zone][$action] = (bool) $storedActions[$zone][$action];
                }
            }
        }

        return $config;
    }
}
