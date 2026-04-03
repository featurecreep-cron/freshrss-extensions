<?php

/**
 * Abstraction over FreshRSS filter actions API.
 *
 * Handles the read-modify-write cycle safely — _filtersAction() replaces
 * all filters for a given action type, so we must read existing filters,
 * merge our change, and write the full list back.
 */
class QuickFilterService {

    /** FreshRSS uses semicolons to delimit multiple authors */
    private const AUTHOR_DELIMITERS = [';', ' · '];

    /**
     * Get all filter rules for a feed, structured for the client.
     *
     * @return array{filters: array<int, array{type: string, value: string, action: string, search: string}>}
     */
    public static function getFilters(int $feedId): array {
        $feed = self::loadFeed($feedId);
        if (!$feed) {
            return ['filters' => []];
        }

        $filters = [];
        foreach (['read', 'star'] as $action) {
            $searches = $feed->filtersAction($action);
            foreach ($searches as $search) {
                $searchStr = $search->__toString();
                $parsed = self::parseFilterString($searchStr);
                if ($parsed) {
                    $filters[] = [
                        'type' => $parsed['type'],
                        'value' => $parsed['value'],
                        'action' => $action,
                        'search' => $searchStr,
                    ];
                } else {
                    // Filter we don't understand — still display it
                    $filters[] = [
                        'type' => 'raw',
                        'value' => $searchStr,
                        'action' => $action,
                        'search' => $searchStr,
                    ];
                }
            }
        }

        return ['filters' => $filters];
    }

    /**
     * Add a filter to a feed.
     *
     * @return array{filters: array} Updated filter list on success
     * @throws InvalidArgumentException on invalid input
     */
    public static function addFilter(int $feedId, string $type, string $value, string $action): array {
        self::validateAction($action);
        $filterString = self::buildFilterString($type, $value);

        $feed = self::loadFeed($feedId);
        if (!$feed) {
            throw new InvalidArgumentException('Feed not found');
        }

        // Read existing filter strings for this action
        $existing = self::getFilterStrings($feed, $action);

        // Check for duplicate
        if (in_array($filterString, $existing, true)) {
            return self::getFilters($feedId);
        }

        $existing[] = $filterString;
        $feed->_filtersAction($action, $existing);
        self::saveFeed($feed);

        return self::getFilters($feedId);
    }

    /**
     * Remove a filter from a feed.
     *
     * @return array{filters: array} Updated filter list
     */
    public static function removeFilter(int $feedId, string $type, string $value, string $action): array {
        self::validateAction($action);

        $feed = self::loadFeed($feedId);
        if (!$feed) {
            throw new InvalidArgumentException('Feed not found');
        }

        // Reconstruct the search string server-side to avoid quote
        // sanitization issues in POST parameter transmission.
        // For 'raw' filters we use the value directly as the search string.
        if ($type === 'raw') {
            $targetSearch = $value;
        } else {
            $targetSearch = self::buildFilterString($type, $value);
        }

        // Normalize via BooleanSearch so we match regardless of quoting style
        $targetNorm = (new FreshRSS_BooleanSearch($targetSearch))->__toString();

        $existing = self::getFilterStrings($feed, $action);
        $updated = array_values(array_filter($existing, function ($s) use ($targetNorm) {
            return (new FreshRSS_BooleanSearch($s))->__toString() !== $targetNorm;
        }));

        $feed->_filtersAction($action, $updated);
        self::saveFeed($feed);

        return self::getFilters($feedId);
    }

    /**
     * Count articles matching a filter for preview/retroactive apply.
     */
    public static function countMatches(int $feedId, string $type, string $value): int {
        $entryDAO = FreshRSS_Factory::createEntryDao();
        $conditions = self::buildMatchConditions($type, $value);

        return $entryDAO->countUnreadReadFavorites()['all'] ?? 0;
        // TODO: Need custom query — FreshRSS EntryDAO doesn't expose
        // a count-by-filter method. Implement in applyToExisting instead.
    }

