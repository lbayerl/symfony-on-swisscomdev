# symfony-on-swisscomdev

This article demonstrates how to deploy a standard [Symfony](http://symfony.com/) application
to the [Swisscom Application Cloud](https://www.swisscom.ch/de/business/enterprise/angebot/cloud-data-center-services/paas/application-cloud.html).

As the Swisscom Developer Platform is based on [Cloud Foundry](https://www.cloudfoundry.org/) it should
 mostly apply to all Cloud Foundry based platforms like IBM Bluemix (trademark) and others as well. But this hasn't been tested.

These steps have been taken on a Kubuntu 16.04 LTS system but most of the stuff should as well easily done on any other platform.

In the end you will have Symfony example application which:
1. can be deployed from your local workstation to the Swisscom Application Cloud (SCAPP) with a one-line command.
2. is connected to a MariaDB service provided by SCAPP
3. is logging its errors to the SCAPP logs
4. uses some caching which will boost your performance as your application grows

You can find the full project in this repository - so if you have a Swisscom Application Cloud (SCAPP) account already, you can check it out and deploy it (only necessary step is first and last then).

Prerequisites:

1. **[composer](https://getcomposer.org/)** is installed globally
2. **[git](https://git-scm.com/)**
3. **[cf cli](http://docs.cloudfoundry.org/cf-cli/)** is installed
4. **[Swisscom Application Public Cloud](http://console.developer.swisscom.com/)** account / user is registered.
5. of course some basic understanding about **PHP** and the **Symfony** framework.

### Keep your Data: Create a Database Service

No real web application is real without a database access.

In this case we will create a new MariaDB service on Swisscom Application Cloud (SCAPP) by executing the following command:

```sh
cf create-service mariadb small ssc-db
```

After that we have created a MariaDB service, with a small plan (means lowest possible pricing at the time of writing) named ssc-db

> You might have to replace ssc-db by the name of your choice due to potential naming conflicts - but be sure to adapt manifest.yml then.

### Get the Party started: Install Symfony Standard Framework

Go to a local folder and use composer to install the Symfony standard framework. ([More information](https://symfony.com/doc/current/setup.html)
about the installation process and options)
```sh
composer create-project symfony/framework-standard-edition symfony-on-swisscomdev "3.2.*"
```

This will install a Symfony 3.2 based standard application into the folder "symfony-on-swisscomdev".

During the installation process you'll be asked to provide some missing parameters.

For now just hit `Enter` for any missing parameter to accept all default values.

At the end of the installation process you have an example Symfony app available locally.

If you like you can startup this app locally with:

```sh
cd symfony-on-swisscomdev
php bin/console server:run
```

Open your browser, point it to `localhost:8000` et voila!

### Enable Deployment: Create a Manifest

In order to deploy your application to the Swisscom Application Cloud you can create a Manifest file. There are other ways but let's keep it simple and together.

Switch to the root dir of your application and create manifest.yml and fill it with the following content. We'll explain it in a minute.

```yml
applications:
- services:
  - ssc-db
  buildpack: https://github.com/cloudfoundry/php-buildpack.git
  host: ssc
  name: SymfonyOnSwisscomDev
  instances: 1
  memory: 256M
  env:
    PHP_INI_SCAN_DIR: .bp-config/php/conf.d
```

During the push Swisscom Application Cloud (SCAPP) will recognize this file and follow its configuration to deploy your app.
This means regarding our configuration:

There will be an application deployed which

1. is connected to one service (our newly created database service named ssc-db)
2. is based on the currently supported [PHP buildpack](https://docs.cloudfoundry.org/buildpacks/php/) of Cloud Foundry
3. will be available under the following URL (host): http://ssc.scapp.io
4. name is SymfonyOnSwisscomDev
5. will spawn one instance of the app (vertical scaling, do you hear me?)
6. each instance will spawn with 128 MB of Ram (horizontal scaling)
7. has one environment variables available: PHP_INI_SCAN_DIR: .bp-config/php/conf.d

### Symfony doesn't know: Adapt config_prod.yml

In this step we will

1. create the reference which will allow us to connect from our application to the database (so far we just bound the service to the application to allow a connection but don't know or use the credentials)
2. make sure that APC (caching) is available for Doctrine (the ORM which is bundled with Symfony) and the general Symfony system cache. This can boost the performance of your application significantly depending on your purpose.
3. adapt the [Monolog](https://seldaek.github.io/monolog/) Logger, which is as well bundled with our Symfony standard to be able to write to the SCAPP logs.

Open `app/config/config_prod.yml` and replace its content with the following content:

```yml
imports:
    - { resource: readEnvParams.php }
    - { resource: parameters_prod.yml }
    - { resource: config.yml }

framework:
    cache:
        system: cache.adapter.apcu

doctrine:
    orm:
        metadata_cache_driver: apcu # if you'd like to use PHP < 7.0 use 'apc' instead of 'apcu'
        result_cache_driver: apcu # if you'd like to use PHP < 7.0 use 'apc' instead of 'apcu'
        query_cache_driver: apcu # if you'd like to use PHP < 7.0 use 'apc' instead of 'apcu'

monolog:
    handlers:
        main_handler:
            type:           fingers_crossed
            action_level:   error
            buffer_size:    200
            handler:        stdout_handler
            channels:       ['!event', '!doctrine']   # channels must be defined here and not in the nested handler
        stdout_handler:         # writes to stdout, as required by Cloud Foundry in order to write to Swisscom log file
            type:   stream
            path:   'php://stdout'
            level:  info
```

The basic monolog configuration allows us that all logging is rollingly cached (buffer_size: 200) as long as no error happens ('fingers crossed'),
but whenever an error happens (action_level: error) the full buffer is handled by the stdout_handler which means nothing else than writing the buffer to php://stdout - and this is automatically part of SCAPP's logging.
Unfortunately we have to do something else later on to ensure that our errors reach this stage.

In your /app/config folder create a new file named `parameters_prod.yml` and fill it with the following content:

```yml
parameters:
    mailer_transport: smtp
    mailer_host: 127.0.0.1
    mailer_user: null
    mailer_password: null
    secret: ThisTokenIsNotSoSecretChangeIt
```

### Further Config and Parameter Mangling: Symfony

In file `app/config/config.yml` locate and delete the line

```yml
- { resource: parameters.yml }
```

In file `app/config/config_dev.yml` add the following line:

```yml
imports:
    - { resource: parameters_dev.yml } # <--- this is the new line
    - { resource: config.yml }
```

Do the same for your file `app/config/config_test.yml`:

```yml
imports:
    - { resource: parameters_test.yml } # <--- this is the new line
    - { resource: config.yml }
```

Now create **two new files** named `app/config/parameters_dev.yml` and `app/config/parameters_test.yml` and fill both with the following content:

```yml
parameters:
    database_host: 127.0.0.1
    database_port: null
    database_name: symfony
    database_user: root
    database_password: null
    mailer_transport: smtp
    mailer_host: 127.0.0.1
    mailer_user: null
    mailer_password: null
    secret: ThisTokenIsNotSoSecretChangeIt
```

In your root folder open the file `composer.json` and delete the following lines:

```json
        "Incenteev\\ParameterHandler\\ScriptHandler::buildParameters",
```

as well as:

```json
        "incenteev-parameters": {
            "file": "app/config/parameters.yml"
        },
```

Finally delete the following files:
`app/config/parameters.yml` and `app/config/parameters.yml.dist`

> **CAUTION:** It's heavily recommended that you provide a valid secret parameter per stage! To do so replace 'ThisTokenIsNotSoSecretChangeIt' in your parameter_*.yml files with a real secret.

> **CAUTION:** After this step you lost the convenience (provided by the [Incenteev ParameterHandler Bundle](https://github.com/Incenteev/ParameterHandler)) that composer will ask you to provide parameters
during the 'composer install' process. You'll have to provide all required parameters per stage in the corresponding `parameter_*.yml` file (parameters_dev.yml, parameters_test.yml, parameters_prod.yml [except database]).

> There are many alternatives to this approach, but we try to keep it simple here.

For our 'production stage' (which is our SCAPP) you might have noticed in our `config_prod.yml` we don't use any database parameters anymore. For this we have introduced the readEnvParams.php which we will create next.

### Use the Database Credentials: Create readEnvParams.php

Go to app/config/ and create a new file named `readEnvParams.php` with the following content:

```php
<?php

$vcapServices = json_decode(getenv('VCAP_SERVICES'));

$container->setParameter('database_driver', 'pdo_mysql');

$db = $vcapServices->{'mariadb'}[0]->credentials;

$container->setParameter('database_host', $db->host);
$container->setParameter('database_port', $db->port);
$container->setParameter('database_name', $db->name);
$container->setParameter('database_user', $db->username);
$container->setParameter('database_password', $db->password);
```

This file reads the SCAPP provided **environment variable VCAP_SERVICES** and parses/sets its values as Symfony container parameter which are used via config_prod.yml.
This allows us to connect to the Database Service.

### Configure PHP: Create an options.json

Create a folder ./bp-config and within that folder create a file `options.json` and fill it with the following content:

```json
{
  "WEB_SERVER": "httpd",
  "ADMIN_EMAIL": "your-email@example.com",
  "COMPOSER_INSTALL_OPTIONS": [
    "--no-dev --optimize-autoloader --no-progress --no-interaction"
  ],
  "WEBDIR": "web",
  "PHP_MODULES": [
    "fpm",
    "cli"
  ],
  "PHP_VERSION": "{PHP_70_LATEST}",
  "PHP_EXTENSIONS": [
    "bz2",
    "zlib",
    "curl",
    "mcrypt",
    "openssl",
    "mbstring",
    "pdo",
    "pdo_mysql",
    "apcu"
  ],
  "ZEND_EXTENSIONS": [
    "opcache"
  ]
}

```

This will configure your PHP buildpack to

1. use Apache as your Web Server
2. run Composer during deployment with a couple of production-optimized arguments
3. install 3rd party dependencies into a folder 'vendor'
4. enable PHP modules for FPM and CLI
5. use the latest PHP 7.0 version which the buildpack is offering
6. use a couple of PHP Extensions (especially to mention here `pdo` and `pdo_mysl` to allow for database connections as well as `apcu` in order to use APCu for caching
7. make sure that `opcache` is available

### Turn on Performance - Change php.ini

Within your folder `.bp-config` create another folder `conf.d` and create a filed named `myphp.ini` there.
Fill it with the following content:

```ini
[APC]
apc.enabled = 1
apc.stat = 1
apc.enable_cli = 1
apc.shm_size = 64M

[opcache]
opcache.enable = 1
opcache.memory_consumption = 64
opcache.interned_strings_buffer = 8
opcache.max_accelerated_files = 20000
opcache.revalidate_freq = 60
opcache.enable_cli = 1
opcache.max_wasted_percentage = 10
realpath_cache_size = 4096K
realpath_cache_ttl = 600
opcache.validate_timestamps = 1

memory_limit = 128M
```

This will enable **APCu** as well as **opcache** both with 64 MB memory (together with the PHP memory limit of 128 MB we have reached the 256 MB memory per SCAPP instance as defined in the manifest.yml).
This can and should be adapted to your further needs (there can't be a general rule for it).

Some opcache settings here are taken from the [Symfony performance recommendations](http://symfony.com/doc/current/performance.html#use-a-byte-code-cache-e-g-opcache)

We have enabled caching for CLI as well which you might not need.
Another optimization is to set **apc.stat = 0** and **opcache.validate_timestamps = false** in
order to block both caches from checking if some scripts might have changed (usually they don't and shouldn't during the lifetime of your deployment).

### Let the Logging beginn - Overwrite php-fpm.conf

Unfortunately - and this is pretty ugly from our point of view - we have to overwrite php-fpm.conf to allow logging to php://stdout which we use as standard handler in our Monolog configuration.

Go to the Github of the [Cloud Foundry Buildpack](https://github.com/cloudfoundry/php-buildpack/tree/master/defaults/config/php) and - depending on your version of the PHP buildpack and your desired PHP Version find the file `php-fpm.conf` and copy its content.

In your folder .bp-config/php create a file named `php-fpm.conf` and paste the content in.

Now locate the following line and make sure the following option is set (it's usually commented):

```ini
catch_workers_output = yes
```

By enabling this option, output of your application to stdout or stderr streams is not dropped (i.e. written to /dev/null) but instead passed on to the surrounding environment. Only in this way, stdout and stderr streams reach the logging system of the Cloud Foundry framework, enabling us to log to the Swisscom log file which you can see in your Swisscom Developer Console (see [doc](https://docs.developer.swisscom.com/devguide/deploy-apps/streaming-logs.html#writing)).

And because you might want to change something in php-fpm anyway - now is your chance.

### Don't push anything unnecessary to Production - Create a .cfignore

Usually not every directory or file which you use in development or which is required in dev or test stage needs to be on production.

Like a .gitignore file you can create a `.cfignore` file in the root folder of your app.
Folders/files mentioned there are not going to be deployed during SCAPP deployment.

Ours looks roughly like that:

```
tests
phpunit.xml.dist
var/cache
var/logs
web/app_dev.php
vendor
app/config/config_dev.yml
app/config/config_test.yml
app/config/parameters_dev.yml
app/config/parameters_test.yml
app/config/routing_dev.yml
```

### Big moment - Deploy and Enjoy

From your local folder call

```sh
cf push
```

and follow the instructions.

After the successful deployment you can now open https://scc.scapp.io in your browser.

Bam!
