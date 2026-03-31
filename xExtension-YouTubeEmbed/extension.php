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

        // Match iframes with YouTube URLs in src OR data-original (FreshRSS lazy-loading)
        $content = preg_replace_callback(
            '#(<iframe\b)([^>]*?)(\s*/?>)#i',
            function ($matches) use ($origin) {
                $tag = $matches[1] . $matches[2] . $matches[3];

                // Check if this iframe has a YouTube URL in src or data-original
                if (!preg_match('#(?:src|data-original)=["\']https?://(?:www\.)?youtube(?:-nocookie)?\.com/embed/#i', $tag)) {
                    return $tag;
                }

                // Add origin parameter to YouTube URLs in both src and data-original
                $tag = preg_replace_callback(
                    '#((?:src|data-original)=["\'])(https?://(?:www\.)?youtube(?:-nocookie)?\.com/embed/[^"\']*?)(["\'])#i',
                    function ($m) use ($origin) {
                        $url = $m[2];
                        if (strpos($url, 'origin=') === false) {
                            $url .= (strpos($url, '?') !== false ? '&' : '?') . 'origin=' . urlencode($origin);
                        }
                        return $m[1] . $url . $m[3];
                    },
                    $tag
                );

                // Add referrerpolicy if missing
                if (stripos($tag, 'referrerpolicy') === false) {
                    $tag = preg_replace('#<iframe\b#i', '<iframe referrerpolicy="origin"', $tag);
                }

                // Remove sandbox attribute — YouTube embeds need unrestricted
                // access to verify embedder identity via ancestorOrigins
                $tag = preg_replace('#\s*sandbox="[^"]*"#i', '', $tag);

                return $tag;
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
