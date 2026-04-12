<?php
declare(strict_types=1);

final class FreshExtension_extmgr_Controller extends Minz_ActionController {

    public function firstAction(): void {
        if (!FreshRSS_Auth::hasAccess()) {
            $this->sendJson(['error' => 'Unauthorized'], 403);
        }
        if (Minz_Request::isPost() && !FreshRSS_Auth::isCsrfOk()) {
            $this->sendJson(['error' => 'Invalid CSRF token. Reload the page and try again.'], 403);
        }
    }

    private function requireAdmin(): void {
        if (!FreshRSS_Auth::hasAccess('admin')) {
            $this->sendJson(['error' => 'Admin access required'], 403);
        }
    }

    public function installAction(): void {
        $this->requireAdmin();
        if (!Minz_Request::isPost()) {
            $this->sendJson(['error' => 'POST required'], 405);
        }

        $url = Minz_Request::paramString('url');
        $dir = basename(Minz_Request::paramString('dir'));
        $catalogToken = Minz_Request::paramString('catalogToken');

        // Per-extension install from a catalog session
        if ($dir && $catalogToken) {
            $tmpDir = ExtensionManagerExtension::getCatalogSession($catalogToken);
            if (!$tmpDir) {
                $this->sendJson(['error' => 'Catalog session expired. Refresh the page and try again.'], 400);
            }

            if (ExtensionManagerExtension::extensionsWritable()) {
                $result = ExtensionManagerExtension::installFromExtracted($tmpDir, $dir);
                if ($result === true) {
                    $this->sendJson(['success' => true, 'message' => $dir . ' installed successfully']);
                }
                $this->sendJson(['error' => is_string($result) ? $result : 'Unknown error'], 500);
            } else {
                $result = ExtensionManagerExtension::queueInstall($tmpDir, $dir);
                if ($result === true) {
                    $this->sendJson([
                        'success' => true,
                        'queued' => true,
                        'message' => $dir . ' queued for installation. Restart your FreshRSS container to complete.',
                    ]);
                }
                $this->sendJson(['error' => is_string($result) ? $result : 'Unknown error'], 500);
            }
        }

        // Direct URL install (community table, single-extension repos)
        if ($url) {
            $name = Minz_Request::paramString('name');
            $result = ExtensionManagerExtension::downloadAndInstall($url, $name ?: null);
            if ($result === true) {
                if (ExtensionManagerExtension::extensionsWritable()) {
                    $this->sendJson(['success' => true, 'message' => 'Extension installed successfully']);
                } else {
                    $this->sendJson([
                        'success' => true,
                        'queued' => true,
                        'message' => 'Extension queued for installation. Restart your FreshRSS container to complete.',
                    ]);
                }
            }
            $this->sendJson(['error' => is_string($result) ? $result : 'Unknown error'], 500);
        }

        $this->sendJson(['error' => 'Missing url or dir+catalogToken parameters'], 400);
    }

    public function catalogAction(): void {
        $this->requireAdmin();
        $url = Minz_Request::paramString('url');
        if (!$url) {
            $this->sendJson(['error' => 'Missing url parameter'], 400);
        }

        $result = ExtensionManagerExtension::fetchRepoCatalog($url);
        if (isset($result['error'])) {
            $this->sendJson(['error' => $result['error']], 500);
        }
        $this->sendJson([
            'success' => true,
            'extensions' => $result['extensions'],
            'catalogToken' => $result['catalogToken'],
        ]);
    }

    public function removeAction(): void {
        $this->requireAdmin();
        if (!Minz_Request::isPost()) {
            $this->sendJson(['error' => 'POST required'], 405);
        }

        $dir = Minz_Request::paramString('dir');
        if (!$dir) {
            $this->sendJson(['error' => 'Missing dir parameter'], 400);
        }

        if (ExtensionManagerExtension::extensionsWritable()) {
            $result = ExtensionManagerExtension::removeExtension($dir);
            if ($result === true) {
                $this->sendJson(['success' => true, 'message' => 'Extension removed']);
            }
            $this->sendJson(['error' => is_string($result) ? $result : 'Unknown error'], 500);
        }

        // Queue mode: stage removal for next queue processing
        $result = ExtensionManagerExtension::queueRemove($dir);
        if ($result === true) {
            $this->sendJson(['success' => true, 'queued' => true, 'message' => 'Removal queued']);
        }
        $this->sendJson(['error' => is_string($result) ? $result : 'Unknown error'], 500);
    }

    public function statusAction(): void {
        $this->sendJson([
            'installed' => ExtensionManagerExtension::getInstalledExtensions(),
            'writable' => ExtensionManagerExtension::extensionsWritable(),
            'queued' => ExtensionManagerExtension::getQueuedInstalls(),
        ]);
    }

    private function sendJson(array $data, int $code = 200): never {
        header('Content-Type: application/json', true, $code);
        echo json_encode($data);
        exit();
    }
}
