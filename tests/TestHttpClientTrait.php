<?php declare(strict_types=1);

namespace ImboReleaser;

use ArrayObject;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

use const JSON_THROW_ON_ERROR;

trait TestHttpClientTrait
{
    /**
     * @return array{0:Client,1:array<array{request:RequestInterface,response:?ResponseInterface,error:mixed,options:array<mixed>}>}
     */
    private function getGuzzleClient(Response|Throwable ...$responses): array
    {
        /** @var list<Response|Throwable> $responses */
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $history = new ArrayObject();
        $handlerStack->push(Middleware::history($history));

        /** @var array<array{request:RequestInterface,response:?ResponseInterface,error:mixed,options:array<mixed>}> $history */
        return [new Client(['handler' => $handlerStack]), $history];
    }

    /**
     * @param array<mixed> $data
     */
    private function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }
}
