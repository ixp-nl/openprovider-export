<?php

require './vendor/autoload.php';

use Garden\Cli\Cli;
use GuzzleHttp\Client as HttpClient;
use Openprovider\Api\Rest\Client\Auth\Model\AuthLoginRequest;
use Openprovider\Api\Rest\Client\Base\Configuration;
use Openprovider\Api\Rest\Client\Client;

$cli = new Cli();

if (!file_exists('./config.php')) {
    file_put_contents('./config.php', "<?php\n\nreturn [\n    'api_username' => '',\n    'api_password' => '',\n];\n");
}
$config = require('./config.php');
if (empty($config['api_username'])) {
    echo $cli->red('configure your api credentials in config.php') . PHP_EOL;
    exit(1);
}

// Parse and return cli args.
try {
    $cli->description('Export Openprovider DNS zone')
        ->opt('sectigo', 'optional \'sectigo\'', false, 'boolean')
        ->opt('with-ns', 'include ns records', false, 'boolean')
        ->opt('with-soa', 'include soa record', false, 'boolean')
        ->arg('domain', 'domain to export', true);
    $args = $cli->parse($argv, false);
} catch (Exception $e) {
    $cli->writeHelp();
    exit(1);
}

// Connect to OpenProvider and retrieve token for further using
try {
    $client = new Client(new HttpClient(), $configuration = new Configuration());
    $loginResult = $client->getAuthModule()->getAuthApi()->login(
        new AuthLoginRequest([
            'username' => $config['api_username'],
            'password' => $config['api_password'],
        ])
    );
    $configuration->setAccessToken($loginResult->getData()->getToken());
} catch (\Openprovider\Api\Rest\Client\Base\ApiException $e) {
    echo $cli->red('API error: ' . $e->getMessage()) . PHP_EOL;
    exit(1);
}

// Get domain info
try {
    $domain = $args->getArg('domain');
    $provider = $args->getOpt('sectigo') ? 'sectigo' : null;
    $withSoa = (bool)$args->getOpt('with-soa');
    $withNs = (bool)$args->getOpt('with-ns');
    $zoneService = $client->getDnsModule()->getZoneServiceApi();
    foreach ($zoneService->getZone($domain, null, true, false, null, $provider)->getData()->getRecords() as $record) {
        $name = $record->getName();
        $ttl = $record->getTtl();
        $type = $record->getType();
        $value = $record->getValue();
        $prio = $record->getPrio();
        if (($type === 'SOA') && !$withSoa) {
            continue;
        }
        if (($type === 'NS') && !$withNs) {
            continue;
        }
        if ($name === $domain) {
            $name = '@';
        } elseif (substr($name, -(strlen($domain)+1)) === '.' . $domain) {
            $name = substr($name, 0, -(strlen($domain)+1));
        }
        printf("%-20s\t%d\tIN\t%s\t%s\n",
            $name,
            $ttl,
            $type,
            (!empty($prio) ? $prio . ' ' : '') . $value
        );
    }
} catch (\Openprovider\Api\Rest\Client\Base\ApiException $e) {
    echo $cli->red('API error: ' . $e->getMessage()) . PHP_EOL;
    exit(1);
}
