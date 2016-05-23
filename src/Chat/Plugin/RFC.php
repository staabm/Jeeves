<?php  declare(strict_types=1);
namespace Room11\Jeeves\Chat\Plugin;

use Amp\Artax\HttpClient;
use Amp\Artax\Response as HttpResponse;
use Room11\Jeeves\Chat\Client\ChatClient;
use Room11\Jeeves\Chat\Client\PostedMessage;
use Room11\Jeeves\Chat\Message\Command;
use Room11\Jeeves\Chat\Plugin;
use Room11\Jeeves\Chat\Plugin\Traits\CommandOnly;
use Room11\Jeeves\Chat\PluginCommandEndpoint;
use Room11\Jeeves\Storage\KeyValue as KeyValueStore;
use function Room11\DOMUtils\domdocument_load_html;

class RFC implements Plugin
{
    use CommandOnly;

    private $chatClient;
    private $httpClient;
    private $pluginData;

    const BASE_URI = 'https://wiki.php.net/rfc';

    public function __construct(ChatClient $chatClient, HttpClient $httpClient, KeyValueStore $pluginData)
    {
        $this->chatClient = $chatClient;
        $this->httpClient = $httpClient;
        $this->pluginData = $pluginData;
    }

    public function search(Command $command): \Generator {
        /** @var HttpResponse $response */
        $response = yield $this->httpClient->request(self::BASE_URI);

        if ($response->getStatus() !== 200) {
            return $this->chatClient->postMessage($command->getRoom(), "Nope, we can't have nice things.");
        }

        $dom = domdocument_load_html($response->getBody());

        $list = $dom->getElementById("in_voting_phase")->nextSibling->nextSibling->getElementsByTagName("ul")->item(0);
        $rfcsInVoting = [];

        foreach ($list->childNodes as $node) {
            if ($node instanceof \DOMText) {
                continue;
            }

            /** @var \DOMElement $node */
            /** @var \DOMElement $href */
            $href = $node->getElementsByTagName("a")->item(0);

            $rfcsInVoting[] = sprintf(
                "[%s](%s)",
                $href->textContent,
                \Sabre\Uri\resolve(self::BASE_URI, $href->getAttribute("href"))
            );
        }

        if (empty($rfcsInVoting)) {
            return $this->chatClient->postMessage($command->getRoom(), "Sorry, but we can't have nice things.");
        }

        /** @var PostedMessage $postedMessage */
        $postedMessage = yield $this->chatClient->postMessage(
            $command->getRoom(),
            sprintf(
                "[tag:rfc-vote] %s",
                implode(" | ", $rfcsInVoting)
            )
        );

        $pinnedMessages = yield $this->chatClient->getPinnedMessages($command->getRoom());
        $lastPinId = (yield $this->pluginData->exists('lastpinid', $command->getRoom()))
            ? yield $this->pluginData->get('lastpinid', $command->getRoom())
            : -1;

        if (in_array($lastPinId, $pinnedMessages)) {
            yield $this->chatClient->unstarMessage($lastPinId, $command->getRoom());
        }

        yield $this->pluginData->set('lastpinid', $postedMessage->getMessageId(), $command->getRoom());
        return $this->chatClient->pinOrUnpinMessage($postedMessage->getMessageId(), $command->getRoom());
    }

    public function getRFC(Command $command): \Generator {
        $rfc = $command->getParameter(0);
        if ($rfc === null) {
            // e.g.: !!rfc pipe-operator
            return $this->chatClient->postMessage($command->getRoom(), "RFC id required");
        }

        /*r @var HttpResponse $response */
        $uri = self::BASE_URI . '/' . urlencode($rfc);
        $response = yield $this->httpClient->request($uri);
        if ($response->getStatus() !== 200) {
            return $this->chatClient->postMessage($command->getRoom(), "Nope, we can't have nice things.");
        }

        $votes = self::parseVotes($response->getBody());
        if (empty($votes)) {
            return $this->chatClient->postMessage($command->getRoom(), "No votes found");
        }

        $messages = array();
        foreach ($votes as $id => $vote) {
            $breakdown = array();
            $total = array_sum($vote['votes']);
            if ($total > 0) {
                foreach ($vote['votes'] as $option => $value) {
                    $breakdown[] = sprintf("%s (%d: %d%%)", $option, $value, 100 * $value / $total);
                }
            }
            $messages[] = sprintf(
                "[tag:rfc-vote] [%s](%s) %s",
                $vote['name'],
                $uri . '#' . $id,
                implode(', ', $breakdown)
            );
        }

        /** @var PostedMessage $postedMessage */
        $postedMessage = yield $this->chatClient->postMessage(
            $command->getRoom(),
            implode("\n", $messages)
        );

        $pinnedMessages = yield $this->chatClient->getPinnedMessages($command->getRoom());
        $lastPinId = (yield $this->pluginData->exists('lastpinid', $command->getRoom()))
            ? yield $this->pluginData->get('lastpinid', $command->getRoom())
            : -1;

        if (in_array($lastPinId, $pinnedMessages)) {
            yield $this->chatClient->unstarMessage($lastPinId, $command->getRoom());
        }

        yield $this->pluginData->set('lastpinid', $postedMessage->getMessageId(), $command->getRoom());
        return $this->chatClient->pinOrUnpinMessage($postedMessage->getMessageId(), $command->getRoom());
    }

    private static function parseVotes(string $html) {
        $dom = domdocument_load_html($html);
        $votes = array();
        foreach ($dom->getElementsByTagName('form') as $form) {
            if ($form->getAttribute('name') != 'doodle__form') continue;
            $id = $form->getAttribute('id');
            $info = [
                'name' => $id,
                'votes' => [],
            ];
            $options = array();

            $table = $form->getElementsByTagName('table')->item(0);
            foreach ($table->getElementsByTagName('tr') as $row) {
                $class = $row->getAttribute('class');
                if ($class == 'row0') { // Title
                    $title = trim($row->getElementsByTagName('th')->item(0)->textContent);
                    if (!empty($title)) {
                        $info['name'] = $title;
                    }
                    continue;
                }
                if ($class == 'row1') { // Options
                    $opts = $row->getElementsByTagName('td');
                    for ($i = 0; $i < $opts->length; ++$i) {
                        $options[$i] = strval($opts->item($i)->textContent);
                        $info['votes'][$options[$i]] = 0;
                    }
                    continue;
                }

                // Vote
                $vote = $row->getElementsByTagName('td');
                for ($i = 1; $i < $vote->length; ++$i) {
                    // Adjust by one to ignore voter name
                    if ($vote->item($i)->getElementsByTagName('img')->length > 0) {
                        ++$info['votes'][$options[$i - 1]];
                    }
                }
            }
            $votes[$id] = $info;
        }

        return $votes;
    }

    public function getName(): string
    {
        return 'RFC.PHP';
    }

    public function getDescription(): string
    {
        return 'Displays the PHP RFCs which are currently in the voting phase';
    }

    public function getHelpText(array $args): string
    {
        // TODO: Implement getHelpText() method.
    }

    /**
     * @return PluginCommandEndpoint[]
     */
    public function getCommandEndpoints(): array
    {
        return [
          new PluginCommandEndpoint('Search', [$this, 'search'], 'rfcs'),
          new PluginCommandEndpoint('RFC', [$this, 'getRFC'], 'rfc'),
        ];
    }
}
