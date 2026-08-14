<?php
declare(strict_types=1);

final class FreshExtension_rcafilter_Controller extends Minz_ActionController {

    /**
     * Filter actions this controller will write. FreshRSS applies all three
     * (read, star, label) from FilterActionsTrait regardless of owner, but its
     * own subscription page only ever writes 'read' — so a feed-level star
     * filter works and is simply not reachable through stock UI.
     */
    private const ACTIONS = ['read', 'star'];

    /**
     * Ceiling on the retroactive sweep. The read path delegates to
     * markReadFeed(), which is a single UPDATE and needs no cap; starring has
     * no search-scoped equivalent, so it collects ids first and that list is
     * what has to stay bounded. Reported to the user when it bites rather than
     * silently truncated.
     */
    private const RETRO_LIMIT = 1000;

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
        // Absent means 'read': older callers posted no action at all, and the
        // hide-articles menu item is still the common case.
        $action = Minz_Request::paramString('action');
        if ($action === '') {
            $action = 'read';
        }

        if ($feedId === 0 || $filter === '') {
            $this->sendJson(['error' => 'Missing id or filter'], 400);
        }
        if (!in_array($action, self::ACTIONS, true)) {
            $this->sendJson(['error' => 'Unsupported action: ' . $action], 400);
        }

        $feedDAO = FreshRSS_Factory::createFeedDao();
        $feed = $feedDAO->searchById($feedId);
        if ($feed === null) {
            $this->sendJson(['error' => 'Feed not found'], 404);
        }

        // Compare and store the parsed form, not the raw string. FreshRSS only
        // quotes a value that needs it (Search::quote() checks for spaces and
        // separators), so the `intitle:"Boeing"` posted from the menu comes back
        // out of toString() as `intitle:Boeing`. Comparing raw against
        // re-serialised would miss that as a duplicate and append the same rule
        // on every use. Existing entries are already normalised — they are read
        // back through toString() here and saved in that form.
        $search = new FreshRSS_BooleanSearch($filter);
        $normalized = $search->toString();

        $existing = [];
        foreach ($feed->filtersAction($action) as $booleanSearch) {
            $existing[] = $booleanSearch->toString();
        }

        if (in_array($normalized, $existing, true)) {
            $this->sendJson(['success' => true, 'message' => 'Filter already exists']);
            return;
        }

        $existing[] = $normalized;
        $feed->_filtersAction($action, $existing);

        $ok = $feedDAO->updateFeed($feedId, ['attributes' => $feed->attributes()]);
        if ($ok === false) {
            $this->sendJson(['error' => 'Failed to save feed'], 500);
        }

        // A saved filter only runs against articles fetched from here on, so
        // apply it once to what is already stored — otherwise the rule appears
        // to do nothing until the next refresh.
        [$affected, $capped] = $action === 'star'
            ? $this->starExisting($feedId, $search)
            : $this->readExisting($feedId, $search);

        $verb = $action === 'star' ? 'starred' : 'marked read';
        $msg = 'Filter added';
        if ($affected > 0) {
            $msg .= ', ' . $affected . ' existing article' . ($affected === 1 ? '' : 's') . ' ' . $verb;
        }
        // Reported whether or not anything changed: hitting the cap with zero
        // changes means every one of those matches was already starred, and
        // there may still be older ones that are not.
        if ($capped) {
            $msg .= ' (limited to the ' . self::RETRO_LIMIT . ' most recent matches; re-run to continue)';
        }
        $this->sendJson(['success' => true, 'message' => $msg]);
    }

    /**
     * @return array{0: int, 1: bool} count affected, and whether the cap was hit
     */
    private function readExisting(int $feedId, FreshRSS_BooleanSearch $search): array {
        $entryDAO = FreshRSS_Factory::createEntryDao();
        $marked = $entryDAO->markReadFeed($feedId, uTimeString(), $search);
        return [$marked === false ? 0 : $marked, false];
    }

    /**
     * There is no markFavoriteFeed() to mirror markReadFeed() — markFavorite()
     * takes ids — so collect the matching ids first. Passing an explicit limit
     * matters: listIdsWhere() defaults to 1.
     *
     * @return array{0: int, 1: bool} count affected, and whether the cap was hit
     */
    private function starExisting(int $feedId, FreshRSS_BooleanSearch $search): array {
        $entryDAO = FreshRSS_Factory::createEntryDao();
        $ids = $entryDAO->listIdsWhere('f', $feedId, FreshRSS_Entry::STATE_ALL, $search, limit: self::RETRO_LIMIT);
        if ($ids === null || $ids === []) {
            return [0, false];
        }
        $starred = $entryDAO->markFavorite($ids, true);
        return [$starred === false ? 0 : $starred, count($ids) >= self::RETRO_LIMIT];
    }

    private function sendJson(array $data, int $status = 200): never {
        header('Content-Type: application/json', true, $status);
        echo json_encode($data);
        exit();
    }
}
