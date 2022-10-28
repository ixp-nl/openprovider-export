<?php

require './vendor/autoload.php';

use Garden\Cli\Cli;
use GuzzleHttp\Client as HttpClient;
use Openprovider\Api\Rest\Client\Auth\Model\AuthLoginRequest;
use Openprovider\Api\Rest\Client\Base\ApiException;
use Openprovider\Api\Rest\Client\Base\Configuration;
use Openprovider\Api\Rest\Client\Client;

$cli = new Cli();

// Load config
$init = ($argv[1] ?? null) === '--init';
if (!file_exists('./config.php')) {
    if ($init) {
        copy('./config.example.php', './config.php');
    }
    echo $cli->blue('please configure your api credentials in config.php') . PHP_EOL;
    exit($init ? 0 : 1);
}
if ($init) {
    exit;
}
$config = require('./config.php');
if (empty($config['api_username'])) {
    echo $cli->red('Config error: api credentials missing') . PHP_EOL;
    exit(1);
}

// Parse and return cli args
try {
    $cli->description('Export Openprovider DNS zone')
        ->opt('save:s', 'save to file', false, 'boolean')
        ->opt('sectigo', 'optional \'sectigo\'', false, 'boolean')
        ->opt('with-ns', 'include ns records', false, 'boolean')
        ->opt('with-soa', 'include soa record', false, 'boolean')
        ->arg('domain', 'domain to export', true);
    $args = $cli->parse($argv, false);
    $domains = $args->getArgs();
    $provider = $args->getOpt('sectigo') ? 'sectigo' : null;
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

// Get domain info
try {
    $zoneService = $client->getDnsModule()->getZoneServiceApi();
    foreach ($domains as $domain) {
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
            echo $cli->green('domain exported: ' . $domain) . PHP_EOL;
        }
    }
} catch (ApiException $e) {
    echo $cli->red('API error: ' . $e->getMessage()) . PHP_EOL;
    exit(1);
}
