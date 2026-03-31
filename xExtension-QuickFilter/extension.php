<?php

class QuickFilterExtension extends Minz_Extension {

    public function init() {
        $this->registerController('quickfilter');
        $this->registerViews();

        // Load the service class
        require_once __DIR__ . '/lib/QuickFilterService.php';

        Minz_View::appendStyle($this->getFileUrl('style.css'));
        Minz_View::appendScript($this->getFileUrl('script.js'));
        $this->registerHook('js_vars', [$this, 'addJsVars']);
    }

    public function addJsVars(array $vars): array {
        // Only inject filter data on article views, not settings pages
        if (Minz_Request::controllerName() !== 'index') {
            return $vars;
        }

        $feedId = $this->getCurrentFeedId();
        $filters = [];
        if ($feedId > 0) {
            $filters = QuickFilterService::getFilters($feedId)['filters'];
        }

        $showTags = 'n';
        try {
            if (class_exists('FreshRSS_Context', false) && FreshRSS_Context::hasUserConf()) {
                $conf = FreshRSS_Context::userConf();
                // show_tags may be a direct property or an attribute depending on FreshRSS version
                if (method_exists($conf, 'attributeString')) {
                    $showTags = $conf->attributeString('show_tags') ?: 'n';
                }
            }
        } catch (Throwable $e) {
            // Fail safe — assume tags not shown
        }

        $vars[$this->getName()] = [
            'feedId' => $feedId,
            'filters' => $filters,
            'showTags' => $showTags,
            'firstRun' => !$this->getUserConfigurationValue('onboarded'),
        ];

        return $vars;
    }

    public function handleConfigureAction() {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            // Mark as onboarded when settings are saved
            $this->setUserConfiguration(['onboarded' => true]);
        }
    }

    /**
     * Detect the current feed ID from the request.
     */
    private function getCurrentFeedId(): int {
        $get = Minz_Request::paramString('get');

        // Direct feed view: get=f_123
        if (preg_match('/^f_(\d+)$/', $get, $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}
