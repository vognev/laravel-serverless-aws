<?php

namespace Laravel\Serverless\Aws\Console;

use Docker\API\Model\BuildInfo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Laravel\Serverless\Config;
use Laravel\Serverless\ZipArchive;
use Laravel\Serverless\Helper;
use Docker\Docker;
use Docker\Context\Context;

class RuntimeCommand extends Command
{
    protected $name = 'serverless:runtime';

    protected $description = 'Prepares your application runtime';

    /** @var Docker */
    protected $docker = null;

    public function __construct()
    {
        $this->docker = Docker::create();

        parent::__construct();
    }

    public function handle()
    {
        $phpModules     = Config::phpModules();
        $runtimeImage   = Config::projectSlug('runtime');

        $this->buildRuntimeDockerImage($runtimeImage, $phpModules);
        $this->extractRuntimeFromImage($runtimeImage);
    }

    private function buildRuntimeDockerImage(string $imageName, array $phpModules = [])
    {
        $this->info('Building runtime docker image');

        $buildContext   = new Context(
            \config('serverless.storage') . '/context'
        );

        $buildStream    = $this->docker->imageBuild($buildContext->toStream(), [
            't' => $imageName, 'buildargs' => json_encode([
                'SERVERLESS_PHP_MODULES' => implode(' ', $phpModules)
            ])
        ]);

        $buildStream->onFrame(function (BuildInfo $buildInfo) {
            if ($error = $buildInfo->getError()) {
                throw new \RuntimeException($error);
            } else {
                $stream = $buildInfo->getStream();
                if (0 === strpos($stream, 'Step')) {
                    $this->line("$stream");
                } elseif ($stream) {
                    $this->output->write($stream, false, $this->output::VERBOSITY_VERBOSE);
                }
            }
        });

        $buildStream->wait();
    }

    private function extractRuntimeFromImage(string $imageName)
    {
        $this->info('Extracting runtime from docker image');

        $tarPath   = config('serverless.storage') . '/runtime.tar';
        $tarStream = $this->docker->imageGet($imageName, $this->docker::FETCH_RESPONSE);
        Helper::stream_save($tarStream->getBody(), $tarPath);

        // create // clear runtime folder
        $runtimeDir = config('serverless.storage') . '/runtime';
        if (File::isDirectory($runtimeDir)) {
            File::cleanDirectory($runtimeDir);
        } else {
            File::makeDirectory($runtimeDir);
        }

        // extract all layers
        $bundle     = new \PharData($tarPath);
        $manifest   = json_decode($bundle['manifest.json']->getContent(), true);

        foreach ($manifest[0]['Layers'] as $layer) {
            /** @var \SplFileInfo $layerFile */
            $layerFile = $bundle[$layer];
            try {
                $layerPath = storage_path("serverless/{$layerFile->getBasename()}");
                copy($layerFile, $layerPath);

                // fixme: \PharData is not handling symlinks
                $oTar = new \Archive_Tar($layerPath);
                $oTar->extract($runtimeDir);
            } finally {
                @unlink($layerPath);
            }
        }

        // create zip from runtime
        $zipPath = storage_path('serverless/runtime.zip');
        File::exists($zipPath) && File::delete($zipPath);

        $zip = new ZipArchive();
        if (true !== $zip->open($zipPath, $zip::CREATE)) {
            throw new \RuntimeException('ZipArchive::open failure');
        }

        $zip->addFolderUnix($runtimeDir);

        if (true !== $zip->close()) {
            throw new \RuntimeException('ZipArchive::close failure');
        }

        File::delete($tarPath);
    }
}
