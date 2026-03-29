<?php

class ExtensionManagerExtension extends Minz_Extension {

    public function init() {
        // Register custom controller for install/remove endpoints
        $this->registerController('extmgr');
        $this->registerViews();

        Minz_View::appendScript($this->getFileUrl('script.js', 'js'), '', '', '');
        Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
        $this->registerHook('js_vars', [$this, 'addVariables']);
    }

    public function addVariables($vars) {
        $vars[$this->getName()]['configuration'] = [
            'installed' => self::getInstalledExtensions(),
        ];
        return $vars;
    }

    public function handleConfigureAction() {
        $this->registerTranslates();
        // No POST handling needed — custom controller handles actions
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

    public static function downloadAndInstall($url) {
        if (!preg_match('#^https://github\.com/[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+#', $url)) {
            return 'Invalid URL: only GitHub repositories supported';
        }

        $url = rtrim($url, '/');
        $url = preg_replace('#\.git$#', '', $url);

        // Download zip
        $zipData = self::downloadZip($url . '/archive/refs/heads/main.zip');
        if ($zipData === false) {
            $zipData = self::downloadZip($url . '/archive/refs/heads/master.zip');
        }
        if ($zipData === false) {
            return 'Failed to download from ' . $url;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'frss_ext_');
        file_put_contents($tmpFile, $zipData);

        $zip = new ZipArchive();
        if ($zip->open($tmpFile) !== true) {
            unlink($tmpFile);
            return 'Failed to open zip';
        }

        $tmpDir = sys_get_temp_dir() . '/frss_ext_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $zip->extractTo($tmpDir);
        $zip->close();
        unlink($tmpFile);

        // Find extensions
        $extensionDirs = [];
        self::findExtensionDirs($tmpDir, $extensionDirs, 0);

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

        if (empty($extensionDirs)) {
            self::recursiveDelete($tmpDir);
            return 'No extension found in archive';
        }

        $extPath = dirname(dirname(__FILE__));
        $names = [];

        foreach ($extensionDirs as $ext) {
            $targetDir = $extPath . '/' . $ext['name'];

            // Save enabled state before modifying
            $wasEnabled = self::isExtensionEnabled($ext['name']);

            // Atomic update: copy to staging dir, verify, then swap
            $stagingDir = $extPath . '/' . $ext['name'] . '.new';
            $backupDir = $extPath . '/' . $ext['name'] . '.bak';

            // Clean up any leftover staging/backup from previous failed attempt
            if (is_dir($stagingDir)) self::recursiveDelete($stagingDir);
            if (is_dir($backupDir)) self::recursiveDelete($backupDir);

            // Copy new version to staging
            self::recursiveCopy($ext['source'], $stagingDir);

            // Verify staging has required files
            if (!file_exists($stagingDir . '/metadata.json') || !file_exists($stagingDir . '/extension.php')) {
                self::recursiveDelete($stagingDir);
                self::recursiveDelete($tmpDir);
                return 'Downloaded extension is missing required files (metadata.json or extension.php)';
            }

            // Swap: old → backup, staging → target
            if (is_dir($targetDir)) {
                if (!@rename($targetDir, $backupDir)) {
                    self::recursiveDelete($stagingDir);
                    self::recursiveDelete($tmpDir);
                    return 'Failed to move old extension to backup. Check permissions.';
                }
            }

            if (!@rename($stagingDir, $targetDir)) {
                // Rollback: restore backup
                if (is_dir($backupDir)) @rename($backupDir, $targetDir);
                self::recursiveDelete($tmpDir);
                return 'Failed to install new extension. Old version restored.';
            }

            // Success — clean up backup
            if (is_dir($backupDir)) self::recursiveDelete($backupDir);

            // Restore enabled state
            if ($wasEnabled) {
                self::setExtensionEnabled($ext['name'], true);
            }

            $names[] = $ext['name'];
        }

        self::recursiveDelete($tmpDir);
        return true;
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
        // Check both user and system config for enabled state
        try {
            $conf = FreshRSS_Context::userConf();
            if ($conf) {
                $enabled = $conf->extensions_enabled;
                if (is_array($enabled)) {
                    // Extensions are keyed by name (from metadata.json), not directory name
                    // Read the extension name from metadata before we delete
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
            // Read the freshly installed extension's name
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
