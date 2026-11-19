<?php

namespace Alvora\ErpnextLink\Tests\Support;

use Fleetbase\Webhook\Signer\EndpointSignature;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A stand-in for a client's ERPNext with the AlvoraVector connector installed. It plays the connector's part
 * (verify, connect, webhook with the signature check) so the extension is tested end to end without a network.
 */
class FakeErpnext
{
    /** @var array<int, array{path: string, headers: array, body: string}> */
    public array $requests = [];

    /** The pair the "connector" holds right now. */
    public ?array $pair = null;

    /** path suffix => status or ['status' => int, 'body' => string] or \Throwable, to make a step fail. */
    public array $failures = [];

    public function client(): Client
    {
        return new Client(['handler' => HandlerStack::create(function (RequestInterface $request, array $options) {
            return Create::promiseFor($this->reply($request));
        })]);
    }

    public function count(string $suffix): int
    {
        return count(array_filter($this->requests, fn ($r) => str_ends_with($r['path'], $suffix)));
    }

    /** The last body received by the connect method, decoded. */
    public function lastConnectBody(): ?array
    {
        foreach (array_reverse($this->requests) as $request) {
            if (str_ends_with($request['path'], '.connect')) {
                return json_decode($request['body'], true);
            }
        }

        return null;
    }

    private function reply(RequestInterface $request): Response
    {
        $path             = $request->getUri()->getPath();
        $body             = (string) $request->getBody();
        $this->requests[] = ['path' => $path, 'headers' => $request->getHeaders(), 'body' => $body];

        foreach ($this->failures as $suffix => $failure) {
            if (str_ends_with($path, $suffix)) {
                if ($failure instanceof \Throwable) {
                    throw $failure;
                }

                return is_array($failure) ? new Response($failure['status'], [], $failure['body'] ?? '') : new Response($failure);
            }
        }

        if (str_ends_with($path, '.verify')) {
            return $this->ok(['ok' => true]);
        }
        if (str_ends_with($path, '.connect')) {
            $data       = json_decode($body, true);
            $this->pair = ['webhook_secret' => $data['webhook_secret'], 'api_key' => $data['api_key'], 'connection_id' => $data['connection_id']];

            return $this->ok(['ok' => true, 'connection_id' => $data['connection_id']]);
        }
        if (str_ends_with($path, '.webhook')) {
            $signature = $request->getHeaderLine(EndpointSignature::HEADER);
            if (!$this->pair || !EndpointSignature::verify($body, $signature, $this->pair['webhook_secret'])) {
                return new Response(401, [], '{"exc_type":"AuthenticationError"}');
            }

            return $this->ok(['ok' => true, 'connection_id' => $this->pair['connection_id']]);
        }

        return new Response(404);
    }

    private function ok(array $message): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['message' => $message]));
    }
}
