<?php declare(strict_types = 1);

namespace Room11\Jeeves\Chat\Client;

use Amp\Artax\Client;
use Amp\Artax\FormBody;
use Amp\Artax\Request;
use Amp\Artax\Response as ArtaxResponse;
use Amp\Pause;
use Room11\Jeeves\Chat\Room\Room;
use Room11\Jeeves\Fkey\FKey;
use Room11\Jeeves\Chat\Message\Message as ChatMessage;

class ChatClient {
    private $httpClient;
    private $fkey;
    private $room;

    private $postMutex;

    public function __construct(Client $httpClient, FKey $fkey, Room $room) {
        $this->httpClient = $httpClient;
        $this->fkey = $fkey;
        $this->room = $room;

        $this->postMutex = new Mutex();
    }

    public function request($uriOrRequest): \Generator {
        $response = yield $this->httpClient->request($uriOrRequest);

        return $response;
    }

    public function requestMulti(array $urisAndRequests): \Generator {
        $promises = array_map(function($uriOrRequest) {
            return $this->httpClient->request($uriOrRequest);
        }, $urisAndRequests);

        $responses = [];

        foreach ($promises as $promise) {
            $responses[] = yield $promise;
        }

        return $responses;
    }

    public function getMessage(int $id): \Generator {
        $uri = sprintf(
            "%s://%s/message/%d",
            $this->room->getHost()->isSecure() ? "https" : "http",
            $this->room->getHost()->getHostname(),
            $id
        );

        return yield $this->httpClient->request($uri);
    }

    public function postMessage(string $text): \Generator {
        $body = (new FormBody)
            ->addField("text", $text)
            ->addField("fkey", $this->fkey);

        $uri = sprintf(
            "%s://%s/chats/%d/messages/new",
            $this->room->getHost()->isSecure() ? "https" : "http",
            $this->room->getHost()->getHostname(),
            $this->room->getId()
        );

        $request = (new Request)
            ->setUri($uri)
            ->setMethod("POST")
            ->setBody($body);

        return yield from $this->postMutex->withLock(function() use($body, $uri, $request) {
            // @todo remove me once we found out what message this breaks on
            try {
                $attempts = 0;

                while ($attempts++ < 5) {
                    /** @var ArtaxResponse $response */
                    $response = yield $this->httpClient->request($request);

                    $decoded = json_decode($response->getBody(), true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $decodeErrorStr = json_last_error();

                        if (0 !== $delay = $this->fuckOff($response->getBody())) {
                            throw new \RuntimeException(
                                'A response that could not be decoded as JSON or otherwise handled was received'
                                . ' (JSON decode error: ' . $decodeErrorStr . ')'
                            );
                        }

                        yield new Pause($delay);
                        continue;
                    }

                    if (isset($decoded["id"], $decoded["time"])) {
                        return new Response($decoded["id"], $decoded["time"]);
                    }

                    if (array_key_exists('id', $decoded)) { // sometimes we can get {"id":null,"time":null} ??
                        yield new Pause($attempts * 1000);
                    }
                }

                throw new \RuntimeException(
                    'Sending the message failed after 5 attempts and I know when to quit'
                );
            } catch (\Throwable $e) {
                $errorInfo = isset($response) ? $response->getBody() : 'No response data';

                file_put_contents(
                    __DIR__ . '/../../../data/exceptions.txt',
                    (new \DateTimeImmutable())->format('Y-m-d H:i:s') . ' ' . $e->getMessage() . "\r\n" . $errorInfo . "\r\n\r\n",
                    FILE_APPEND
                );

                yield new Pause(2000);

                yield from $this->postMessage("@PeeHaa error has been logged. Fix it fix it fix it fix it.");
            }

            return null;
        });
    }

    public function postReply(ChatMessage $origin, string $text): \Generator
    {
        yield from $this->postMessage(":{$origin->getId()} {$text}");
    }

    public function editMessage(int $id, string $text): \Generator {
        $body = (new FormBody)
            ->addField("text", $text)
            ->addField("fkey", $this->fkey);

        $uri = sprintf(
            "%s://%s/messages/%s",
            $this->room->getHost()->isSecure() ? "https" : "http",
            $this->room->getHost()->getHostname(),
            $id
        );

        $request = (new Request)
            ->setUri($uri)
            ->setMethod("POST")
            ->setBody($body);

        yield from $this->postMutex->withLock(function() use($body, $uri, $request) {
            $attempts = 0;

            while ($attempts++ < 5) {
                /** @var ArtaxResponse $response */
                $response = yield $this->httpClient->request($request);

                if (0 === $delay = $this->fuckOff($response->getBody())) {
                    break;
                }

                yield new Pause($delay);
            }
        });
    }

    private function fuckOff(string $body): int
    {
        if (!preg_match('/You can perform this action again in (\d+) seconds/', $body, $matches)) {
            return 0;
        }

        return (int)(($matches[1] + 2) * 1000);
    }
}
