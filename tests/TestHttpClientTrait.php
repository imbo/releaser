<?php declare(strict_types=1);

namespace ImboReleaser;

use ArrayObject;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

use const JSON_THROW_ON_ERROR;

trait TestHttpClientTrait
{
    /**
     * @return array{0:Client,1:list<array{request:Request,response:Response}>}
     */
    private function getGuzzleClient(Response ...$responses): array
    {
        /** @var list<Response> $responses */
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $history = new ArrayObject();
        $handlerStack->push(Middleware::history($history));

        /** @var list<array{request:Request,response:Response}> $history */
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
