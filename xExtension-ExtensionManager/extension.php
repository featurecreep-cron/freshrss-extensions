<?php

class ExtensionManagerExtension extends Minz_Extension {

    public function init() {
        $this->registerController('extmgr');
        $this->registerViews();

        Minz_View::appendScript($this->getFileUrl('script.js', 'js'), '', '', '');
        Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
        $this->registerHook('js_vars', [$this, 'addVariables']);
    }

    public function addVariables($vars) {
        $vars[$this->getName()]['configuration'] = [
            'installed' => self::getInstalledExtensions(),
            'repos' => $this->getUserConfigurationValue('repos') ?: [],
        ];
        return $vars;
    }

    public function handleConfigureAction() {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            $repos = Minz_Request::param('repos', '');
            $repoList = array_values(array_filter(array_map('trim', explode("\n", $repos))));
            $this->setUserConfiguration(['repos' => $repoList]);
        }
    }

    public static function getInstalledExtensions() {
        $extPath = dirname(dirname(__FILE__));
        $installed = [];
        $dirs = glob($extPath . '/xExtension-*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            $metaFile = $dir . '/metadata.json';
            if (file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true);
                if ($meta) {
                    $installed[basename($dir)] = [
                        'name' => $meta['name'] ?? basename($dir),
                        'version' => $meta['version'] ?? '0',
                    ];
                }
            }
        }
        return $installed;
    }

    /**
     * Fetch the extension catalog from a GitHub repo.
     * Returns array of extensions found in the repo with metadata.
     */
    public static function fetchRepoCatalog($url) {
        if (!preg_match('#^https://github\.com/[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+#', $url)) {
            return ['error' => 'Invalid URL: only GitHub repositories supported'];
        }

        $url = rtrim($url, '/');
        $url = preg_replace('#\.git$#', '', $url);

        $zipData = self::downloadZip($url . '/archive/refs/heads/main.zip');
        if ($zipData === false) {
            $zipData = self::downloadZip($url . '/archive/refs/heads/master.zip');
        }
        if ($zipData === false) {
            return ['error' => 'Failed to download from ' . $url];
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'frss_ext_');
        file_put_contents($tmpFile, $zipData);

        $zip = new ZipArchive();
        if ($zip->open($tmpFile) !== true) {
            unlink($tmpFile);
            return ['error' => 'Failed to open zip'];
        }

        $tmpDir = sys_get_temp_dir() . '/frss_ext_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $zip->extractTo($tmpDir);
        $zip->close();
        unlink($tmpFile);

        $extensionDirs = [];
        self::findExtensionDirs($tmpDir, $extensionDirs, 0);

        // Fallback: single-extension repo with metadata.json at root
        if (empty($extensionDirs)) {
            $topDirs = glob($tmpDir . '/*', GLOB_ONLYDIR);
            if (!empty($topDirs)) {
                $repoDir = $topDirs[0];
                if (file_exists($repoDir . '/metadata.json')) {
                    $meta = json_decode(file_get_contents($repoDir . '/metadata.json'), true);
                    if ($meta && !empty($meta['entrypoint'])) {
                        $extensionDirs[] = ['source' => $repoDir, 'name' => 'xExtension-' . $meta['entrypoint']];
                    }
                }
            }
        }

        $catalog = [];
        foreach ($extensionDirs as $ext) {
            $metaFile = $ext['source'] . '/metadata.json';
            if (file_exists($metaFile)) {
                $meta = json_decode(file_get_contents($metaFile), true);
                $catalog[] = [
                    'dir' => $ext['name'],
                    'name' => $meta['name'] ?? $ext['name'],
                    'version' => $meta['version'] ?? '0',
                    'description' => $meta['description'] ?? '',
                    'author' => $meta['author'] ?? '',
                    'url' => $url,
                ];
            }
        }

        // Store the extracted tmpDir path for subsequent install calls
        // Clean up is handled after install or on timeout
        if (empty($catalog)) {
            self::recursiveDelete($tmpDir);
            return ['error' => 'No extensions found in repository'];
        }

        return ['extensions' => $catalog, 'tmpDir' => $tmpDir];
    }

    /**
     * Install a single extension by directory name from an already-extracted repo.
     */
    public static function installFromExtracted($tmpDir, $extDirName) {
        if (!is_dir($tmpDir)) {
            return 'Extracted repo not found. Try refreshing the catalog.';
        }

        // Prevent self-install
        if ($extDirName === 'xExtension-ExtensionManager') {
            return 'Extension Manager cannot update itself this way. Replace the files manually.';
        }

        // Find the extension source in the extracted dir
        $extensionDirs = [];
        self::findExtensionDirs($tmpDir, $extensionDirs, 0);

        $sourceDir = null;
        foreach ($extensionDirs as $ext) {
            if ($ext['name'] === $extDirName) {
                $sourceDir = $ext['source'];
                break;
            }
        }

        if (!$sourceDir || !is_dir($sourceDir)) {
            return 'Extension ' . $extDirName . ' not found in extracted archive';
        }

        // Verify required files
        if (!file_exists($sourceDir . '/metadata.json') || !file_exists($sourceDir . '/extension.php')) {
            return 'Extension is missing required files (metadata.json or extension.php)';
        }

        $extPath = dirname(dirname(__FILE__));
        $targetDir = $extPath . '/' . $extDirName;

        // Save enabled state
        $wasEnabled = self::isExtensionEnabled($extDirName);

        // Atomic update: staging in temp dir (NOT in extensions/)
        // Use copy+delete instead of rename() — rename fails across filesystem
        // boundaries, which is common in Docker (tmpfs vs bind mount).
        $stagingDir = sys_get_temp_dir() . '/frss_staging_' . uniqid();
        $backupDir = sys_get_temp_dir() . '/frss_backup_' . uniqid();

        self::recursiveCopy($sourceDir, $stagingDir);

        // Swap: backup old → install new → clean up
        if (is_dir($targetDir)) {
            self::recursiveCopy($targetDir, $backupDir);
            self::recursiveDelete($targetDir);
            if (is_dir($targetDir)) {
                self::recursiveDelete($stagingDir);
                self::recursiveDelete($backupDir);
                return 'Failed to remove old extension. Check permissions.';
            }
        }

        self::recursiveCopy($stagingDir, $targetDir);
        if (!is_dir($targetDir) || !file_exists($targetDir . '/metadata.json')) {
            // Rollback
            self::recursiveDelete($targetDir);
            if (is_dir($backupDir)) {
                self::recursiveCopy($backupDir, $targetDir);
                self::recursiveDelete($backupDir);
            }
            self::recursiveDelete($stagingDir);
            return 'Failed to install new extension. Old version restored.';
        }

        // Clean up temp dirs
        self::recursiveDelete($stagingDir);
        if (is_dir($backupDir)) self::recursiveDelete($backupDir);

        // Restore enabled state
        if ($wasEnabled) {
            self::setExtensionEnabled($extDirName, true);
        }

        return true;
    }

    /**
     * Legacy: install directly from a GitHub URL (single-extension repos).
     */
    public static function downloadAndInstall($url) {
        $result = self::fetchRepoCatalog($url);
        if (isset($result['error'])) {
            return $result['error'];
        }

        $extensions = $result['extensions'];
        $tmpDir = $result['tmpDir'];

        // For single-extension repos, install directly
        if (count($extensions) === 1) {
            $ext = $extensions[0];
            $installResult = self::installFromExtracted($tmpDir, $ext['dir']);
            self::recursiveDelete($tmpDir);
            return $installResult;
        }

        // Multi-extension repos should use the catalog flow
        self::recursiveDelete($tmpDir);
        return 'Repository contains multiple extensions. Add it as a repository source in Extension Manager settings, then install individual extensions from the extensions page.';
    }

    private static function downloadZip($zipUrl) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'FreshRSS-ExtensionManager/1.0',
                'follow_location' => true,
                'max_redirects' => 5,
            ],
        ]);
        return @file_get_contents($zipUrl, false, $context);
    }

    private static function findExtensionDirs($dir, &$results, $depth) {
        if ($depth > 3) return;
        $entries = @scandir($dir);
        if (!$entries) return;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                if (strpos($entry, 'xExtension-') === 0 && file_exists($path . '/metadata.json')) {
                    $results[] = ['source' => $path, 'name' => $entry];
                } else {
                    self::findExtensionDirs($path, $results, $depth + 1);
                }
            }
        }
    }

    public static function removeExtension($dir) {
        $extPath = dirname(dirname(__FILE__));
        $safeName = basename($dir);
        $targetDir = $extPath . '/' . $safeName;

        if (!is_dir($targetDir)) return 'Extension not found: ' . $safeName;
        if ($safeName === 'xExtension-ExtensionManager') return 'Cannot remove Extension Manager';
        if (!file_exists($targetDir . '/metadata.json')) return 'Not a valid extension';

        self::recursiveDelete($targetDir);
        return is_dir($targetDir) ? 'Failed to delete. Check permissions.' : true;
    }

    private static function isExtensionEnabled($dirName) {
        try {
            $conf = FreshRSS_Context::userConf();
            if ($conf) {
                $enabled = $conf->extensions_enabled;
                if (is_array($enabled)) {
                    $extPath = dirname(dirname(__FILE__));
                    $metaFile = $extPath . '/' . $dirName . '/metadata.json';
                    if (file_exists($metaFile)) {
                        $meta = json_decode(file_get_contents($metaFile), true);
                        $name = $meta['name'] ?? '';
                        if ($name && isset($enabled[$name])) {
                            return (bool) $enabled[$name];
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore
        }
        return false;
    }

    private static function setExtensionEnabled($dirName, $state) {
        try {
            $extPath = dirname(dirname(__FILE__));
            $metaFile = $extPath . '/' . $dirName . '/metadata.json';
            if (!file_exists($metaFile)) return;

            $meta = json_decode(file_get_contents($metaFile), true);
            $name = $meta['name'] ?? '';
            if (!$name) return;

            $conf = FreshRSS_Context::userConf();
            if (!$conf) return;

            $enabled = $conf->extensions_enabled;
            if (!is_array($enabled)) $enabled = [];
            $enabled[$name] = $state;
            $conf->extensions_enabled = $enabled;
            $conf->save();
        } catch (Exception $e) {
            // Ignore — worst case the user re-enables manually
        }
    }

    private static function recursiveDelete($dir) {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::recursiveDelete($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private static function recursiveCopy($src, $dst) {
        @mkdir($dst, 0755, true);
        foreach (scandir($src) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $s = $src . '/' . $entry;
            $d = $dst . '/' . $entry;
            is_dir($s) ? self::recursiveCopy($s, $d) : copy($s, $d);
        }
    }
}
