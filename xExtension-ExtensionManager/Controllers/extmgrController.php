<?php
declare(strict_types=1);

final class FreshExtension_extmgr_Controller extends Minz_ActionController {

    public function firstAction(): void {
        if (!FreshRSS_Auth::hasAccess()) {
            $this->sendJson(['error' => 'Unauthorized'], 403);
            return;
        }
        if (Minz_Request::isPost() && !FreshRSS_Auth::isCsrfOk()) {
            $this->sendJson(['error' => 'Invalid CSRF token. Reload the page and try again.'], 403);
            return;
        }
    }

    public function installAction(): void {
        if (!Minz_Request::isPost()) {
            $this->sendJson(['error' => 'POST required'], 405);
            return;
        }

        $url = Minz_Request::paramString('url');
        $dir = Minz_Request::paramString('dir');
        $tmpDir = Minz_Request::paramString('tmpDir');

        // Per-extension install from an already-extracted repo
        if ($dir && $tmpDir) {
            $result = ExtensionManagerExtension::installFromExtracted($tmpDir, $dir);
            if ($result === true) {
                $this->sendJson(['success' => true, 'message' => $dir . ' installed successfully']);
            } else {
                $this->sendJson(['error' => is_string($result) ? $result : 'Unknown error'], 500);
            }
            return;
        }

        // Direct URL install (single-extension repos)
        if ($url) {
            $result = ExtensionManagerExtension::downloadAndInstall($url);
            if ($result === true) {
                $this->sendJson(['success' => true, 'message' => 'Extension installed successfully']);
            } else {
                $this->sendJson(['error' => is_string($result) ? $result : 'Unknown error'], 500);
            }
            return;
        }

        $this->sendJson(['error' => 'Missing url or dir+tmpDir parameters'], 400);
    }

    public function catalogAction(): void {
        $url = Minz_Request::paramString('url');
        if (!$url) {
            $this->sendJson(['error' => 'Missing url parameter'], 400);
            return;
        }

        $result = ExtensionManagerExtension::fetchRepoCatalog($url);
        if (isset($result['error'])) {
            $this->sendJson(['error' => $result['error']], 500);
        } else {
            $this->sendJson([
                'success' => true,
                'extensions' => $result['extensions'],
                'tmpDir' => $result['tmpDir'],
            ]);
        }
    }

    public function removeAction(): void {
        if (!Minz_Request::isPost()) {
            $this->sendJson(['error' => 'POST required'], 405);
            return;
        }

        $dir = Minz_Request::paramString('dir');
        if (!$dir) {
            $this->sendJson(['error' => 'Missing dir parameter'], 400);
            return;
        }

        $result = ExtensionManagerExtension::removeExtension($dir);
        if ($result === true) {
            $this->sendJson(['success' => true, 'message' => 'Extension removed']);
        } else {
            $this->sendJson(['error' => is_string($result) ? $result : 'Unknown error'], 500);
        }
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
