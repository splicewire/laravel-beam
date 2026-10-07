<?php

namespace Splicewire\Beam\Install;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Console\VendorPublishCommand;
use Illuminate\Foundation\Events\VendorTagPublished;
use Illuminate\Support\Carbon;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/** Runs Laravel's own publish-item machinery over an already-filtered tag map. */
final class SelectedVendorPublisher extends VendorPublishCommand
{
    public function __construct(Filesystem $files)
    {
        parent::__construct($files);
    }

    /** @param array<string, string> $paths */
    public function publish(Application $app, string $tag, array $paths, bool $force): void
    {
        if ($paths === []) {
            return;
        }

        $input = new ArrayInput($force ? ['--force' => true] : [], $this->getDefinition());
        $this->setLaravel($app);
        $this->setInput($input);
        $this->setOutput(new OutputStyle($input, new NullOutput));
        $this->components = $app->make(Factory::class, ['output' => $this->output]);
        $this->publishedAt = Carbon::now();

        foreach ($paths as $source => $destination) {
            $this->publishItem($source, $destination);
        }

        $app['events']->dispatch(new VendorTagPublished($tag, $paths));
    }
}
