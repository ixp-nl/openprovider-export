# openprovider-export
PHP command line tool for exporting Openprovider DNS zones to bind files

## Setup

```shell
git clone https://github.com/ixp-nl/openprovider-export.git
```

```shell
composer update
```

Check if you have enabled API access on https://cp.openprovider.eu/account/dashboard.php   
Provide the API username and password in config.php

## Usage

Export domain example.com
```shell
php export.php example.com
```

