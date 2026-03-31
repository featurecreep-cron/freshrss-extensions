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
        $dir = Minz_Request::paramString('dir');
        $tmpDir = Minz_Request::paramString('tmpDir');

        // Per-extension install from an already-extracted repo
        if ($dir && $tmpDir) {
            $result = ExtensionManagerExtension::installFromExtracted($tmpDir, $dir);
            if ($result === true) {
                $this->sendJson(['success' => true, 'message' => $dir . ' installed successfully']);
            }
            $this->sendJson(['error' => is_string($result) ? $result : 'Unknown error'], 500);
        }

        // Direct URL install
        if ($url) {
            $name = Minz_Request::paramString('name');
            $result = ExtensionManagerExtension::downloadAndInstall($url, $name ?: null);
            if ($result === true) {
                $this->sendJson(['success' => true, 'message' => 'Extension installed successfully']);
            }
            $this->sendJson(['error' => is_string($result) ? $result : 'Unknown error'], 500);
        }

        $this->sendJson(['error' => 'Missing url or dir+tmpDir parameters'], 400);
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
            'tmpDir' => $result['tmpDir'],
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

        $result = ExtensionManagerExtension::removeExtension($dir);
        if ($result === true) {
            $this->sendJson(['success' => true, 'message' => 'Extension removed']);
        }
        $this->sendJson(['error' => is_string($result) ? $result : 'Unknown error'], 500);
    }

    public function statusAction(): void {
        $this->sendJson([
            'installed' => ExtensionManagerExtension::getInstalledExtensions(),
        ]);
    }

    private function sendJson(array $data, int $code = 200): never {
        header('Content-Type: application/json', true, $code);
        echo json_encode($data);
        exit();
    }
}
