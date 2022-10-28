# Openprovider Export
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

Save to domains folder
```shell
php export.php --save example.com
```

Multiple domains
```shell
php export.php --save domain1.com domain2.com domain3.com ...
```

## Export all domains
To export all domains in your account use:
```shell
php exportall.php --save
```