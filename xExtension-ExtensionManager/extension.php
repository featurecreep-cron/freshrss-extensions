<?php

class ExtensionManagerExtension extends Minz_Extension {

    /**
     * Queue directory for deferred installs (within FreshRSS data dir).
     * Used when the extensions directory is not writable at runtime.
     */
    private static function queueDir(): string {
        return DATA_PATH . '/extmgr';
    }

    /**
     * Whether the extensions directory is writable by the current process.
     */
    public static function extensionsWritable(): bool {
        return is_writable(dirname(dirname(__FILE__)));
    }

    public function init() {
        $this->registerController('extmgr');
        $this->registerViews();

        Minz_View::appendScript($this->getFileUrl('script.js'));
        Minz_View::appendStyle($this->getFileUrl('style.css'));
        $this->registerHook('js_vars', [$this, 'addVariables']);
    }

    public function addVariables($vars) {
        $vars[$this->getName()]['configuration'] = [
            'installed' => self::getInstalledExtensions(),
            'repos' => $this->getUserConfigurationValue('repos') ?: [],
            'is_admin' => FreshRSS_Auth::hasAccess('admin'),
            'writable' => self::extensionsWritable(),
            'queued' => self::getQueuedInstalls(),
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

    // ---------------------------------------------------------------
    // Server-side catalog session storage (replaces tmpDir round-trip)
    // ---------------------------------------------------------------

    /**
     * Store a catalog tmpDir in the PHP session, keyed by a random token.
     * Returns the token for client reference.
     */
    private static function storeCatalogSession(string $tmpDir): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $token = bin2hex(random_bytes(16));
        if (!isset($_SESSION['extmgr_catalogs'])) {
            $_SESSION['extmgr_catalogs'] = [];
        }
        $_SESSION['extmgr_catalogs'][$token] = [
            'tmpDir' => $tmpDir,
            'created' => time(),
        ];
        // Expire old entries (> 30 minutes)
        foreach ($_SESSION['extmgr_catalogs'] as $k => $v) {
            if (time() - $v['created'] > 1800) {
                if (is_dir($v['tmpDir'])) {
                    self::recursiveDelete($v['tmpDir']);
                }
                unset($_SESSION['extmgr_catalogs'][$k]);
            }
        }
        return $token;
    }

    /**
     * Retrieve a catalog tmpDir from the session by token.
     * Returns the tmpDir path or null if not found/expired.
     */
    public static function getCatalogSession(string $token): ?string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        if (!isset($_SESSION['extmgr_catalogs'][$token])) {
            return null;
        }
        $entry = $_SESSION['extmgr_catalogs'][$token];
        if (time() - $entry['created'] > 1800) {
            if (is_dir($entry['tmpDir'])) {
                self::recursiveDelete($entry['tmpDir']);
            }
            unset($_SESSION['extmgr_catalogs'][$token]);
            return null;
        }
        return $entry['tmpDir'];
    }

    // ---------------------------------------------------------------
    // Queue mode: deferred installs via FreshRSS data directory
    // ---------------------------------------------------------------

