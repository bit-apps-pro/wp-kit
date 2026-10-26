<?php

namespace BitApps\WPKit\Http\Router;

/**
 * Builds WordPress rewrite rules and query vars for a static page's route paths.
 */
final class RewriteRuleSet
{
    private string $_pageName;

    private array $_rules = [];

    private array $_queryVars = [];

    public function __construct(string $pageName)
    {
        $this->_pageName = trim($pageName, '/');
    }

    /**
     * Adds the rule for one route path; only an empty path maps the page root itself.
     */
    public function addPath(string $path): void
    {
        $path = trim($path, '/');
        if ($path === '') {
            $this->_rules["^{$this->_pageName}/?$"] = "index.php?pagename={$this->_pageName}";

            return;
        }

        $regex      = '^' . preg_quote($this->_pageName, '~') . '/';
        $query      = 'index.php?pagename=' . $this->_pageName;
        $cursor     = 0;
        $matchIndex = 1;

        foreach (RoutePattern::placeholders($path) as $placeholder) {
            $literal = substr($path, $cursor, $placeholder['offset'] - $cursor);
            $cursor  = $placeholder['offset'] + \strlen($placeholder['token']);

            if (!$placeholder['required'] && str_ends_with($literal, '/')) {
                $regex .= preg_quote(substr($literal, 0, -1), '~') . '(?:/([^/]+))?';
            } else {
                $regex .= preg_quote($literal, '~') . '([^/]+)' . ($placeholder['required'] ? '' : '?');
            }

            $query .= '&' . $placeholder['name'] . '=$matches[' . $matchIndex . ']';
            $this->_queryVars[] = $placeholder['name'];
            ++$matchIndex;
        }

        $regex .= preg_quote(substr($path, $cursor), '~') . '/?$';
        $this->_rules[$regex] = $query;
    }

    public function rules(): array
    {
        return $this->_rules;
    }

    public function queryVars(): array
    {
        return array_values(array_unique($this->_queryVars));
    }
}
