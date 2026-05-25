<?php

class CompactReaderExtension extends Minz_Extension {

    public function init() {
        Minz_View::appendStyle($this->getFileUrl('style.css'));
    }

    public function handleConfigureAction() {
        $this->registerTranslates();
    }
}
