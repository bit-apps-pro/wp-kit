<?php

namespace BitApps\WPKit\Tests\Router;

use BitApps\WPKit\Http\Router\RewriteRuleSet;
use BitApps\WPKit\Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class RewriteRuleSetTest extends TestCase
{
    public function testLiteralRouteRegistersItsFullRewrite(): void
    {
        $set = new RewriteRuleSet('landing');
        $set->addPath('about');

        assertSameValue(
            'index.php?pagename=landing',
            $set->rules()['^landing/about/?$'] ?? null,
            'literal route rewrite was not generated',
        );
    }

    public function testOptionalParameterKeepsItsLiteralPrefix(): void
    {
        $set = new RewriteRuleSet('landing');
        $set->addPath('entries/{slug?}');

        assertSameValue(
            'index.php?pagename=landing&slug=$matches[1]',
            $set->rules()['^landing/entries(?:/([^/]+))?/?$'] ?? null,
            'optional route rewrite was malformed',
        );
    }

    public function testAddPathBuildsOneCompleteRuleAndQueryVars(): void
    {
        $set = new RewriteRuleSet('landing');
        $set->addPath('entries/{id}');

        assertSameValue(
            [
                '^landing/entries/([^/]+)/?$' => 'index.php?pagename=landing&id=$matches[1]',
            ],
            $set->rules(),
            'complete rewrite rule changed',
        );
        assertSameValue(['id'], $set->queryVars(), 'query vars changed');
    }

    public function testRulesAccumulateAcrossPaths(): void
    {
        $set = new RewriteRuleSet('landing');
        $set->addPath('alpha/{a}');
        $set->addPath('beta/{b}');

        assertTest(isset($set->rules()['^landing/alpha/([^/]+)/?$']), 'first path lost its rule');
        assertTest(isset($set->rules()['^landing/beta/([^/]+)/?$']), 'second path lost its rule');
        assertSameValue(['a', 'b'], $set->queryVars(), 'query vars did not accumulate');
    }

    public function testEmptyPathRegistersOnlyThePageRootRule(): void
    {
        $set = new RewriteRuleSet('landing');
        $set->addPath('/');

        assertSameValue(['^landing/?$' => 'index.php?pagename=landing'], $set->rules(), 'empty path did not map to the page root');
    }

    public function testEmptySetHasNoRulesOrQueryVars(): void
    {
        $set = new RewriteRuleSet('landing');

        assertSameValue([], $set->rules(), 'empty set produced rules');
        assertSameValue([], $set->queryVars(), 'empty set produced query vars');
    }
}
