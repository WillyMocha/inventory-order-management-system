<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Request;
use App\Support\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Envelope error seragam untuk /api/* (API-01, FR-028, FR-030).
 *
 * Dua hal yang dijaga di sini, dan keduanya adalah alasan endpoint API berbeda
 * dari route HTML:
 *   1. request ke path API dikenali sebagai API — kalau tidak, kegagalannya
 *      dibalas halaman HTML, persis yang dilarang contract;
 *   2. envelope-nya berbentuk {error:{code,message}} dengan pesan generic,
 *      tidak pernah memuat exception message atau stack trace (ERR-01).
 */
final class ApiErrorEnvelopeTest extends TestCase
{
    /** Kode yang didokumentasikan contracts/openapi.yaml. */
    private const array DOCUMENTED_CODES = [
        'unauthorized',
        'forbidden',
        'not_found',
        'invalid_request',
        'rate_limited',
        'server_error',
    ];

    protected function tearDown(): void
    {
        unset($_SERVER['REQUEST_URI']);

        parent::tearDown();
    }

    // ------------------------------------------- Pengenalan path API

    #[Test]
    public function anApiPathIsRecognisedAsExpectingJson(): void
    {
        self::assertTrue($this->requestFor('/api/products/SKU-1/availability')->expectsJson());
        self::assertTrue($this->requestFor('/api/dashboard/low-stock')->expectsJson());
    }

    #[Test]
    public function theBareApiPrefixIsAlsoRecognised(): void
    {
        // '/api/' dinormalisasi menjadi '/api'. Tanpa penanganan ini, keduanya
        // akan dibalas halaman error HTML.
        self::assertTrue($this->requestFor('/api')->expectsJson());
        self::assertTrue($this->requestFor('/api/')->expectsJson());
    }

    #[Test]
    public function anUnknownApiPathStillExpectsJson(): void
    {
        // Route yang tidak dikenal tetap harus dibalas 404 JSON, bukan halaman.
        self::assertTrue($this->requestFor('/api/nothing/here')->expectsJson());
    }

    #[Test]
    public function aPageRouteDoesNotExpectJson(): void
    {
        foreach (['/products', '/sales-orders', '/reports', '/dashboard', '/'] as $path) {
            self::assertFalse($this->requestFor($path)->expectsJson(), $path . ' adalah route HTML.');
        }
    }

    #[Test]
    public function aPathThatMerelyStartsWithTheLettersApiIsNotAnApiPath(): void
    {
        // '/apixyz' bukan berada di bawah prefix API; prefix harus berakhir
        // pada batas segmen, bukan sekadar cocok sebagai awalan string.
        self::assertFalse($this->requestFor('/apixyz')->expectsJson());
        self::assertFalse($this->requestFor('/api-docs')->expectsJson());
    }

    // ---------------------------------------------- Bentuk envelope

    #[Test]
    public function theEnvelopeHasExactlyTheDocumentedShape(): void
    {
        $body = $this->decode(Response::jsonError('not_found', 'Resource not found.', 404));

        self::assertSame(['error'], array_keys($body));
        self::assertSame(['code', 'message'], array_keys($body['error']));
    }

    #[Test]
    public function everyDocumentedCodeProducesTheSameEnvelopeShape(): void
    {
        foreach (self::DOCUMENTED_CODES as $code) {
            $response = Response::jsonError($code, 'Something went wrong.', 500);
            $body = $this->decode($response);

            self::assertSame($code, $body['error']['code']);
            self::assertSame(
                'application/json; charset=UTF-8',
                $response->header('Content-Type'),
                'Envelope untuk ' . $code . ' harus dinyatakan sebagai JSON.',
            );
        }
    }

    #[Test]
    public function theStatusCodeIsCarriedThroughRatherThanAlwaysBeing200(): void
    {
        self::assertSame(401, Response::jsonError('unauthorized', 'x', 401)->statusCode());
        self::assertSame(403, Response::jsonError('forbidden', 'x', 403)->statusCode());
        self::assertSame(404, Response::jsonError('not_found', 'x', 404)->statusCode());
        self::assertSame(500, Response::jsonError('server_error', 'x', 500)->statusCode());
    }

    #[Test]
    public function theEnvelopeNeverCarriesAStackTraceOrExceptionDetail(): void
    {
        $body = Response::jsonError(
            'server_error',
            'Something went wrong. Please try again.',
            500,
        )->body();

        // Pesannya generic dan tidak ada field tambahan tempat detail bisa
        // menyelinap masuk (ERR-01, security standard §11).
        self::assertStringNotContainsString('Exception', $body);
        self::assertStringNotContainsString('#0 ', $body);
        self::assertStringNotContainsString('.php', $body);
        self::assertSame(
            ['error' => ['code' => 'server_error', 'message' => 'Something went wrong. Please try again.']],
            $this->decode(Response::jsonError('server_error', 'Something went wrong. Please try again.', 500)),
        );
    }

    #[Test]
    public function aSuccessResponseIsAlsoDeclaredAsJson(): void
    {
        $response = Response::json(['count' => 0, 'products' => []]);

        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
        self::assertSame(200, $response->statusCode());
    }

    #[Test]
    public function slashesInJsonAreNotEscapedSoUrlsStayReadable(): void
    {
        $response = Response::json(['path' => '/api/products']);

        self::assertStringContainsString('/api/products', $response->body());
    }

    // ------------------------------------------------------ Helpers

    private function requestFor(string $path): Request
    {
        $_SERVER['REQUEST_URI'] = $path;

        return Request::fromGlobals();
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
