<?php

class ExtensionManagerExtension extends Minz_Extension {

    /**
     * Sidecar file recording which repository and branch an extension was
     * installed from. Lets the Extensions page distinguish two installs of the
     * same extension that came from different branches.
     */
    private const SOURCE_MARKER = '.extmgr-source.json';

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

    /* ===================== Self-update =====================
     *
     * Minz finds extensions by scanning every directory under the extensions
     * path and loading any that carry a valid metadata.json — the directory
     * name is not part of the test (lib/Minz/ExtensionManager.php). So two
     * directories holding this extension's metadata means extension.php is
     * included twice, ExtensionManagerExtension is declared twice, and the
     * fatal takes down all of FreshRSS rather than just this page. The same
     * scan is why the old in-place install refused to touch this extension:
     * a recursive copy has an observable half-written state, and every request
     * boots through this file.
     *
     * Everything below is arranged so neither can happen. A staged tree keeps
     * its manifest as metadata.json.pending and so is invisible to discovery;
     * the version being replaced has its manifest renamed away before the new
     * one is put in place. Activation is renames only — never a copy — and the
     * failure modes are all "Extension Manager is briefly missing", which
     * FreshRSS survives, and which the next request repairs.
     */
    private const SELF_DIR = 'xExtension-ExtensionManager';
    private const STAGE_DIR = '.extmgr-staged';
    private const ROLLBACK_PREFIX = '.extmgr-rollback-';
    private const PENDING_MANIFEST = 'metadata.json.pending';
    private const DISABLED_MANIFEST = 'metadata.json.disabled';
    private const PENDING_RECORD = '.pending.json';

    private static function extensionsPath(): string {
        return dirname(dirname(__FILE__));
    }

    /** The directory this file is running from, rather than a composed name, so a renamed install still works. */
    private static function selfPath(): string {
        return dirname(__FILE__);
    }

    private static function stagePath(): string {
        return self::extensionsPath() . '/' . self::STAGE_DIR;
    }

    public static function isSelfDir(string $extDirName): bool {
        return basename($extDirName) === self::SELF_DIR;
    }

