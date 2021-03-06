<?php declare(strict_types=1);

namespace Room11\Jeeves\Chat\Plugin;

use Room11\Jeeves\Chat\Client\ChatClient;
use Room11\Jeeves\Chat\Command\Command;
use SebastianBergmann\Version as SebastianVersion;

class Version implements Plugin
{
    use CommandOnlyPlugin;

    private $chatClient;

    public function __construct(ChatClient $chatClient)
    {
        $this->chatClient = $chatClient;
    }

    private function getVersion(): \Generator
    {
        $version = (new SebastianVersion(VERSION, dirname(dirname(dirname(__DIR__)))))->getVersion();

        $version = preg_replace_callback("@(v([0-9.]+)-(\d+))-g([0-9a-f]+)@", function($match) {
            return sprintf(
                "[%s-g%s](%s)",
                $match[1],
                $match[4],
                "https://github.com/Room-11/Jeeves/commit/" . $match[4]
            );
        }, $version);

        $version = preg_replace_callback("@(v[0-9.]+)@", function($match) {
            return sprintf(
                "[%s](%s)",
                $match[1],
                "https://github.com/Room-11/Jeeves/tree/" . $match[1]
            );
        }, $version);

        yield from $this->chatClient->postMessage($version);
    }

    /**
     * Handle a command message
     *
     * @param Command $command
     * @return \Generator
     */
    public function handleCommand(Command $command): \Generator
    {
        yield from $this->getVersion();
    }

    /**
     * Get a list of specific commands handled by this plugin
     *
     * @return string[]
     */
    public function getHandledCommands(): array
    {
        return ['version'];
    }
}
