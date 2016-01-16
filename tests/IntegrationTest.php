<?php
namespace GuzzleHttp5\Tests;

use GuzzleHttp5\Client;
use GuzzleHttp5\Event\AbstractTransferEvent;
use GuzzleHttp5\Event\CompleteEvent;
use GuzzleHttp5\Event\EndEvent;
use GuzzleHttp5\Event\ErrorEvent;
use GuzzleHttp5\Exception\RequestException;
use GuzzleHttp5\Message\Response;
use GuzzleHttp5\Pool;

class IntegrationTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @issue https://github.com/guzzle/guzzle/issues/867
     */
    public function testDoesNotFailInEventSystemForNetworkError()
    {
        $c = new Client();
        $r = $c->createRequest(
            'GET',
            Server::$url,
            [
                'timeout'         => 1,
                'connect_timeout' => 1,
                'proxy'           => 'http://127.0.0.1:123/foo'
            ]
        );

        $events = [];
        $fn = function(AbstractTransferEvent $event) use (&$events) {
            $events[] = [
                get_class($event),
                $event->hasResponse(),
                $event->getResponse()
            ];
        };

        $pool = new Pool($c, [$r], [
            'error'    => $fn,
            'end'      => $fn
        ]);

        $pool->wait();

        $this->assertCount(2, $events);
        $this->assertEquals('GuzzleHttp5\Event\ErrorEvent', $events[0][0]);
        $this->assertFalse($events[0][1]);
        $this->assertNull($events[0][2]);

        $this->assertEquals('GuzzleHttp5\Event\EndEvent', $events[1][0]);
        $this->assertFalse($events[1][1]);
        $this->assertNull($events[1][2]);
    }

    /**
     * @issue https://github.com/guzzle/guzzle/issues/866
     */
    public function testProperyGetsTransferStats()
    {
        $transfer = [];
        Server::enqueue([new Response(200)]);
        $c = new Client();
        $response = $c->get(Server::$url . '/foo', [
            'events' => [
                'end' => function (EndEvent $e) use (&$transfer) {
                    $transfer = $e->getTransferInfo();
                }
            ]
        ]);
        $this->assertEquals(Server::$url . '/foo', $response->getEffectiveUrl());
        $this->assertNotEmpty($transfer);
        $this->assertArrayHasKey('url', $transfer);
    }

    public function testNestedFutureResponsesAreResolvedWhenSending()
    {
        $c = new Client();
        $total = 3;
        Server::enqueue([
            new Response(200),
            new Response(201),
            new Response(202)
        ]);
        $c->getEmitter()->on(
            'complete',
            function (CompleteEvent $e) use (&$total) {
                if (--$total) {
                    $e->retry();
                }
            }
        );
        $response = $c->get(Server::$url);
        $this->assertEquals(202, $response->getStatusCode());
        $this->assertEquals('GuzzleHttp5\Message\Response', get_class($response));
    }

    public function testNestedFutureErrorsAreResolvedWhenSending()
    {
        $c = new Client();
        $total = 3;
        Server::enqueue([
            new Response(500),
            new Response(501),
            new Response(502)
        ]);
        $c->getEmitter()->on(
            'error',
            function (ErrorEvent $e) use (&$total) {
                if (--$total) {
                    $e->retry();
                }
            }
        );
        try {
            $c->get(Server::$url);
            $this->fail('Did not throw!');
        } catch (RequestException $e) {
            $this->assertEquals(502, $e->getResponse()->getStatusCode());
        }
    }
}
