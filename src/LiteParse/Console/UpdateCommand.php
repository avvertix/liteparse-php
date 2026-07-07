<?php

declare(strict_types=1);

namespace LiteParse\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'update',
    description: 'Update the native liteparse_php library for the installed package version',
)]
final class UpdateCommand extends Command
{
    public function __construct(
        private readonly string $packageRoot,
        private readonly string $projectRoot,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'version',
                InputArgument::OPTIONAL,
                'Release version to download (defaults to the installed package version)',
            )
            ->setHelp(
                "Re-downloads the compiled liteparse_php Rust library and its PDFium\n".
                "dependency, replacing any copy already present in the lib/ directory.\n".
                'Run this after upgrading the package.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $version */
        $version = $input->getArgument('version');

        try {
            (new NativeLibraryInstaller($this->packageRoot, $this->projectRoot))->install(
                $io,
                force: true,
                version: $version,
            );
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
