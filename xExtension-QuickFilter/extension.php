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
        if (class_exists('FreshRSS_Context', false) && FreshRSS_Context::hasUserConf()) {
            $showTags = FreshRSS_Context::userConf()->attributeString('show_tags') ?: 'n';
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
