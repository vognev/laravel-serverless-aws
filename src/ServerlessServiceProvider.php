<?php

namespace Laravel\Serverless\Aws;

use Illuminate\Support\ServiceProvider;

class ServerlessServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $source = realpath($raw = __DIR__ . '/../config/serverless.php') ?: $raw;

        $this->mergeConfigFrom($source, 'serverless');
    }

    public function register()
    {
        $this->commands([
            Console\InstallCommand::class,
            Console\RuntimeCommand::class,
        ]);
    }

    public function provides()
    {
        return [

        ];
    }
}
