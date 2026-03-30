<?php

class YouTubeEmbedExtension extends Minz_Extension {

    private string $origin = '';

    public function init() {
        $this->registerHook('entry_before_display', [$this, 'fixYouTubeEmbeds']);
    }

    public function handleConfigureAction() {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            $origin = trim(Minz_Request::param('origin', ''));
            $this->setUserConfiguration(['origin' => $origin]);
        }
    }

    public function fixYouTubeEmbeds(FreshRSS_Entry $entry): FreshRSS_Entry {
        $content = $entry->content();

        // Skip if no YouTube embeds in content
        if (stripos($content, 'youtube.com/embed/') === false &&
            stripos($content, 'youtube-nocookie.com/embed/') === false) {
            return $entry;
        }

        $origin = $this->getOrigin();
        if ($origin === '') {
            return $entry;
        }

        // Rewrite YouTube embed URLs to add origin parameter and referrerpolicy
        $content = preg_replace_callback(
            '#(<iframe\b[^>]*\bsrc=["\'])(https?://(?:www\.)?youtube(?:-nocookie)?\.com/embed/[^"\']*?)(["\'][^>]*>)#i',
            function ($matches) use ($origin) {
                $before = $matches[1];
                $url = $matches[2];
                $after = $matches[3];

                // Add origin parameter if not already present
                if (strpos($url, 'origin=') === false) {
                    $url .= (strpos($url, '?') !== false ? '&' : '?') . 'origin=' . urlencode($origin);
                }

                // Ensure referrerpolicy attribute is present on the iframe
                $iframeTag = $before . $url . $after;
                if (stripos($iframeTag, 'referrerpolicy') === false) {
                    $iframeTag = str_replace('<iframe ', '<iframe referrerpolicy="origin" ', $iframeTag);
                }

                // Ensure allow attribute includes autoplay and encrypted-media
                if (stripos($iframeTag, 'allow=') === false) {
                    $iframeTag = str_replace('<iframe ', '<iframe allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" ', $iframeTag);
                }

                return $iframeTag;
            },
            $content
        );

        if ($content !== null) {
            $entry->_content($content);
        }

        return $entry;
    }

    private function getOrigin(): string {
        if ($this->origin !== '') {
            return $this->origin;
        }

        $origin = $this->getUserConfigurationValue('origin');
        if ($origin) {
            $this->origin = $origin;
            return $this->origin;
        }

        // Auto-detect from request
        if (!empty($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $this->origin = $scheme . '://' . $_SERVER['HTTP_HOST'];
            return $this->origin;
        }

        return '';
    }
}