    /**
     * Everything that must hold before a staged tree is allowed to replace the
     * running one. A bad payload here is not a failed update, it is a FreshRSS
     * that will not boot, so this refuses on anything it cannot confirm.
     */
    public static function verifyStagedTree(string $dir): ?string {
        $manifestPath = $dir . '/' . self::PENDING_MANIFEST;
        if (!is_file($manifestPath)) {
            return 'staged copy has no manifest';
        }
        $meta = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($meta)) {
            return 'staged manifest is not valid JSON';
        }
        foreach (['name', 'entrypoint', 'version'] as $key) {
            if (!isset($meta[$key]) || !is_string($meta[$key]) || $meta[$key] === '') {
                return 'staged manifest is missing "' . $key . '"';
            }
        }
        if ($meta['entrypoint'] !== 'ExtensionManager') {
            return 'staged manifest entrypoint is "' . $meta['entrypoint'] . '", expected ExtensionManager';
        }
        if (!is_file($dir . '/extension.php')) {
            return 'staged copy has no extension.php';
        }
        // Minz derives the class name from the entrypoint and warns if it is
        // absent; absent here means the swap produces an extension that loads
        // and then does nothing.
        if (strpos((string) file_get_contents($dir . '/extension.php'), 'class ExtensionManagerExtension') === false) {
            return 'staged extension.php does not declare ExtensionManagerExtension';
        }
        foreach (['Controllers', 'static', 'views'] as $sub) {
            if (!is_dir($dir . '/' . $sub)) {
                return 'staged copy is missing ' . $sub . '/';
            }
        }
        return self::firstSyntaxError($dir);
    }

    /**
     * Parse every PHP file without running any of it. token_get_all() with
     * TOKEN_PARSE raises ParseError on invalid syntax, which is the check that
     * makes self-update defensible: a truncated download or a bad commit is
     * caught here rather than at the next request's include.
     */
    private static function firstSyntaxError(string $dir): ?string {
        $walker = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($walker as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $src = file_get_contents($file->getPathname());
            if ($src === false) {
                return 'cannot read ' . $file->getFilename();
            }
            try {
                token_get_all($src, TOKEN_PARSE);
            } catch (ParseError $e) {
                return 'syntax error in ' . $file->getFilename() . ' — ' . $e->getMessage();
            }
        }
        return null;
    }

    /**
     * Step 1. Build the new version alongside the running one and verify it.
     * Nothing about the live install changes here, and a staged tree that is
     * never applied is inert.
     */
    public static function stageSelfUpdate(string $tmpDir, string $extDirName, ?string $url = null, ?string $branch = null): string|bool {
        if (!self::extensionsWritable()) {
            return 'Extensions directory is not writable — use the queued install script.';
        }
        $sourceDir = self::findSourceInExtracted($tmpDir, $extDirName);
        if ($sourceDir === null) {
            return 'Extension Manager not found in the downloaded archive';
        }
        if (!is_file($sourceDir . '/metadata.json')) {
            return 'Downloaded copy has no metadata.json';
        }

        // Assemble under a scratch name and publish with a single rename, so a
        // directory named .extmgr-staged never exists half-copied.
        $scratch = self::stagePath() . '.incoming';
        self::recursiveDelete($scratch);
        self::recursiveDelete(self::stagePath());
        self::recursiveCopy($sourceDir, $scratch);

        // Withhold the manifest before anything else: from this point the tree
        // is on disk but cannot be discovered as a second copy of us.
        if (!@rename($scratch . '/metadata.json', $scratch . '/' . self::PENDING_MANIFEST)) {
            self::recursiveDelete($scratch);
            return 'Could not withhold the staged manifest';
        }
        self::writeSourceMarker($scratch, $url, $branch);

        $error = self::verifyStagedTree($scratch);
        if ($error !== null) {
            self::recursiveDelete($scratch);
            return 'Refused to stage: ' . $error;
        }

        $meta = json_decode((string) file_get_contents($scratch . '/' . self::PENDING_MANIFEST), true);
        @file_put_contents($scratch . '/' . self::PENDING_RECORD, json_encode([
            'version' => is_array($meta) ? ($meta['version'] ?? '?') : '?',
            'source' => $url,
            'branch' => $branch,
            'staged_at' => date('c'),
            'apply' => false,
        ]));

        if (!@rename($scratch, self::stagePath())) {
            self::recursiveDelete($scratch);
            return 'Could not publish the staged copy';
        }
        self::pruneRollbacks();
        return true;
    }

    public static function pendingSelfUpdate(): ?array {
        $record = self::stagePath() . '/' . self::PENDING_RECORD;
        if (!is_file($record)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($record), true);
        return is_array($data) ? $data : null;
    }

    public static function discardSelfUpdate(): bool {
        self::recursiveDelete(self::stagePath());
        return !is_dir(self::stagePath());
    }

    /**
     * Step 2a. Arm the staged copy. Kept separate from activation so that
     * merely navigating never swaps the code out from under you — a staged
     * update sits untouched until it is asked for.
     */
    public static function requestSelfActivation(): string|bool {
        $pending = self::pendingSelfUpdate();
        if ($pending === null) {
            return 'Nothing is staged';
        }
        $error = self::verifyStagedTree(self::stagePath());
        if ($error !== null) {
            self::discardSelfUpdate();
            return 'Staged copy failed verification and was discarded: ' . $error;
        }
        $pending['apply'] = true;
        if (@file_put_contents(self::stagePath() . '/' . self::PENDING_RECORD, json_encode($pending)) === false) {
            return 'Could not arm the staged copy';
        }
        return true;
    }

    /** Rollback copies are inert (their manifest is renamed away); keep the most recent one only. */
    private static function pruneRollbacks(int $keep = 1): void {
        $entries = @scandir(self::extensionsPath()) ?: [];
        $rollbacks = array_values(array_filter($entries, fn($e) => strpos($e, self::ROLLBACK_PREFIX) === 0));
        sort($rollbacks);
        foreach (array_slice($rollbacks, 0, max(0, count($rollbacks) - $keep)) as $old) {
            self::recursiveDelete(self::extensionsPath() . '/' . $old);
        }
    }

    /**
     * Step 2b. Runs at the top of every boot, but the common case is one
     * is_dir() and out.
     *
     * Only on GET: a swap during the POST that requested it would leave that
     * request's controller and views coming from the new tree while this
     * process holds the old class in memory.
     */
    private static function maybeActivateSelfUpdate(): void {
        if (!is_dir(self::stagePath())) {
            return;
        }
        // Never from the CLI: actualize and the update scripts boot extensions
        // too, and a swap there would exit() in the middle of a feed refresh
        // with nobody to see the redirect.
        if (PHP_SAPI === 'cli' || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return;
        }
        $pending = self::pendingSelfUpdate();
        if ($pending === null || empty($pending['apply'])) {
            return;
        }

        $error = self::activateSelfUpdate();
        if ($error !== null) {
            Minz_Log::error('ExtensionManager self-update: ' . $error);
            return;
        }

        // Reload before anything else loads. extension.php is already included
        // for this request, but controllers and views are pulled in lazily and
        // would now come from the new tree — old class, new views. A fresh
        // request has no such split.
        if (!headers_sent()) {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? './');
            header('Location: ' . str_replace(["\r", "\n"], '', $uri));
            exit();
        }
    }

    /**
     * Renames only. The ordering is the safety argument, so read it before
     * changing it: at no instant may two directories under the extensions path
     * carry a valid metadata.json, because that is a double include of this
     * file and a fatal for the whole site.
     *
     *   1. live -> rollback         live is gone; only one manifest exists
     *   2. rollback manifest away   rollback can no longer be discovered
     *   3. staged -> live           still no manifest at the live path
     *   4. staged manifest in place  new version becomes discoverable
     *
     * Every window between those steps is "Extension Manager is missing",
     * which FreshRSS renders happily and the next request repairs.
     */
    private static function activateSelfUpdate(): ?string {
        $lockPath = self::extensionsPath() . '/.extmgr.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            return 'could not open the activation lock';
        }
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return null; // another request is mid-swap; nothing to report
        }

        try {
            $stage = self::stagePath();
            $live = self::selfPath();

            $error = self::verifyStagedTree($stage);
            if ($error !== null) {
                self::recursiveDelete($stage);
                return 'staged copy failed verification and was discarded: ' . $error;
            }

            $rollback = self::extensionsPath() . '/' . self::ROLLBACK_PREFIX . date('YmdHis');
            if (!@rename($live, $rollback)) {
                return 'could not move the running version aside';
            }
            @rename($rollback . '/metadata.json', $rollback . '/' . self::DISABLED_MANIFEST);

            if (!@rename($stage, $live)) {
                // Put it back exactly as it was, manifest last.
                @rename($rollback . '/' . self::DISABLED_MANIFEST, $rollback . '/metadata.json');
                @rename($rollback, $live);
                return 'could not move the staged copy into place; the running version was restored';
            }

            if (!@rename($live . '/' . self::PENDING_MANIFEST, $live . '/metadata.json')) {
                return 'staged copy is in place but its manifest could not be enabled';
            }

            @unlink($live . '/' . self::PENDING_RECORD);

            // With opcache.validate_timestamps=0 the swapped files would keep
            // running as the old bytecode until PHP restarts.
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            return null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockPath);
        }
    }

    public function init() {
        self::maybeActivateSelfUpdate();
        $this->registerController('extmgr');
        $this->registerViews();

        Minz_View::appendScript($this->getFileUrl('script.js'));
        Minz_View::appendStyle($this->getFileUrl('style.css'));
        $this->registerHook('js_vars', [$this, 'addVariables']);
    }

    public function addVariables($vars) {
        // If extensions dir became writable and there's a stale queue, drain it
        if (self::extensionsWritable()) {
            self::drainQueue();
        }

        $vars[$this->getName()]['configuration'] = [
            'installed' => self::getInstalledExtensions(),
            'repos' => $this->getUserConfigurationValue('repos') ?: [],
            'is_admin' => FreshRSS_Auth::hasAccess('admin'),
            'writable' => self::extensionsWritable(),
            'queued' => self::getQueuedInstalls(),
            'pendingSelf' => self::pendingSelfUpdate(),
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
                    $marker = self::readSourceMarker($dir);
                    $installed[basename($dir)] = [
                        'name' => $meta['name'] ?? basename($dir),
                        'version' => $meta['version'] ?? '0',
                        // null for hand-installed extensions and anything
                        // installed before Extension Manager recorded this.
                        'source' => $marker['url'] ?? null,
                        'branch' => $marker['branch'] ?? null,
                        'sourceLabel' => $marker
                            ? self::sourceLabel($marker['url'], $marker['branch'] ?? null)
                            : null,
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
    private static function storeCatalogSession(string $tmpDir, ?string $url = null, ?string $branch = null): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $token = bin2hex(random_bytes(16));
        if (!isset($_SESSION['extmgr_catalogs'])) {
            $_SESSION['extmgr_catalogs'] = [];
        }
        $_SESSION['extmgr_catalogs'][$token] = [
            'tmpDir' => $tmpDir,
            'url' => $url,
            'branch' => $branch,
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

    /**
     * Full catalog session entry — tmpDir plus the source it was fetched from.
     * Returns null if not found or expired. Unlike getCatalogSession() this does
     * not clean up on expiry; call that first if you need both.
     */
    public static function getCatalogEntry(string $token): ?array {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $entry = $_SESSION['extmgr_catalogs'][$token] ?? null;
        if (!is_array($entry) || time() - $entry['created'] > 1800) {
            return null;
        }
        return $entry;
    }

    /**
     * Record where an installed extension came from, as a sidecar file inside
     * the extension directory. Written into the *source* tree before the copy,
     * so both the direct and queued install paths carry it along without either
     * needing to know about it.
     *
     * A dotfile: FreshRSS only looks for metadata.json and extension.php, and
     * getInstalledExtensions() globs xExtension-* directories, so this is inert.
     */
    private static function writeSourceMarker(string $dir, ?string $url, ?string $branch): void {
        if ($url === null) {
            return;
        }
        @file_put_contents($dir . '/' . self::SOURCE_MARKER, json_encode([
            'url' => $url,
            'branch' => $branch,
            'installed' => gmdate('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Read the sidecar written by writeSourceMarker(). Null when the extension
     * was installed by hand or by an older Extension Manager.
     */
    private static function readSourceMarker(string $dir): ?array {
        $file = $dir . '/' . self::SOURCE_MARKER;
        if (!file_exists($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) && isset($data['url']) ? $data : null;
    }

    // ---------------------------------------------------------------
    // Queue mode: deferred installs via FreshRSS data directory
    // ---------------------------------------------------------------

    /**
     * Queue an extension for installation on next container restart.
     * Copies the extension source to DATA_PATH/extmgr/queue/{dirName}/
     * and writes a manifest entry.
     */
    public static function queueInstall(string $tmpDir, string $extDirName, ?string $url = null, ?string $branch = null): string|bool {
        $extDirName = basename($extDirName);

        // Self is allowed through here: the queue is applied by
        // install-queued.sh outside any request, and that script replaces this
        // extension with the same rename sequence used in-app rather than by
        // copying over the running tree.

        // Find source in extracted dir
        $sourceDir = self::findSourceInExtracted($tmpDir, $extDirName);
        if ($sourceDir === null) {
            return 'Extension ' . $extDirName . ' not found in extracted archive';
        }

        if (!file_exists($sourceDir . '/metadata.json') || !file_exists($sourceDir . '/extension.php')) {
            return 'Extension is missing required files (metadata.json or extension.php)';
        }

        // Written before the copy so it travels into the queue and out again
        // when install-queued.sh moves the directory into place.
        self::writeSourceMarker($sourceDir, $url, $branch);

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
     * Queue an extension for removal.
     */
    public static function queueRemove(string $dirName): string|bool {
        $dirName = basename($dirName);

        if ($dirName === 'xExtension-ExtensionManager') {
            return 'Cannot remove Extension Manager';
        }

        $extPath = dirname(dirname(__FILE__));
        if (!is_dir($extPath . '/' . $dirName)) {
            return 'Extension not found: ' . $dirName;
        }

        $queueDir = self::queueDir();
        if (!is_dir($queueDir)) {
            if (!mkdir($queueDir, 0755, true)) {
                return 'Cannot create queue directory.';
            }
        }

        $manifestFile = $queueDir . '/manifest.json';
        $manifest = [];
        if (file_exists($manifestFile)) {
            $manifest = json_decode(file_get_contents($manifestFile), true) ?: [];
        }
        $metaFile = $extPath . '/' . $dirName . '/metadata.json';
        $meta = file_exists($metaFile) ? json_decode(file_get_contents($metaFile), true) : null;
        $manifest[$dirName] = [
            'name' => $meta['name'] ?? $dirName,
            'action' => 'remove',
            'queued_at' => date('c'),
        ];
        file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT));

        return true;
    }

    /**
     * Drain the queue by processing queued operations directly (writable mode).
     * Called when extensions dir is writable but a queue exists from a previous
     * read-only session.
     */
    private static function drainQueue(): void {
        $manifestFile = self::queueDir() . '/manifest.json';
        if (!file_exists($manifestFile)) {
            return;
        }

        $extPath = dirname(dirname(__FILE__));
        $manifest = json_decode(file_get_contents($manifestFile), true) ?: [];

        // Process installs from queue directory
        $queueDir = self::queueDir() . '/queue';
        if (is_dir($queueDir)) {
            $dirs = glob($queueDir . '/xExtension-*', GLOB_ONLYDIR);
            foreach ($dirs as $srcDir) {
                $dirName = basename($srcDir);
                if ($dirName === 'xExtension-ExtensionManager') {
                    continue;
                }
                if (!file_exists($srcDir . '/metadata.json') || !file_exists($srcDir . '/extension.php')) {
                    continue;
                }
                $target = $extPath . '/' . $dirName;
                if (is_dir($target)) {
                    self::recursiveDelete($target);
                }
                self::recursiveCopy($srcDir, $target);
            }
            self::recursiveDelete($queueDir);
        }

        // Process removals from manifest
        foreach ($manifest as $dirName => $entry) {
            if (($entry['action'] ?? '') !== 'remove') {
                continue;
            }
            $dirName = basename($dirName);
            if ($dirName === 'xExtension-ExtensionManager') {
                continue;
            }
            $target = $extPath . '/' . $dirName;
            if (is_dir($target)) {
                self::recursiveDelete($target);
            }
        }

        @unlink($manifestFile);
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
    /**
     * Split a configured source into a repository URL and an optional branch.
     *
     * Accepted forms:
     *   https://github.com/owner/repo                    → branch null (resolve main, then master)
     *   https://github.com/owner/repo/tree/develop        → branch "develop"
     *   https://github.com/owner/repo/tree/fix/some-bug   → branch "fix/some-bug"
     *
     * The /tree/ form is what GitHub puts in the address bar when you switch
     * branches, so it can be pasted straight in. Branch names may contain
     * slashes, so everything after /tree/ is the branch.
     *
     * Returns ['url' => string, 'branch' => ?string], or null if not a GitHub URL.
     */
    public static function parseSource(string $source): ?array {
        $source = trim($source);
        $source = rtrim($source, '/');

        if (!preg_match('#^(https://github\.com/[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+?)(?:\.git)?(?:/tree/(.+))?$#', $source, $m)) {
            return null;
        }

        $branch = isset($m[2]) && $m[2] !== '' ? $m[2] : null;
        if ($branch !== null && !self::isSafeBranch($branch)) {
            return null;
        }

        return ['url' => $m[1], 'branch' => $branch];
    }

    /**
     * Branch names go into a URL path, so refuse traversal and anything that is
     * not a plausible ref. Slashes are allowed — `fix/foo` is a normal branch.
     */
    private static function isSafeBranch(string $branch): bool {
        if (strpos($branch, '..') !== false) {
            return false;
        }
        return (bool) preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $branch);
    }

    /**
     * Human-readable label for a source, e.g. "owner/repo @ develop".
     */
    public static function sourceLabel(string $url, ?string $branch): string {
        $label = preg_replace('#^https://github\.com/#', '', $url);
        return $branch === null ? $label : $label . ' @ ' . $branch;
    }

    public static function fetchRepoCatalog($source) {
        $parsed = self::parseSource($source);
        if ($parsed === null) {
            return ['error' => 'Invalid URL: expected https://github.com/owner/repo, optionally with /tree/branch'];
        }

        $url = $parsed['url'];
        $branch = $parsed['branch'];

        if ($branch !== null) {
            // Explicit branch: no silent fallback. Falling back to main here
            // would install code the user did not ask for and report success.
            $zipData = self::downloadZip($url . '/archive/refs/heads/' . $branch . '.zip');
            if ($zipData === false) {
                return ['error' => 'Branch "' . $branch . '" not found in ' . $url];
            }
        } else {
            $branch = 'main';
            $zipData = self::downloadZip($url . '/archive/refs/heads/main.zip');
            if ($zipData === false) {
                $branch = 'master';
                $zipData = self::downloadZip($url . '/archive/refs/heads/master.zip');
            }
            if ($zipData === false) {
                return ['error' => 'Failed to download from ' . $url];
            }
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
                    'branch' => $branch,
                    'label' => self::sourceLabel($url, $branch),
                ];
            }
        }

        if (empty($catalog)) {
            self::recursiveDelete($tmpDir);
            return ['error' => 'No extensions found in repository'];
        }

        // Store tmpDir in session instead of sending to client. The source is
        // stored with it so an install can record where the code came from.
        $catalogToken = self::storeCatalogSession($tmpDir, $url, $branch);

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
    public static function installFromExtracted($tmpDir, $extDirName, ?string $url = null, ?string $branch = null): string|bool {
        // Sanitize: strip any path components
        $extDirName = basename($extDirName);

        if (!is_dir($tmpDir)) {
            return 'Extracted repo not found. Try refreshing the catalog.';
        }

        // Stays blocked, and must. This path copies file by file over the live
        // directory, which is the one serving the request — a concurrent boot
        // can read a half-written extension.php and fatal the whole site.
        // Self-update goes through stageSelfUpdate() + activateSelfUpdate().
        if ($extDirName === 'xExtension-ExtensionManager') {
            return 'Extension Manager updates itself by staging, not by copying over the running copy — use Download update.';
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

        // Written before the copy so it travels with the files.
        self::writeSourceMarker($sourceDir, $url, $branch);

        $extPath = dirname(dirname(__FILE__));
        $targetDir = $extPath . '/' . $extDirName;

        // Early writable check
        if (!is_writable($extPath)) {
            return 'Extensions directory is not writable. '
                . 'See the Extension Manager README for setup instructions: '
                . 'https://github.com/featurecreep-cron/freshrss-extensions/blob/main/xExtension-ExtensionManager/README.md#install-modes';
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
        // Handle deep tree URLs pointing at one extension inside a repo:
        //   https://github.com/owner/repo/tree/<branch>/xExtension-Foo
        // The branch is kept and passed through to fetchRepoCatalog() — it used
        // to be matched and thrown away, so every such URL silently installed
        // main regardless of the branch the user pasted.
        $targetExtDir = null;
        if (preg_match('#^(https://github\.com/[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+)/tree/(.+)/(xExtension-[a-zA-Z0-9._-]+)$#', $url, $matches)) {
            $url = $matches[1] . '/tree/' . $matches[2];
            $targetExtDir = $matches[3];
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
        $srcUrl = $extToInstall['url'] ?? null;
        $srcBranch = $extToInstall['branch'] ?? null;
        if (self::extensionsWritable()) {
            $installResult = self::installFromExtracted($tmpDir, $extToInstall['dir'], $srcUrl, $srcBranch);
        } else {
            $installResult = self::queueInstall($tmpDir, $extToInstall['dir'], $srcUrl, $srcBranch);
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
