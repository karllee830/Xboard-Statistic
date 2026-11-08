<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Tests\Unit;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Plugin\XboardStatistic\Middleware\DecodeStatisticsPayload;
use Plugin\XboardStatistic\Middleware\PrepareStatisticsAuth;
use Symfony\Component\HttpFoundation\Response;

final class ReportMiddlewareTest extends TestCase
{
    public function testAuthHeadersBecomeRequestInputWithoutParsingTheBody(): void
    {
        $request = $this->request(gzencode('{"not":"parsed"}'), [
            'HTTP_X_XBOARD_TOKEN' => 'secret',
            'HTTP_X_XBOARD_NODE_ID' => '7',
            'HTTP_X_XBOARD_MACHINE_ID' => '3',
            'HTTP_CONTENT_ENCODING' => 'gzip',
        ]);

        $seen = null;
        (new PrepareStatisticsAuth())->handle($request, function (Request $request) use (&$seen): Response {
            $seen = $request->all();
            return new Response('ok');
        });

        self::assertSame(['token' => 'secret', 'node_id' => 7, 'machine_id' => 3], $seen);
    }

    public function testInvalidNodeHeaderIsRejected(): void
    {
        $response = (new PrepareStatisticsAuth())->handle(
            $this->request('{}', ['HTTP_X_XBOARD_NODE_ID' => 'abc']),
            fn (): Response => new Response('unreachable'),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_auth_header', $this->errorCode($response));
    }

    public function testGzipBodyIsDecodedAndVerified(): void
    {
        $body = '{"schema_version":2}';
        $request = $this->request(gzencode($body), [
            'HTTP_CONTENT_ENCODING' => 'gzip',
            'HTTP_X_STATISTICS_CHECKSUM' => hash('sha256', $body),
            'HTTP_X_STATISTICS_PENDING_BATCHES' => '4',
        ]);

        $response = (new DecodeStatisticsPayload())->handle($request, fn (): Response => new Response('ok'));

        self::assertSame('ok', $response->getContent());
        self::assertSame(['schema_version' => 2], $request->attributes->get('statistics_payload'));
        self::assertSame(hash('sha256', $body), $request->attributes->get('statistics_checksum'));
        self::assertSame(4, $request->attributes->get('statistics_pending_batches'));
    }

    public function testOversizedGzipBodyReturns413(): void
    {
        $body = str_repeat('a', DecodeStatisticsPayload::MAX_DECOMPRESSED_BYTES + 1);
        $request = $this->request(gzencode($body, 9), [
            'HTTP_CONTENT_ENCODING' => 'gzip',
            'HTTP_X_STATISTICS_CHECKSUM' => hash('sha256', $body),
        ]);

        $response = (new DecodeStatisticsPayload())->handle($request, fn (): Response => new Response('unreachable'));

        self::assertSame(413, $response->getStatusCode());
        self::assertSame('payload_too_large', $this->errorCode($response));
    }

    public function testCorruptGzipBodyReturns400(): void
    {
        $request = $this->request('not gzip', ['HTTP_CONTENT_ENCODING' => 'gzip']);

        $response = (new DecodeStatisticsPayload())->handle($request, fn (): Response => new Response('unreachable'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_gzip', $this->errorCode($response));
    }

    public function testTruncatedGzipBodyReturns400(): void
    {
        $compressed = gzencode(str_repeat('{"a":1}', 1000));
        $request = $this->request(substr($compressed, 0, intdiv(strlen($compressed), 2)), ['HTTP_CONTENT_ENCODING' => 'gzip']);

        $response = (new DecodeStatisticsPayload())->handle($request, fn (): Response => new Response('unreachable'));

        self::assertSame('invalid_gzip', $this->errorCode($response));
    }

    public function testChecksumMismatchIsRejected(): void
    {
        $request = $this->request('{"a":1}', ['HTTP_X_STATISTICS_CHECKSUM' => str_repeat('0', 64)]);

        $response = (new DecodeStatisticsPayload())->handle($request, fn (): Response => new Response('unreachable'));

        self::assertSame('checksum_mismatch', $this->errorCode($response));
    }

    private function request(string $body, array $server): Request
    {
        return Request::create('/api/v2/server/statistics/report', 'POST', [], [], [], $server + [
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function errorCode(Response $response): ?string
    {
        self::assertInstanceOf(JsonResponse::class, $response);
        return $response->getData(true)['error']['code'] ?? null;
    }
}
