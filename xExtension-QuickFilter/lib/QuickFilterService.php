<?php

/**
 * Abstraction over FreshRSS filter actions API.
 *
 * Handles the read-modify-write cycle safely — _filtersAction() replaces
 * all filters for a given action type, so we must read existing filters,
 * merge our change, and write the full list back.
 */
class QuickFilterService {

    /** Keyword scopes — which part of an article the keyword is matched against */
    public const SCOPE_TITLE = 'title';
    public const SCOPE_ARTICLE = 'article';
    public const SCOPE_BOTH = 'both';

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
                        'scope' => $parsed['scope'] ?? null,
                        'action' => $action,
                        'search' => $searchStr,
                    ];
                } else {
                    // Filter we don't understand — still display it
                    $filters[] = [
                        'type' => 'raw',
                        'value' => $searchStr,
                        'scope' => null,
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
    public static function addFilter(int $feedId, string $type, string $value, string $action, string $scope = self::SCOPE_TITLE): array {
        self::validateAction($action);
        $filterString = self::buildFilterString($type, $value, $scope);

        $feed = self::loadFeed($feedId);
        if (!$feed) {
            throw new InvalidArgumentException('Feed not found');
        }

        // Read existing filter strings for this action
        $existing = self::getFilterStrings($feed, $action);

        // Compare in parsed form, not as written. Search::quote() only quotes values
        // that need it, so the intitle:"Boeing" we write comes back from core as
        // intitle:Boeing — a raw string compare never matches and appends the same
        // rule on every use.
        if (self::containsFilter($existing, $filterString)) {
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
    public static function removeFilter(int $feedId, string $type, string $value, string $action, string $scope = self::SCOPE_TITLE): array {
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
            $targetSearch = self::buildFilterString($type, $value, $scope);
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
     * Apply a filter retroactively to existing articles.
     *
     * @return array{applied: int, total: int} Count of affected articles
     */
    public static function applyToExisting(int $feedId, string $type, string $value, string $action, int $offset = 0, int $batchSize = 50, string $scope = self::SCOPE_TITLE): array {
        self::validateAction($action);

        $feed = self::loadFeed($feedId);
        if (!$feed) {
            throw new InvalidArgumentException('Feed not found');
        }

        $entryDAO = FreshRSS_Factory::createEntryDao();

        // Build search for matching entries
        $filterString = self::buildFilterString($type, $value, $scope);
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
    public static function previewMatches(int $feedId, string $type, string $value, int $limit = 50, string $scope = self::SCOPE_TITLE): array {
        $filterString = self::buildFilterString($type, $value, $scope);
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

    /**
     * @param string[] $existing
     */
    private static function containsFilter(array $existing, string $filterString): bool {
        $targetNorm = (new FreshRSS_BooleanSearch($filterString))->__toString();
        foreach ($existing as $s) {
            if ((new FreshRSS_BooleanSearch($s))->__toString() === $targetNorm) {
                return true;
            }
        }
        return false;
    }

    private static function validateScope(string $scope): void {
        if (!in_array($scope, [self::SCOPE_TITLE, self::SCOPE_ARTICLE, self::SCOPE_BOTH], true)) {
            throw new InvalidArgumentException('Invalid scope: ' . $scope);
        }
    }

    private static function validateAction(string $action): void {
        if (!in_array($action, ['read', 'star'], true)) {
            throw new InvalidArgumentException('Invalid action: ' . $action);
        }
    }

    /**
     * Build a FreshRSS filter string from structured input.
     */
    public static function buildFilterString(string $type, string $value, string $scope = self::SCOPE_TITLE): string {
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
                // Scope decides which operator carries the keyword. FreshRSS matches
                // all three the same way at fetch time — case-insensitive substring —
                // and differs only in what it looks at: intitle: the title, intext: the
                // content, a bare term either of them (Entry::matches()).
                self::validateScope($scope);
                $escaped = str_replace('"', '\\"', $value);
                switch ($scope) {
                    case self::SCOPE_ARTICLE:
                        return 'intext:"' . $escaped . '"';
                    case self::SCOPE_BOTH:
                        // A bare term is the only form with no operator in front of it,
                        // so a value that looks like one is indistinguishable from one
                        // after the round trip: core stores "#rust" and "author:foo"
                        // correctly, but Search::__toString() drops the quotes it no
                        // longer needs, and the rule reads back as a tag or an author.
                        // The filter still matches the right articles; the row above it
                        // would name the wrong kind of rule and preview the wrong query.
                        // Refused rather than silently mislabelled — the other two
                        // scopes carry a prefix and are safe.
                        if (preg_match('/^[#!-]|^[A-Za-z]+:/', $value) === 1) {
                            throw new InvalidArgumentException(
                                'A title-or-body keyword cannot start with # or - or contain '
                                . 'a word followed by a colon: FreshRSS reads those as search '
                                . 'operators. Use the Title or Article body scope instead.'
                            );
                        }
                        // Quoted so a multi-word value stays one phrase. Unquoted, core
                        // splits a bare term on spaces into separate AND-ed needles.
                        return '"' . $escaped . '"';
                    default:
                        return 'intitle:"' . $escaped . '"';
                }

            default:
                throw new InvalidArgumentException('Invalid filter type: ' . $type);
        }
    }

    /**
     * Parse a filter string back into type + value (+ scope, for keywords).
     *
     * Anything not recognised returns null and is shown as a raw rule rather than
     * being rewritten — a hand-written boolean expression is not ours to reshape.
     * @return array{type: string, value: string, scope?: string}|null
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

        // intitle:"keyword" or intitle:'keyword' or intitle:keyword — title only
        if (preg_match('/^intitle:"(.+)"$/', $search, $m)) {
            return self::keyword(str_replace('\\"', '"', $m[1]), self::SCOPE_TITLE);
        }
        if (preg_match("/^intitle:'(.+)'$/", $search, $m)) {
            return self::keyword(str_replace("\\'", "'", $m[1]), self::SCOPE_TITLE);
        }
        if (preg_match('/^intitle:(\S+)$/', $search, $m)) {
            return self::keyword($m[1], self::SCOPE_TITLE);
        }

        // intext:"keyword" and friends — article body only
        if (preg_match('/^intext:"(.+)"$/', $search, $m)) {
            return self::keyword(str_replace('\\"', '"', $m[1]), self::SCOPE_ARTICLE);
        }
        if (preg_match("/^intext:'(.+)'$/", $search, $m)) {
            return self::keyword(str_replace("\\'", "'", $m[1]), self::SCOPE_ARTICLE);
        }
        if (preg_match('/^intext:(\S+)$/', $search, $m)) {
            return self::keyword($m[1], self::SCOPE_ARTICLE);
        }

        // A bare term matches title or content. Recognised last and conservatively:
        // an unquoted term is only ours if it carries no operator syntax at all, so
        // f:70, -word, boolean groups and the like stay raw instead of being
        // mislabelled as a keyword we could rewrite.
        if (preg_match('/^"(.+)"$/', $search, $m)) {
            return self::keyword(str_replace('\\"', '"', $m[1]), self::SCOPE_BOTH);
        }
        if (preg_match("/^'(.+)'$/", $search, $m)) {
            return self::keyword(str_replace("\\'", "'", $m[1]), self::SCOPE_BOTH);
        }
        if (preg_match('/^(?![-!])([^\s:#"\'()]+)$/', $search, $m)) {
            return self::keyword($m[1], self::SCOPE_BOTH);
        }

        return null;
    }

    /**
     * @return array{type: string, value: string, scope: string}
     */
    private static function keyword(string $value, string $scope): array {
        return ['type' => 'keyword', 'value' => $value, 'scope' => $scope];
    }
}