    /**
     * Queue an extension for installation on next container restart.
     * Copies the extension source to DATA_PATH/extmgr/queue/{dirName}/
     * and writes a manifest entry.
     */
    public static function queueInstall(string $tmpDir, string $extDirName): string|true {
        $extDirName = basename($extDirName);

        if ($extDirName === 'xExtension-ExtensionManager') {
            return 'Extension Manager cannot update itself this way. Replace the files manually.';
        }

        // Find source in extracted dir
        $sourceDir = self::findSourceInExtracted($tmpDir, $extDirName);
        if ($sourceDir === null) {
            return 'Extension ' . $extDirName . ' not found in extracted archive';
        }

        if (!file_exists($sourceDir . '/metadata.json') || !file_exists($sourceDir . '/extension.php')) {
            return 'Extension is missing required files (metadata.json or extension.php)';
        }

        $queueDir = self::queueDir() . '/queue';
        if (!is_dir($queueDir)) {
            if (!mkdir($queueDir, 0755, true)) {
                return 'Cannot create queue directory. Check permissions on FreshRSS data directory.';
            }
        }

        $targetQueue = $queueDir . '/' . $extDirName;
        if (is_dir($targetQueue)) {
            self::recursiveDelete($targetQueue);
        }
        self::recursiveCopy($sourceDir, $targetQueue);

        if (!is_dir($targetQueue) || !file_exists($targetQueue . '/metadata.json')) {
            return 'Failed to queue extension for installation.';
        }

        // Write/update manifest
        $manifestFile = self::queueDir() . '/manifest.json';
        $manifest = [];
        if (file_exists($manifestFile)) {
            $manifest = json_decode(file_get_contents($manifestFile), true) ?: [];
        }
        $meta = json_decode(file_get_contents($targetQueue . '/metadata.json'), true);
        $manifest[$extDirName] = [
            'name' => $meta['name'] ?? $extDirName,
            'version' => $meta['version'] ?? '0',
            'queued_at' => date('c'),
        ];
        file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT));

        return true;
    }

    /**
     * Get list of queued installs from manifest.
     */
    public static function getQueuedInstalls(): array {
        $manifestFile = self::queueDir() . '/manifest.json';
        if (!file_exists($manifestFile)) {
            return [];
        }
        return json_decode(file_get_contents($manifestFile), true) ?: [];
    }

    // ---------------------------------------------------------------
    // Catalog fetching
    // ---------------------------------------------------------------

    /**
     * Fetch the extension catalog from a GitHub repo.
     * Returns array with 'extensions', 'catalogToken' (session key), or 'error'.
     */
    public static function fetchRepoCatalog($url) {
        if (!preg_match('#^https://github\.com/[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+$#', $url)) {
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

        $extensionDirs = self::resolveExtensionDirs($tmpDir);

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

        if (empty($catalog)) {
            self::recursiveDelete($tmpDir);
            return ['error' => 'No extensions found in repository'];
        }

        // Store tmpDir in session instead of sending to client
        $catalogToken = self::storeCatalogSession($tmpDir);

        return ['extensions' => $catalog, 'catalogToken' => $catalogToken];
    }

    // ---------------------------------------------------------------
    // Installation
    // ---------------------------------------------------------------

    /**
     * Resolve all extension directories in an extracted repo archive.
     * Scans for xExtension-* dirs, with a fallback for single-extension repos
     * where metadata.json lives at the archive root instead.
     */
    private static function resolveExtensionDirs(string $tmpDir): array {
        $extensionDirs = [];
        self::findExtensionDirs($tmpDir, $extensionDirs, 0);

        if (empty($extensionDirs)) {
            $topDirs = glob($tmpDir . '/*', GLOB_ONLYDIR);
            if (!empty($topDirs)) {
                $repoDir = $topDirs[0];
                if (file_exists($repoDir . '/metadata.json')) {
                    $meta = json_decode(file_get_contents($repoDir . '/metadata.json'), true);
                    if (is_array($meta) && !empty($meta['entrypoint'])) {
                        $extensionDirs[] = ['source' => $repoDir, 'name' => 'xExtension-' . $meta['entrypoint']];
                    }
                }
            }
        }

        return $extensionDirs;
    }

    /**
     * Find the source directory for a named extension in an extracted repo.
     */
    private static function findSourceInExtracted(string $tmpDir, string $extDirName): ?string {
        foreach (self::resolveExtensionDirs($tmpDir) as $ext) {
            if ($ext['name'] === $extDirName) {
                return $ext['source'];
            }
        }
        return null;
    }

    /**
     * Install a single extension by directory name from an already-extracted repo.
     * Returns true on success, or an error string on failure.
     */
    public static function installFromExtracted($tmpDir, $extDirName): string|true {
        // Sanitize: strip any path components
        $extDirName = basename($extDirName);

        if (!is_dir($tmpDir)) {
            return 'Extracted repo not found. Try refreshing the catalog.';
        }

        if ($extDirName === 'xExtension-ExtensionManager') {
            return 'Extension Manager cannot update itself this way. Replace the files manually.';
        }

        // Validate tmpDir is within expected temp directory
        if (strpos(realpath($tmpDir), realpath(sys_get_temp_dir())) !== 0) {
            return 'Invalid temporary directory.';
        }

        $sourceDir = self::findSourceInExtracted($tmpDir, $extDirName);
        if ($sourceDir === null) {
            return 'Extension ' . $extDirName . ' not found in extracted archive';
        }

        if (!file_exists($sourceDir . '/metadata.json') || !file_exists($sourceDir . '/extension.php')) {
            return 'Extension is missing required files (metadata.json or extension.php)';
        }

        $extPath = dirname(dirname(__FILE__));
        $targetDir = $extPath . '/' . $extDirName;

        // Early writable check
        if (!is_writable($extPath)) {
            return 'Extensions directory is not writable. '
                . 'See the Extension Manager README for setup instructions: '
                . 'https://github.com/featurecreep-cron/freshrss-extensions#extension-manager';
        }

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
     * Install directly from a GitHub URL (single-extension repos, community table).
     */
    public static function downloadAndInstall($url, $extName = null) {
        // Handle tree URLs: https://github.com/user/repo/tree/branch/xExtension-Foo
        $targetExtDir = null;
        if (preg_match('#^(https://github\.com/[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+)/tree/[^/]+/(xExtension-[a-zA-Z0-9._-]+)$#', $url, $matches)) {
            $url = $matches[1];
            $targetExtDir = $matches[2];
        }

        $result = self::fetchRepoCatalog($url);
        if (isset($result['error'])) {
            return $result['error'];
        }

        $extensions = $result['extensions'];
        $catalogToken = $result['catalogToken'];
        $tmpDir = self::getCatalogSession($catalogToken);
        if (!$tmpDir) {
            return 'Catalog session expired. Please try again.';
        }

        // Determine which extension to install
        $extToInstall = null;

        if ($targetExtDir) {
            foreach ($extensions as $ext) {
                if ($ext['dir'] === $targetExtDir) {
                    $extToInstall = $ext;
                    break;
                }
            }
            if (!$extToInstall) {
                self::recursiveDelete($tmpDir);
                return 'Extension ' . $targetExtDir . ' not found in repository';
            }
        } elseif (count($extensions) === 1) {
            $extToInstall = $extensions[0];
        } elseif ($extName && count($extensions) > 1) {
            foreach ($extensions as $ext) {
                if ($ext['name'] === $extName) {
                    $extToInstall = $ext;
                    break;
                }
            }
            if (!$extToInstall) {
                self::recursiveDelete($tmpDir);
                return 'Extension "' . $extName . '" not found in repository';
            }
        } else {
            self::recursiveDelete($tmpDir);
            return 'Repository contains multiple extensions. Add it as a repository source in Extension Manager settings, then install individual extensions from the extensions page.';
        }

        // Decide mode: immediate install or queue
        if (self::extensionsWritable()) {
            $installResult = self::installFromExtracted($tmpDir, $extToInstall['dir']);
        } else {
            $installResult = self::queueInstall($tmpDir, $extToInstall['dir']);
        }
        self::recursiveDelete($tmpDir);
        return $installResult;
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

        if (!is_writable($extPath)) {
            return 'Extensions directory is not writable. Cannot remove extensions.';
        }

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
                    $metaFile = $extPath . '/' . basename($dirName) . '/metadata.json';
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
            $metaFile = $extPath . '/' . basename($dirName) . '/metadata.json';
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
        if (!is_dir($dst)) {
            if (!mkdir($dst, 0755, true) && !is_dir($dst)) {
                error_log('ExtensionManager: mkdir failed for ' . $dst);
                return;
            }
        }
        $entries = scandir($src);
        if ($entries === false) {
            error_log('ExtensionManager: scandir failed for ' . $src);
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $s = $src . '/' . $entry;
            $d = $dst . '/' . $entry;
            if (is_dir($s)) {
                self::recursiveCopy($s, $d);
            } else {
                $dir = dirname($d);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                if (!copy($s, $d)) {
                    error_log('ExtensionManager: copy failed ' . $s . ' -> ' . $d);
                }
            }
        }
    }
}
