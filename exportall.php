<?php

require './vendor/autoload.php';

use Garden\Cli\Cli;
use GuzzleHttp\Client as HttpClient;
use Openprovider\Api\Rest\Client\Auth\Model\AuthLoginRequest;
use Openprovider\Api\Rest\Client\Base\ApiException;
use Openprovider\Api\Rest\Client\Base\Configuration;
use Openprovider\Api\Rest\Client\Client;

$cli = new Cli();

if (file_exists('./config.php')) {
    $config = require('./config.php');
}
if (!isset($config) || empty($config['api_username'])) {
    echo $cli->red('Config error: api credentials missing') . PHP_EOL;
    exit(1);
}

// Parse and return cli args
try {
    $cli->description('Export ALL Openprovider DNS zone')
        ->opt('save:s', 'save to ./' . $config['export_path'], false, 'boolean')
        ->opt('with-ns', 'include ns records', false, 'boolean')
        ->opt('with-soa', 'include soa record', false, 'boolean');
    $args = $cli->parse($argv, false);
    $withSoa = (bool)$args->getOpt('with-soa');
    $withNs = (bool)$args->getOpt('with-ns');
    $save = (bool)$args->getOpt('save');
    if ($save && !is_dir(__DIR__ . '/' . $config['export_path'])) {
        mkdir(__DIR__ . '/' . $config['export_path']);
    }
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
} catch (ApiException $e) {
    echo $cli->red('API error: ' . $e->getMessage()) . PHP_EOL;
    exit(1);
}

try {
    $domains = [];
    $limit = 10;
    $offset = 15;
    do {
        echo $cli->green('getting domain ' . $offset . ' - ');
        $listDomains = $client->getDomainModule()
            ->getDomainServiceApi()
            ->listDomains(null, 'asc', null, null,
                null, null, null, null, null,
                $limit, $offset
            )
            ->getData();
        echo $cli->green($offset + count($listDomains->getResults() ?? []) . ' from ' . $listDomains->getTotal()) . PHP_EOL;
        foreach ($listDomains->getResults() ?? [] as $domain) {
            switch ($domain->getNsGroup()) {
                case 'dns-openprovider':
                    $provider = 'openprovider';
                    break;
                case 'dns-sectigo':
                    $provider = 'sectigo';
                    break;
                default:
                    $provider = null;
            }
            $domains[] = [
                'domain' => $domain->getDomain()->getName() . '.' . $domain->getDomain()->getExtension(),
                'provider' => $provider
            ];
        }
        $offset += $limit;
        $offset += 1000;
    } while ($listDomains->getTotal() > $offset);
} catch (ApiException $e) {
    echo $cli->red('API error: ' . $e->getMessage()) . PHP_EOL;
    exit(1);
}

// Get domain info
$zoneService = $client->getDnsModule()->getZoneServiceApi();
foreach ($domains as $index => $item) {
    $domain = $item['domain'];
    $provider = $item['provider'];
    if (!$provider) {
        if ($save) {
            echo $cli->blue(sprintf('%3u: %s  (skipped, other dns provider)', $index + 1, $domain)) . PHP_EOL;
        }
        continue;
    }
    try {
        if ($save) {
            $fh = fopen(__DIR__ . '/' . $config['export_path'] . '/' . $domain, 'w');
            fwrite($fh, '$ORIGIN ' . $domain . '. ; base domain-name' . PHP_EOL);
        } else {
            $fh = STDOUT;
            if (count($domains) > 1) {
                echo ($domains['domain'] !== $domain ? PHP_EOL : '') . $cli->green('; ' . $domain) . PHP_EOL;
            }
        }
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
            fwrite($fh, sprintf("%-20s\t%d\tIN\t%s\t%s\n",
                $name,
                $ttl,
                $type,
                (!empty($prio) ? $prio . ' ' : '') . $value
            ));
        }
        if ($save) {
            fclose($fh);
            echo $cli->green(sprintf('%3u: %s', $index+1, $domain)) . PHP_EOL;
        }
    } catch (ApiException $e) {
        if ($save) {
            $err = json_decode($e->getResponseBody(), false);
            echo $cli->red(sprintf('%3u: %s  [%s] %s', $index+1, $domain, $err->code ?? '???', $err->desc ?? '')) . PHP_EOL;
        } else {
            echo $cli->red('API error: ' . $domain) . PHP_EOL;
            echo $cli->purple($e->getMessage()) . PHP_EOL;
        }
    }
}
