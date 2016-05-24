<?php

namespace Room11\Jeeves;

use Amp\Artax\Client as ArtaxClient;
use Amp\Artax\Cookie\ArrayCookieJar;
use Amp\Artax\Cookie\CookieJar;
use Amp\Artax\HttpClient;
use Auryn\Injector;
use Room11\Jeeves\Bitly\Client as BitlyClient;
use Room11\Jeeves\Chat\BuiltInCommandManager;
use Room11\Jeeves\Chat\Client\ChatClient;
use Room11\Jeeves\Chat\Event\Filter\Builder as EventFilterBuilder;
use Room11\Jeeves\Chat\PluginManager;
use Room11\Jeeves\Chat\Room\Authenticator as ChatRoomConnector;
use Room11\Jeeves\Chat\Room\Collection as ChatRoomCollection;
use Room11\Jeeves\Chat\Room\CredentialManager;
use Room11\Jeeves\Log\Logger;
use Room11\Jeeves\Storage\Admin as AdminStorage;
use Room11\Jeeves\Storage\Ban as BanStorage;
use Room11\Jeeves\Storage\File\Admin as FileAdminStorage;
use Room11\Jeeves\Storage\File\Ban as FileBanStorage;
use Room11\Jeeves\Storage\File\JsonFileAccessor;
use Room11\Jeeves\Storage\File\Plugin as FilePluginStorage;
use Room11\Jeeves\Storage\Plugin as PluginStorage;
use Room11\Jeeves\WebSocket\Collection as WebSocketCollection;
use Room11\OpenId\Authenticator as OpenIdAuthenticator;
use Room11\OpenId\StackExchangeAuthenticator;
use Room11\OpenId\UriFactory;

const DATA_BASE_DIR = __DIR__ . '/../data';

/** @var Injector $injector */
$injector->alias(HttpClient::class, ArtaxClient::class);
$injector->alias(OpenIdAuthenticator::class, StackExchangeAuthenticator::class);
$injector->alias(CookieJar::class, ArrayCookieJar::class);

$injector->define(FileAdminStorage::class, [":dataFile" => DATA_BASE_DIR . "/admins.%s.json"]);
$injector->define(FileBanStorage::class, [":dataFile" => DATA_BASE_DIR . "/bans.%s.json"]);
$injector->define(FilePluginStorage::class, [":dataFile" => DATA_BASE_DIR . "/plugins.%s.json"]);

$injector->share(AdminStorage::class);
$injector->share(BanStorage::class);
$injector->share(BitlyClient::class);
$injector->share(BuiltInCommandManager::class);
$injector->share(ChatClient::class);
$injector->share(ChatRoomCollection::class);
$injector->share(ChatRoomConnector::class);
$injector->share(CredentialManager::class);
$injector->share(CookieJar::class);
$injector->share(EventFilterBuilder::class);
$injector->share(HttpClient::class);
$injector->share(JsonFileAccessor::class);
$injector->share(Logger::class);
$injector->share(OpenIdAuthenticator::class);
$injector->share(PluginManager::class);
$injector->share(PluginStorage::class);
$injector->share(UriFactory::class);
$injector->share(WebSocketCollection::class);