    /**
     * Apply a filter retroactively to existing articles.
     *
     * @return array{applied: int, total: int} Count of affected articles
     */
    public static function applyToExisting(int $feedId, string $type, string $value, string $action, int $offset = 0, int $batchSize = 50): array {
        self::validateAction($action);

        $feed = self::loadFeed($feedId);
        if (!$feed) {
            throw new InvalidArgumentException('Feed not found');
        }

        $entryDAO = FreshRSS_Factory::createEntryDao();

        // Build search for matching entries
        $filterString = self::buildFilterString($type, $value);
        $search = new FreshRSS_BooleanSearch($filterString);

        // Use FreshRSS's built-in search to find matching entries
        $entries = $entryDAO->listWhere(
            type: 'f',
            id: $feedId,
            state: FreshRSS_Entry::STATE_ALL,
            filters: $search,
            limit: $batchSize,
            offset: $offset
        );

        $applied = 0;
        $ids = [];
        foreach ($entries as $entry) {
            $ids[] = $entry->id();
            $applied++;
        }

        if (!empty($ids)) {
            if ($action === 'read') {
                $entryDAO->markRead($ids, true);
            } elseif ($action === 'star') {
                $entryDAO->markFavorite($ids, true);
            }
        }

        // Check if there are more
        $hasMore = ($applied === $batchSize);

        return [
            'applied' => $applied,
            'offset' => $offset + $applied,
            'hasMore' => $hasMore,
        ];
    }

    /**
     * Preview articles matching a filter (for the preview window).
     *
     * @return array{count: int, articles: array}
     */
    public static function previewMatches(int $feedId, string $type, string $value, int $limit = 50): array {
        $filterString = self::buildFilterString($type, $value);
        $search = new FreshRSS_BooleanSearch($filterString);
        $entryDAO = FreshRSS_Factory::createEntryDao();

        $entries = $entryDAO->listWhere(
            type: 'f',
            id: $feedId,
            state: FreshRSS_Entry::STATE_ALL,
            filters: $search,
            limit: $limit,
        );

        $articles = [];
        $count = 0;
        foreach ($entries as $entry) {
            $count++;
            if (count($articles) < $limit) {
                $articles[] = [
                    'id' => $entry->id(),
                    'title' => $entry->title(),
                    'author' => $entry->authors(true),
                    'date' => $entry->dateAdded(true),
                ];
            }
        }

        return [
            'count' => $count,
            'articles' => $articles,
        ];
    }

