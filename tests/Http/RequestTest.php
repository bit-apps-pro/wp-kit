<?php

namespace BitApps\WPKit\Tests\Http;

use BitApps\WPKit\Http\Request\Request;
use BitApps\WPKit\Tests\Fixtures\FakePhpInputStream;
use BitApps\WPKit\Tests\TestCase;
use WP_REST_Request;
use WpKitTestState;

/**
 * Pins the BC contract: a consumer Request subclass may override accessors with
 * UNTYPED signatures. If a parent accessor regains a return type, this class fails
 * to load ("Declaration must be compatible") and the suite errors — the guard the
 * rest of the suite misses because its other Request subclasses only override
 * Validator hooks.
 */
final class ContractOverridingRequest extends Request
{
    public function all()
    {
        return ['overridden' => true];
    }

    public function has($offset)
    {
        return true;
    }

    public function except()
    {
        return [];
    }

    public function files()
    {
        return [];
    }

    public function getRoute()
    {
        return null;
    }
}

/**
 * @internal
 *
 * @coversNothing
 */
final class RequestTest extends TestCase
{
    /** Puts the built-in php:// wrapper back after a test faked php://input. */
    protected function tearDown(): void
    {
        FakePhpInputStream::uninstall();

        parent::tearDown();
    }

    public function testConsumerSubclassMayOverrideAccessorsWithUntypedSignatures(): void
    {
        $request = new ContractOverridingRequest();

        assertSameValue(['overridden' => true], $request->all(), 'untyped all() override was not honored');
        assertTest($request->has('anything'), 'untyped has() override was not honored');
    }

    public function testRequestQueryBodyAndFilesRemainSeparatelyObservable(): void
    {
        $_GET                    = ['query' => 'value', 'shared' => 'query'];
        $_POST                   = ['body' => 'value', 'shared' => 'body'];
        $_FILES                  = ['upload' => ['name' => 'contract.txt']];
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $request                 = new Request();

        assertSameValue(['query' => 'value', 'shared' => 'query'], $request->queryParams(), 'query values changed');
        assertSameValue(['body' => 'value', 'shared' => 'body'], $request->body(), 'body values changed');
        assertSameValue($_FILES, $request->files(), 'file collection changed');
        assertSameValue('query', $request->get('shared'), 'request source precedence changed');
    }

    public function testRequestAccessorsMagicPropertiesAndArrayAccessShareAttributes(): void
    {
        $_GET    = ['name' => 'Ada'];
        $request = new Request();

        assertSameValue('Ada', $request->get('name'), 'get accessor changed');
        assertSameValue('fallback', $request->get('missing', 'fallback'), 'default accessor changed');
        assertSameValue(true, $request->has('name'), 'has accessor changed');
        assertSameValue('Ada', $request->name, 'magic getter changed');
        assertSameValue('Ada', $request['name'], 'array getter changed');

        $request->role     = 'admin';
        $request['active'] = true;
        unset($request->role);

        assertSameValue(false, isset($request->role), 'magic unset changed');
        assertSameValue(true, $request['active'], 'array setter changed');
        assertSameValue(['name' => 'Ada', 'active' => true], $request->jsonSerialize(), 'serialized attributes changed');
    }

    public function testRequestExceptReturnsAFilteredCopy(): void
    {
        $_GET    = ['one' => 1, 'two' => 2, 'three' => 3];
        $request = new Request();

        assertSameValue(['one' => 1, 'three' => 3], $request->except('two'), 'except result changed');
        assertSameValue(2, $request->get('two'), 'except mutated the request');
    }

    public function testRequestMethodAndContentTypeReflectServerMetadata(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $_SERVER['CONTENT_TYPE']   = 'application/json';
        $request                   = new Request();

        assertSameValue('PATCH', $request->method(), 'request method changed');
        assertSameValue('json', $request->contentType(), 'content type changed');
    }

    public function testRequestRESTRequestReplacesGlobalsAndRetainsSourcePrecedence(): void
    {
        $_GET    = ['old' => 'query'];
        $_POST   = ['old_body' => 'body'];
        $request = new Request();
        $request->setApiRequest(
            new WP_REST_Request(
                ['body' => 'value', 'shared' => 'body'],
                ['query' => 'value', 'shared' => 'query'],
                [],
                ['json' => 'value'],
            ),
        );

        assertSameValue(
            [
                'query'  => 'value',
                'shared' => 'query',
                'body'   => 'value',
                'json'   => 'value',
            ],
            $request->all(),
            'REST request hydration or precedence changed',
        );
    }

    public function testRequestSuccessfulValidationReturnsValidatedValues(): void
    {
        $_POST                   = ['name' => 'Ada'];
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $request                 = new Request();

        $validated = $request->validate(['name' => ['required']]);

        assertSameValue(['name' => 'Ada'], $validated, 'successful validation result changed');
        assertSameValue(null, WpKitTestState::$sentJson, 'successful validation emitted an error response');
    }

    public function testRequestHeaderIsReadFromServerByAnyNameStyle(): void
    {
        $_SERVER['HTTP_X_SETUP_TOKEN'] = 'abc';
        $request                       = new Request();

        assertSameValue('abc', $request->header('X-Setup-Token'), 'dashed header name not resolved');
        assertSameValue('abc', $request->header('x_setup_token'), 'canonical header name not resolved');
        assertTest($request->hasHeader('x-setup-token'), 'hasHeader missed a present header');
    }

    public function testRequestContentHeadersAreExposedWithoutTheHttpPrefix(): void
    {
        $_SERVER['CONTENT_TYPE']   = 'application/json';
        $_SERVER['CONTENT_LENGTH'] = '42';
        $request                   = new Request();

        assertSameValue('application/json', $request->header('Content-Type'), 'content type header missing');
        assertSameValue('42', $request->header('Content-Length'), 'content length header missing');
    }

