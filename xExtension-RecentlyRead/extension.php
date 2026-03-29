<?php

class RecentlyReadExtension extends Minz_Extension {

    public function init() {
        Minz_View::appendScript($this->getFileUrl('script.js'));
        Minz_View::appendStyle($this->getFileUrl('style.css'));
    }

    public function handleConfigureAction() {
        $this->registerTranslates();
    }
}
