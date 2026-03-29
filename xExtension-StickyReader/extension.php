<?php

class StickyReaderExtension extends Minz_Extension {
    const DEFAULTS = [
        'scroll_anchor' => true,
        'scroll_target' => 'control_bar',
        'title_feed_name' => true,
        'hide_feed_column' => true,
        'lock_sidebar' => false,
        'hide_sub_management' => false,
    ];

    const SCROLL_TARGETS = ['search_bar', 'control_bar', 'title_row'];

    public function init() {
        Minz_View::appendStyle($this->getFileUrl('style.css'));
        Minz_View::appendScript($this->getFileUrl('script.js'));
        $this->registerHook('js_vars', [$this, 'addVariables']);
    }

    public function addVariables($vars) {
        $vars[$this->getName()]['configuration'] = $this->getFullConfig();
        return $vars;
    }

    public function handleConfigureAction() {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            $config = [];
            foreach (array_keys(self::DEFAULTS) as $key) {
                if ($key === 'scroll_target') {
                    $val = Minz_Request::param($key, 'control_bar');
                    $config[$key] = in_array($val, self::SCROLL_TARGETS, true) ? $val : 'control_bar';
                } else {
                    $config[$key] = Minz_Request::param($key) === '1';
                }
            }
            $this->setUserConfiguration($config);
        }
    }

    public function getFullConfig() {
        $config = self::DEFAULTS;
        foreach ($config as $key => $default) {
            $stored = $this->getUserConfigurationValue($key);
            if ($stored !== null) {
                if ($key === 'scroll_target') {
                    $config[$key] = in_array($stored, self::SCROLL_TARGETS, true) ? $stored : $default;
                } else {
                    $config[$key] = (bool) $stored;
                }
            }
        }
        return $config;
    }
}