    public function testRequestMissingHeaderFallsBackToTheDefault(): void
    {
        $request = new Request();

        assertSameValue(null, $request->header('X-Missing'), 'missing header was not null');
        assertSameValue('fallback', $request->header('X-Missing', 'fallback'), 'missing header ignored the default');
        assertSameValue(false, $request->hasHeader('X-Missing'), 'hasHeader reported a missing header');
    }

    public function testRequestHeadersAreKeyedByCanonicalName(): void
    {
        $_SERVER['HTTP_ACCEPT']    = 'application/json';
        $_SERVER['HTTP_X_TRACE']   = 't-1';
        $_SERVER['HTTP_X_ARRAY']   = ['not', 'a', 'string'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request                   = new Request();

        assertSameValue(
            ['accept' => 'application/json', 'x_trace' => 't-1'],
            $request->headers(),
            'header map changed',
        );
    }

    public function testRequestHeaderValuesAreSanitizedByDefault(): void
    {
        $_SERVER['HTTP_X_NAME']  = "  <b>Ada</b>\n\tLovelace %41 ";
        $_SERVER['HTTP_X_TRACE'] = 't-1';
        $request                 = new Request();

        assertSameValue('Ada Lovelace', $request->header('X-Name'), 'header() returned an unsanitized value');
        assertSameValue(['x_name' => 'Ada Lovelace', 'x_trace' => 't-1'], $request->headers(), 'headers() returned unsanitized values');
    }

    public function testRequestRawHeaderReturnsTheExactValueForVerification(): void
    {
        $_SERVER['HTTP_X_SIGNATURE'] = "t=1,v1=ab+/= <b>%41";
        $request                     = new Request();

        assertSameValue("t=1,v1=ab+/= <b>%41", $request->rawHeader('X-Signature'), 'raw header value was altered');
        assertSameValue('fallback', $request->rawHeader('X-Missing', 'fallback'), 'raw header ignored the default');
    }

    public function testRequestRESTRequestHeadersTakePrecedenceOverServer(): void
    {
        $_SERVER['HTTP_X_SIGNATURE'] = 'from-server';
        $request                     = new Request();
        $request->setApiRequest(new WP_REST_Request([], [], [], [], ['x_signature' => ['from-rest'], 'accept' => ['a', 'b']]));

        assertSameValue('from-rest', $request->header('X-Signature'), 'REST header did not win');
        assertSameValue('from-rest', $request->rawHeader('X-Signature'), 'REST raw header did not win');
        assertSameValue('a, b', $request->header('Accept'), 'multi-value REST header not joined');
    }

    public function testRequestContentReturnsTheRawBodyByteForByte(): void
    {
        $payload                 = "{\"b\": 2,  \"a\":\"\\u00e9\"}\n";
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        FakePhpInputStream::install($payload);
        $request = new Request();

        assertSameValue($payload, $request->content(), 'raw body was altered');
        assertSameValue(['b' => 2, 'a' => "\u{e9}"], $request->body(), 'decoded body changed');
    }

    public function testRequestContentReturnsNonJsonPayloadsVerbatim(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        FakePhpInputStream::install('a=1&b=%20+x');

        assertSameValue('a=1&b=%20+x', (new Request())->content(), 'form payload was altered');
    }

    public function testRequestContentIsEmptyWithoutABody(): void
    {
        FakePhpInputStream::install('');

        assertSameValue('', (new Request())->content(), 'empty body was not an empty string');
    }

    public function testRequestReadsTheInputStreamOnce(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        FakePhpInputStream::install('{"a":1}');
        $request = new Request();

        $request->content();
        $request->content();

        assertSameValue(1, FakePhpInputStream::$opens, 'php://input was read more than once');
    }

    public function testRequestContentPrefersTheHydratedRESTBody(): void
    {
        FakePhpInputStream::install('from-input');
        $request = new Request();
        $request->setApiRequest(new WP_REST_Request([], [], [], [], [], 'from-rest'));

        assertSameValue('from-rest', $request->content(), 'REST raw body did not win');
    }

    public function testRequestMethodIsSanitizedAndUpperCased(): void
    {
        $_SERVER['REQUEST_METHOD'] = ' <i>patch</i> ';

        assertSameValue('PATCH', (new Request())->method(), 'request method was not sanitized');
    }

    public function testRequestMethodIsEmptyWhenTheServerOmitsIt(): void
    {
        unset($_SERVER['REQUEST_METHOD']);

        assertSameValue('', (new Request())->method(), 'missing request method was not empty');
    }

    public function testRequestContentTypeStripsOnlyTheApplicationPrefix(): void
    {
        $cases = [
            'application/json; charset=utf-8' => 'json; charset=utf-8',
            'application/pdf'                  => 'pdf',
            'Application/XML'                  => 'XML',
            'text/plain'                       => 'text/plain',
            'multipart/form-data; boundary=x'  => 'multipart/form-data; boundary=x',
            ' <b>text/csv</b> '                => 'text/csv',
        ];

        foreach ($cases as $header => $expected) {
            $_SERVER['CONTENT_TYPE'] = $header;

            assertSameValue($expected, (new Request())->contentType(), "content type mangled: {$header}");
        }
    }

    public function testRequestContentTypeIsEmptyWhenTheServerOmitsIt(): void
    {
        unset($_SERVER['CONTENT_TYPE']);

        assertSameValue('', (new Request())->contentType(), 'missing content type was not empty');
    }
}
