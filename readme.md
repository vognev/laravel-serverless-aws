# laravel-serverless-aws

Package's aim is to provide a possibility to deploy Laravel application as an [AWS Lambda](https://aws.amazon.com/lambda/) function.

## Installation

Package can be installed into existing project using composer:

`composer require vognev/laravel-serverless-aws`

But this is rather a helper to build custom php runtime to be run in AWS environment; for fully operational setup you should also install [serverless framework](https://serverless.com/) globally:

`npm install -g serverless`

It will provide binary named `sls` (`serverless`), which will be used for deployments.

Also, you need `docker` and have an `aws-cli` utility installed and configured:

`aws configure`

## Initialization
Run `php artisan serverless:install` command.

It will publish:
#### config/serverless.php
This is a configuration file, where you can tweak package's behaviour. Apart from being able to define where to store package's data, it's aim is to configure which php modules (since lambda runtime size is limited) should be included in runtime.
#### storage/serverless
On default all runtime-related assets will be published here. Inside of this location you can find `context` and `runtime` folders, holding docker's context to build a runtime, and unpacked php runtime respectively.
#### .php.conf.d
In this folder you can tweak php modules options, or add your own `.ini` configs. 
#### serverless.yml
Project description for serverless framework used for deployment.
#### docker-compose.serverless.yml
Standalone and sample docker-compose stack aimed to mock production environment and suitable for local development.

## Building Runtime
AWS Lambda function consists of several `layers`. We're going to build `runtime` layer (providing php binary). 

Runtime is built using docker and context seen in `storage/serverless/context` directory.
Build process will produce `php` binary with several `.so` modules configured to be invoked from `/opt/` (the place where lambda layer will be unpacked) in portable manner (in order to not depend on missing shared libraries in AWS environment)

See [Custom AWS Lambda Runtimes](https://docs.aws.amazon.com/en_us/lambda/latest/dg/runtimes-custom.html) for more details.

In Dockerfile you can find several stages:
- sources - to fetch and unpack php (and other) sources
- builder - which will build unpacked sources
- bundler - will make portable php distribution under /opt

Once context is published, you can add it to VCS and tweak to fit your needs. One aspect of tweaking is just update the list of php modules (config/serverless.php) to build (available ones can be found in storage/serverless/context/php-modules.sh). Also, nothing can stop you from building additional `pecl` modules, changing PHP version or even rewriting whole Dockerfile. 

When done, run `php artisan serverless:runtime` and find it built under `storage/serverless/runtime`.

Hint: you can speed up build by usind Docker-in-Docker (dind) using `tmpfs` for storage, we do not need any persistence from it:

```
docker run --name dind -d --tmpfs=/var/lib/docker --privileged -p 2375:2375 \
  --entrypoint dockerd docker:dind --host tcp://0.0.0.0:2375
DOCKER_HOST=127.0.0.1:2375 php artisan serverless:runtime
```

## Serverless Configuration

Deployment is configured by tweaking `serverless.yml` options.

#### Service name

Key `service` names your project in AWS.

```yaml
service: workbench
```

#### Provider

`provider.name` is always equal to `aws`, since we're deploying to AWS Lambda.

`provider.region` holds region name to deploy function to.

`provider.runtime` is always equal to `privided`, since we're building own php runtime (php is not available on AWS). 

```yaml
provider:
  name: aws
  region: eu-central-1
  runtime: provided
```

#### Environment Variables

AWS Lambda filesystem is read-only (except `/tmp`), so, for Laravel, we should update various writeable paths to point to location inside of it. On initialization, function's bootstrap script will create these folders, so we just tweak them via environment variables.

Also, it is not guaranteed that different requests will be handled by same function instance, so, session also should be persisted in external storage or in cookies only.

These variables should be set as early as possible, so we cannot depend on `.env` variables and injecting them into function process instead.

```yaml
provider:
  environment:
    LOG_CHANNEL: stderr
    SESSION_DRIVER: cookie
    APP_PACKAGES_CACHE: /tmp/packages.php
    APP_SERVICES_CACHE: /tmp/services.php
    APP_CONFIG_CACHE: /tmp/config.php
    APP_ROUTES_CACHE: /tmp/routes.php
    APP_EVENTS_CACHE: /tmp/events.php
```

#### Layers

Here we're telling Serverless Framework, that we're going to use our custom-build `runtime` and specifying it's location.

```yaml
layers:
  runtime:
    retain: false
    package:
      artifact: storage/serverless/runtime.zip
```

#### Include/Exclude

Here you can tell which paths (using glob expressions) to include or exclude in final function layer.

```yaml
package:
  include:
    - tests/TestCase.php
  exclude:
    - .idea/**
    - storage/**
    - node_modules/**
    - bootstrap/cache/**
    - tests/**
```

#### Website function

This function serving http entrypoint of project, so, we have to define http events for it, and then Serverless will create [function api gateway](https://docs.aws.amazon.com/lambda/latest/dg/with-on-demand-https-example.html) for us.

Note: we're also composing function from the source code itself, and custom php runtime we're building (using `layers` key of yml)

```yaml
functions:
  website:
    handler: website
    memorySize: 128
    timeout: 30
    events:
      - http: 'ANY /'
      - http: 'ANY /{proxy+}'
    layers:
      - { Ref: RuntimeLambdaLayer }
```

### Artisan function

This function serving cli entrypoint of project. Here we already defining [CloudWatch Events](https://docs.aws.amazon.com/AmazonCloudWatch/latest/events/WhatIsCloudWatchEvents.html) trigger which will fire Laravel's schedule command.

```yaml
  artisan:
    handler: artisan
    memorySize: 128
    timeout: 900
    events:
      - schedule:
          rate: rate(1 minute)
          input:
            - 'schedule:run'
            - '--no-ansi'
            - '-n'
          enabled: true
    layers:
      - { Ref: RuntimeLambdaLayer }
```

### SQS Queue

In order to be able to run and schedule queued jobs by app, it is possible to configure [SQS Queue](https://aws.amazon.com/sqs/) for app. Serverless Framework will not create it for you, so, you should create it manually using AWS Management Console.

Next, you should ensure that sqs queue configuration is including `token` parameter, so update your `config/queue.php` file accordingly:

```php

return [
  'sqs' => [
     'token' => env('AWS_SESSION_TOKEN')
  ]
];

```

Then, require aws-sdk for php (`composer require aws/aws-sdk-php`), so you'll have working SQS Queue Driver, and, configure it using environment variables section of `serverless.yml`:

```yaml
provider:
  environment:
    QUEUE_CONNECTION: sqs
    SQS_PREFIX: https://sqs.<region_id>.amazonaws.com/<account_d>/
    SQS_QUEUE: <queue_name>
```

and define SQS trigger for `artisan` function:

```yaml
functions:
  artisan:
    events:
      - sqs:
          arn: arn:aws:sqs:<region_id>:<account_id>:<queue_name>
          enabled: true
          batchSize: 1
```

At this point, Role of function will prevent it from sendind messages into queue; so, once you have deployed your function, go to [IAM](https://console.aws.amazon.com/iam/home), find the Role associated with your function, and edit it's policy to include SQS.SendMessage action.

# Deployment

After steps above are done, you can deploy your project into AWS using `sls deploy` command.
