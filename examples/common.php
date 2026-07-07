<?php

require __DIR__.'/../vendor/autoload.php';

$fixturesDir = realpath(__DIR__.'/../tests/fixtures');

function save_to_output(string $contents, string $filename, string $directory): void
{
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    file_put_contents($directory.'/'.$filename, $contents);

    echo 'Saved: '.basename($directory)."/{$filename}\n";
}