    /**
     * Get distinct authors for a feed (for dropdown population).
     *
     * @return string[] Unique author names
     */
    public static function getDistinctAuthors(int $feedId, int $entryLimit = 500): array {
        $entryDAO = FreshRSS_Factory::createEntryDao();
        $entries = $entryDAO->listWhere(
            type: 'f',
            id: $feedId,
            state: FreshRSS_Entry::STATE_ALL,
            limit: $entryLimit,
        );

        $authors = [];
        foreach ($entries as $entry) {
            $raw = $entry->authors(true);
            if ($raw === '') {
                continue;
            }
            // Authors may be delimited by ";" or " · "
            // Split on all known delimiters
            $parts = [$raw];
            foreach (self::AUTHOR_DELIMITERS as $delim) {
                $newParts = [];
                foreach ($parts as $p) {
                    foreach (explode($delim, $p) as $sub) {
                        $newParts[] = $sub;
                    }
                }
                $parts = $newParts;
            }
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $authors[$part] = true;
                }
            }
        }

        $result = array_keys($authors);
        sort($result, SORT_STRING | SORT_FLAG_CASE);
        return $result;
    }

    /**
     * Get distinct tags for a feed (for dropdown population).
     *
     * @return string[] Unique tag names
     */
    public static function getDistinctTags(int $feedId, int $entryLimit = 500): array {
        $entryDAO = FreshRSS_Factory::createEntryDao();
        $entries = $entryDAO->listWhere(
            type: 'f',
            id: $feedId,
            state: FreshRSS_Entry::STATE_ALL,
            limit: $entryLimit,
        );

        $tags = [];
        foreach ($entries as $entry) {
            foreach ($entry->tags() as $tag) {
                $tag = trim($tag);
                if ($tag !== '') {
                    $tags[$tag] = true;
                }
            }
        }

        $result = array_keys($tags);
        sort($result, SORT_STRING | SORT_FLAG_CASE);
        return $result;
    }

    // ── Internal helpers ──

    private static function loadFeed(int $feedId): ?FreshRSS_Feed {
        $feedDAO = FreshRSS_Factory::createFeedDao();
        return $feedDAO->searchById($feedId);
    }

    private static function saveFeed(FreshRSS_Feed $feed): void {
        $feedDAO = FreshRSS_Factory::createFeedDao();
        $feedDAO->updateFeed(
            $feed->id(),
            ['attributes' => $feed->attributes()]
        );
    }

    /**
     * Get all filter strings for a specific action on a feed.
     * @return string[]
     */
    private static function getFilterStrings(FreshRSS_Feed $feed, string $action): array {
        $searches = $feed->filtersAction($action);
        $strings = [];
        foreach ($searches as $search) {
            $strings[] = $search->__toString();
        }
        return $strings;
    }

    private static function validateAction(string $action): void {
        if (!in_array($action, ['read', 'star'], true)) {
            throw new InvalidArgumentException('Invalid action: ' . $action);
        }
    }

    /**
     * Build a FreshRSS filter string from structured input.
     */
    public static function buildFilterString(string $type, string $value): string {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Filter value cannot be empty');
        }

        switch ($type) {
            case 'author':
                // Always double-quote author values (FreshRSS convention)
                $escaped = str_replace('"', '\\"', $value);
                return 'author:"' . $escaped . '"';

            case 'tag':
                // Tags use # prefix, + for spaces
                $tagValue = str_replace(' ', '+', $value);
                return '#' . $tagValue;

            case 'keyword':
                if (strlen($value) < 3) {
                    throw new InvalidArgumentException('Keyword must be at least 3 characters');
                }
                if (strlen($value) > 200) {
                    throw new InvalidArgumentException('Keyword must be at most 200 characters');
                }
                // Double-quote keywords (FreshRSS convention)
                $escaped = str_replace('"', '\\"', $value);
                return 'intitle:"' . $escaped . '"';

            default:
                throw new InvalidArgumentException('Invalid filter type: ' . $type);
        }
    }

    /**
     * Parse a filter string back into type + value.
     * @return array{type: string, value: string}|null
     */
    public static function parseFilterString(string $search): ?array {
        $search = trim($search);

        // author:"Name" or author:'Name' or author:Name
        if (preg_match('/^author:"(.+)"$/', $search, $m)) {
            return ['type' => 'author', 'value' => str_replace('\\"', '"', $m[1])];
        }
        if (preg_match("/^author:'(.+)'$/", $search, $m)) {
            return ['type' => 'author', 'value' => str_replace("\\'", "'", $m[1])];
        }
        if (preg_match('/^author:(\S+)$/', $search, $m)) {
            return ['type' => 'author', 'value' => $m[1]];
        }

        // #tag or #tag+with+spaces
        if (preg_match('/^#(.+)$/', $search, $m)) {
            return ['type' => 'tag', 'value' => str_replace('+', ' ', $m[1])];
        }

        // intitle:"keyword" or intitle:'keyword' or intitle:keyword
        if (preg_match('/^intitle:"(.+)"$/', $search, $m)) {
            return ['type' => 'keyword', 'value' => str_replace('\\"', '"', $m[1])];
        }
        if (preg_match("/^intitle:'(.+)'$/", $search, $m)) {
            return ['type' => 'keyword', 'value' => str_replace("\\'", "'", $m[1])];
        }
        if (preg_match('/^intitle:(\S+)$/', $search, $m)) {
            return ['type' => 'keyword', 'value' => $m[1]];
        }

        return null;
    }
}
