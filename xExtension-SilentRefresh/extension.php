<?php

class SilentRefreshExtension extends Minz_Extension {
    const DEFAULT_REFRESH_INTERVAL = 2;
    const DEFAULT_TITLE_MODE = 'all';

    public function init() {
        Minz_View::appendScript($this->getFileUrl('script.js'));
        $this->registerHook('js_vars', [$this, 'addVariables']);
    }

    public function addVariables($vars) {
        $vars[$this->getName()]['configuration'] = [
            'refresh_interval' => (int) $this->getUserConfigurationValue('refresh_interval', self::DEFAULT_REFRESH_INTERVAL),
            'title_mode' => $this->getUserConfigurationValue('title_mode', self::DEFAULT_TITLE_MODE),
        ];
        return $vars;
    }

    public function handleConfigureAction() {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            $interval = max(1, min(60, (int) Minz_Request::param('refresh_interval', self::DEFAULT_REFRESH_INTERVAL)));
            $titleMode = Minz_Request::param('title_mode', self::DEFAULT_TITLE_MODE);
            if (!in_array($titleMode, ['all', 'current'], true)) {
                $titleMode = self::DEFAULT_TITLE_MODE;
            }
            $this->setUserConfiguration([
                'refresh_interval' => $interval,
                'title_mode' => $titleMode,
            ]);
        }
    }
}
