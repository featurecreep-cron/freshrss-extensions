<?php
declare(strict_types=1);

final class FreshExtension_rcafilter_Controller extends Minz_ActionController {

    public function firstAction(): void {
        if (!FreshRSS_Auth::hasAccess()) {
            $this->sendJson(['error' => 'Unauthorized'], 403);
        }
        if (Minz_Request::isPost() && !FreshRSS_Auth::isCsrfOk()) {
            $this->sendJson(['error' => 'Invalid CSRF token'], 403);
        }
    }

    public function addAction(): void {
        if (!Minz_Request::isPost()) {
            $this->sendJson(['error' => 'POST required'], 405);
        }

        $feedId = Minz_Request::paramInt('id');
        $filter = trim(Minz_Request::paramString('filter'));

        if ($feedId === 0 || $filter === '') {
            $this->sendJson(['error' => 'Missing id or filter'], 400);
        }

        $feedDAO = FreshRSS_Factory::createFeedDao();
        $feed = $feedDAO->searchById($feedId);
        if ($feed === null) {
            $this->sendJson(['error' => 'Feed not found'], 404);
        }

        // Get existing read filters, append new one
        $existing = [];
        foreach ($feed->filtersAction('read') as $booleanSearch) {
            $existing[] = $booleanSearch->toString();
        }

        if (in_array($filter, $existing, true)) {
            $this->sendJson(['success' => true, 'message' => 'Filter already exists']);
            return;
        }

        $existing[] = $filter;
        $feed->_filtersAction('read', $existing);

        $ok = $feedDAO->updateFeed($feedId, ['attributes' => $feed->attributes()]);
        if ($ok !== false) {
            $this->sendJson(['success' => true, 'message' => 'Filter added: ' . $filter]);
        } else {
            $this->sendJson(['error' => 'Failed to save feed'], 500);
        }
    }

    private function sendJson(array $data, int $status = 200): never {
        header('Content-Type: application/json', true, $status);
        echo json_encode($data);
        exit();
    }
}
