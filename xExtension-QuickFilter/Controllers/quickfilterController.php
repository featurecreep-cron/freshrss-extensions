<?php
declare(strict_types=1);

final class FreshExtension_quickfilter_Controller extends Minz_ActionController {

    public function firstAction(): void {
        if (!FreshRSS_Auth::hasAccess()) {
            $this->sendJson(['error' => 'Unauthorized'], 403);
        }
        if (Minz_Request::isPost() && !FreshRSS_Auth::isCsrfOk()) {
            $this->sendJson(['error' => 'Invalid CSRF token'], 403);
        }
    }

    /**
     * GET ?c=quickfilter&a=filters&feedId=123
     * List all filters for a feed.
     */
    public function filtersAction(): void {
        $feedId = Minz_Request::paramInt('feedId');
        if ($feedId <= 0) {
            $this->sendJson(['error' => 'Missing feedId'], 400);
        }

        $result = QuickFilterService::getFilters($feedId);
        $this->sendJson($result);
    }

    /**
     * POST ?c=quickfilter&a=add
     * Add a filter to a feed.
     * Params: feedId, type (author|tag|keyword), value, action (read|star)
     */
    public function addAction(): void {
        if (!Minz_Request::isPost()) {
            $this->sendJson(['error' => 'POST required'], 405);
        }

        $feedId = Minz_Request::paramInt('feedId');
        $type = Minz_Request::paramString('type');
        $value = Minz_Request::paramString('value');
        $action = Minz_Request::paramString('action');

        if ($feedId <= 0 || !$type || !$value || !$action) {
            $this->sendJson(['error' => 'Missing required parameters: feedId, type, value, action'], 400);
        }

        try {
            $result = QuickFilterService::addFilter($feedId, $type, $value, $action);
            $this->sendJson(['success' => true, 'filters' => $result['filters']]);
        } catch (InvalidArgumentException $e) {
            $this->sendJson(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * POST ?c=quickfilter&a=remove
     * Remove a filter from a feed.
     * Params: feedId, search (the filter string), action (read|star)
     */
    public function removeAction(): void {
        if (!Minz_Request::isPost()) {
            $this->sendJson(['error' => 'POST required'], 405);
        }

        $feedId = Minz_Request::paramInt('feedId');
        $search = Minz_Request::paramString('search');
        $action = Minz_Request::paramString('action');

        if ($feedId <= 0 || !$search || !$action) {
            $this->sendJson(['error' => 'Missing required parameters: feedId, search, action'], 400);
        }

        try {
            $result = QuickFilterService::removeFilter($feedId, $search, $action);
            $this->sendJson([
                'success' => true,
                'filters' => $result['filters'],
                '_debug' => $result['_debug'] ?? null,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->sendJson(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * GET ?c=quickfilter&a=feedData&feedId=123
     * Get distinct authors and tags for dropdown population.
     */
    public function feedDataAction(): void {
        $feedId = Minz_Request::paramInt('feedId');
        if ($feedId <= 0) {
            $this->sendJson(['error' => 'Missing feedId'], 400);
        }

        $authors = QuickFilterService::getDistinctAuthors($feedId);
        $tags = QuickFilterService::getDistinctTags($feedId);

        $this->sendJson([
            'authors' => $authors,
            'tags' => $tags,
        ]);
    }

    /**
     * GET ?c=quickfilter&a=preview&feedId=123&type=author&value=Name
     * Preview articles matching a filter.
     */
    public function previewAction(): void {
        $feedId = Minz_Request::paramInt('feedId');
        $type = Minz_Request::paramString('type');
        $value = Minz_Request::paramString('value');

        if ($feedId <= 0 || !$type || !$value) {
            $this->sendJson(['error' => 'Missing required parameters'], 400);
        }

        try {
            $result = QuickFilterService::previewMatches($feedId, $type, $value);
            $this->sendJson($result);
        } catch (InvalidArgumentException $e) {
            $this->sendJson(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * POST ?c=quickfilter&a=apply
     * Apply a filter retroactively to existing articles (batched).
     * Params: feedId, type, value, action, offset (for batching)
     */
    public function applyAction(): void {
        if (!Minz_Request::isPost()) {
            $this->sendJson(['error' => 'POST required'], 405);
        }

        $feedId = Minz_Request::paramInt('feedId');
        $type = Minz_Request::paramString('type');
        $value = Minz_Request::paramString('value');
        $action = Minz_Request::paramString('action');
        $offset = Minz_Request::paramInt('offset');

        if ($feedId <= 0 || !$type || !$value || !$action) {
            $this->sendJson(['error' => 'Missing required parameters'], 400);
        }

        try {
            $result = QuickFilterService::applyToExisting($feedId, $type, $value, $action, $offset);
            $this->sendJson(['success' => true] + $result);
        } catch (InvalidArgumentException $e) {
            $this->sendJson(['error' => $e->getMessage()], 400);
        }
    }

    private function sendJson(array $data, int $code = 200): never {
        header('Content-Type: application/json', true, $code);
        echo json_encode($data);
        exit();
    }
}
