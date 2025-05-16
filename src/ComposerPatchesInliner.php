<?php

declare(strict_types=1);

namespace Cando\ComposerPatchesInliner\Plugin\Composer;

use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use cweagans\Composer\Patches;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * @TODO Enhance update capabilities:
 * - Better merge support for already downloaded patches.
 */
class ComposerPatchesInliner extends BaseCommand
{

    public function configure(): void
    {
        $this->setName('composer-patches-inliner');
        $this->setDescription(
            'Downloads all remote patches and stores them in a local directory.'
        );
        $this->setDefinition([
             new InputOption(
                 'delete-patches',
                 null,
                 InputOption::VALUE_NONE,
                 'If set all local patches are deleted before starting the download.'
             ),
             new InputOption(
                 'keep-patches',
                 null,
                 InputOption::VALUE_NONE,
                 'If set all local patches are always kept.'
             ),
             new InputOption(
                 'overwrite-patches',
                 null,
                 InputOption::VALUE_NONE,
                 'Re-download patches even if the file exists locally.'
             ),
             new InputOption(
                 'delay',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Delay in seconds between downloads',
                 60
             ),
             new InputOption(
                 'delayed-domains',
                 null,
                 InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                 'Domains where the delay is applied',
                 ['patch-diff.githubusercontent.com']
             ),
             new InputArgument(
                 'patches-path',
                 InputArgument::REQUIRED,
                 'Path to the directory where the patches are stored into.'
             ),
             new InputArgument(
                 'patches-file',
                 InputArgument::OPTIONAL,
                 'File where the inlined patch information is stored.',
                 'composer.patches-inline.json'
             ),
             new InputOption(
                 'dry-run',
                 null,
                 InputOption::VALUE_NONE,
                 'File where the inlined patch information is stored.'
             ),
        ]);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->requireComposer();
        $baseDir = rtrim(dirname($composer->getConfig()->get('vendor-dir')), '/') . '/';

        $patchesDir = $baseDir . $input->getArgument('patches-path') . '/';
        if (file_exists($patchesDir)) {
            $patchesDir = realpath($baseDir . $input->getArgument('patches-path')) . '/';
        }

        if (!$this->handlePatchesDirectory($input, $output, $patchesDir)) {
            return 1;
        }

        if (!is_writable($patchesDir)) {
            $output->writeln("<error>Patches direcotry {$patchesDir} not writable</error>");
            return 1;
        }
        $patches = $this->gatherPatches($input, $output);
        $inlinedPatches = $this->processPatches($input, $output, $patches, $patchesDir, $baseDir);

        if ($patchesFile = $input->getArgument('patches-file')) {
            $patchesFile = $baseDir . $patchesFile;
            $this->writeComposerPatchesInlineFile($input, $output, $patchesFile, $inlinedPatches);
        }

        $relativePatchesFile = str_replace($baseDir, './', $patchesFile);
        $output->writeln('Done!');
        $output->writeln('Make sure to configure patches file ' . $relativePatchesFile . ' as source for patches. Example:');
        $output->writeln(<<<EAT
<info>
  "extra": {
    "enable-patching": true,
    "patches-file": "{$relativePatchesFile}",
  },
</info>
EAT
        );

        return 0;
    }

    protected function getBaseDir(): string
    {
        $composer = $this->requireComposer();
        $baseDir = dirname($composer->getConfig()->get('vendor-dir'));
        return $baseDir;
    }

    protected function handlePatchesDirectory(
        InputInterface $input,
        OutputInterface $output,
        string $patchesDir
    ): bool {
        if (!file_exists($patchesDir)) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                'Patches directory does not exist. Do you want to create it? (Y/n): ',
                true
            );
            if ($helper->ask($input, $output, $question)) {
                $output->writeln('Creating patches directory...');
                if (!mkdir($patchesDir, 0755, true)) {
                    throw new RuntimeException('Failed to create directory: ' . $patchesDir);
                }
            } else {
                return false;
            }
        } else {
            $output->writeln("Patches directory {$patchesDir} already exists.");
            return $this->promptAndCleanPatchesDir(
                $input,
                $output,
                $patchesDir
            );
        }
        return true;
    }

    protected function promptAndCleanPatchesDir(
        InputInterface $input,
        OutputInterface $output,
        string $patchesDir
    ): bool {
        if ($input->getOption('keep-patches')) {
            $output->writeln("Keeping existing patches");
            return true;
        }

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            'Do you want to delete all existing patch files before proceeding? (y/N): ',
            false
        );

        if ($input->getOption('delete-patches') || $helper->ask(
                $input,
                $output,
                $question
            )) {
            $output->writeln('Cleaning patches directory...');
            $files = glob($patchesDir . '/*.patch');
            foreach ($files as $file) {
                if (!$input->getOption('dry-run')) {
                    if (unlink($file)) {
                        $output->writeln('Deleted: ' . basename($file));
                    } else {
                        throw new RuntimeException('Could not delete ' . basename($file));
                    }
                } else {
                    $output->writeln('Would delete: ' . basename($file));
                }
            }
        }
        return true;
    }

    /**
     * @return \cweagans\Composer\Patches|null
     */
    protected function getComposerPatchesInstance(): ?Patches
    {
        $composer = $this->requireComposer();
        $pluginManager = $composer->getPluginManager();
        $plugins = $pluginManager->getPlugins();
        foreach ($plugins as $plugin) {
            if ($plugin instanceof Patches) {
                return $plugin;
            }
        }
        return null;
    }

    /**
     * @TODO Add support for V2
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return array
     */
    protected function gatherPatches(
        InputInterface $input,
        OutputInterface $output,
    ): array {
        $composer = $this->requireComposer();

        $composerPatchesInstance = $this->getComposerPatchesInstance();
        if (!$composerPatchesInstance) {
            throw new RuntimeException("Failed to find the composer plugin cweagans/composer-patches");
        }
        // Simulate a package install event to gather the patches.
        $package = $composer->getPackage();
        $repo = $composer->getRepositoryManager()->getLocalRepository();
        $dummyInstallOperation = new InstallOperation($package);
        $dummyPackageEvent = new PackageEvent(
            PackageEvents::PRE_PACKAGE_INSTALL,
            $composer,
            $this->getIO(),
            $package->isDev(),
            $repo,
            [],
            $dummyInstallOperation
        );
        $composerPatchesInstance->gatherPatches($dummyPackageEvent);

        // Hijack the composerPatchesInstance to access the collected patches.
        $gatheredPatches = \Closure::bind(function () {
            return $this->patches;
        }, $composerPatchesInstance, $composerPatchesInstance)();

        return $gatheredPatches;
    }

    protected function processPatches(
        InputInterface $input,
        OutputInterface $output,
        array $patches,
        string $patchesDir,
        string $baseDir
    ): array {
        $downloadedPatches = [];
        unset($patches['_patchesGathered']);
        $current = 0;
        $patchesDir = rtrim($patchesDir, '/') . '/';
        $baseDir = rtrim($baseDir, '/') . '/';
        $relativePatchesDir = str_replace($baseDir, './', $patchesDir);

        $patchCount = $this->countPatches($patches);
        $delayedDomains = $input->getOption('delayed-domains');
        foreach ($patches as $package => $packagePatches) {
            foreach ($packagePatches as $description => $url) {
                $current++;
                $output->writeln("\nProcessing patch {$current}/{$patchCount}");
                $output->writeln("  Package: {$package}");
                $output->writeln("  Description: {$description}");

                if (file_exists($url)) {
                    $output->writeln("  Local file: {$url}");
                    $output->writeln("    Skip inlining");
                    continue;
                }

                $filename = $this->generatePatchFilename( $package, $description);
                $localPath = $patchesDir . $filename;
                if (!isset($downloadedPatches[$package])) {
                    $downloadedPatches[$package] = [];
                }

                if (!$input->getOption('overwrite-patches') && file_exists($localPath)) {
                    $output->writeln("  Patch file already exists: {$localPath}");
                    $output->writeln("    Skipping download");
                    $downloadedPatches[$package][$description] = $relativePatchesDir . $filename;
                    continue;
                }

                if ($this->downloadPatch($input, $output, $url, $localPath, $relativePatchesDir)) {
                    $downloadedPatches[$package][$description] = $relativePatchesDir . $filename;
                }

                if ($current < $patchCount) {
                    // @TODO Could be made more efficient by look-ahead if next patch is also from the same domain.
                    // If not continue but keep track of last download timestamp of delayed domain etc.
                    // Probably overkill for a rarely run command.
                    $domain = parse_url($url, PHP_URL_HOST);
                    if (in_array($domain, $delayedDomains)) {
                        $this->waitWithProgress($input, $output);;
                    }
                }
            }
        }
        return $downloadedPatches;
    }

    protected function countPatches(array $patches): int
    {
        $count = 0;
        foreach ($patches as $packagePatches) {
            $count += count($packagePatches);
        }
        return $count;
    }

    protected function generatePatchFilename(
        string $package,
        string $description
    ): string {
        return preg_replace(
                '/[^a-zA-Z0-9]/',
                '-',
                $package . '-' . $description
            ) . '.patch';
    }

    protected function downloadPatch(
        InputInterface $input,
        OutputInterface $output,
        string $url,
        string $localPath,
        string $relativePatchesDir
    ): bool {
        $output->writeln("  Downloading from: {$url}");

        if (!$input->getOption('dry-run')) {
            $content = @file_get_contents($url);
            if ($content === false) {
                $output->writeln("<error>Error: Failed to download patch from {$url}</error>");
                return false;
            }
            if (file_put_contents($localPath, $content) === false) {
                $output->writeln("<error>Error: Failed to save patch to {$localPath}</error>");
                return false;
            }
            $output->writeln("  Successfully saved to: " . $relativePatchesDir . basename($localPath));
        } else {
            $output->writeln("  Would be saved to: " . $relativePatchesDir . basename($localPath));
        }

        return true;
    }

    protected function waitWithProgress(
        InputInterface $input,
        OutputInterface $output
    ): void {
        $seconds = (int)$input->getOption('delay');

        $output->writeln('');
        $output->writeln("Waiting {$seconds} seconds before next download...");
        if ($input->getOption('dry-run')) {
            $output->writeln("<info>Skipping wait for dry-run.</info>");
            return;
        }
        for ($i = $seconds; $i > 0; $i--) {
            $output->write("\rTime remaining: {$i} seconds...");
            sleep(1);
        }
        $output->writeln('');
    }

    protected function writeComposerPatchesInlineFile(
        InputInterface $input,
        OutputInterface $output,
        string $patchesFile,
        array $inlinedPatches
    ): void {

        if (file_exists($patchesFile)) {
            $patchesFile = realpath($patchesFile);
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                '<warning>' . $patchesFile . ' already exits. Do you want to merge the data? (y/N): </warning>',
                false
            );
            $output->writeln('');
            $output->writeln('');
            if ($helper->ask($input, $output, $question)) {
                $patchesFileContent = file_get_contents($patchesFile);
                $existingPatches = json_decode($patchesFileContent, true, 512, JSON_THROW_ON_ERROR);
                if (isset($existingPatches['patches']) && is_array($existingPatches['patches'])) {
                    $inlinedPatches = $this->array_merge_recursive_distinct($existingPatches['patches'], $inlinedPatches);
                }
            }
        }

        if ((file_exists($patchesFile) && !is_writable($patchesFile)) || !is_writable(dirname($patchesFile))) {
            throw new RuntimeException("Patches file {$patchesFile} not writable");
        }

        $json = json_encode(['patches' => $inlinedPatches], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($input->getOption('dry-run')) {
            $output->writeln("<info>Would write following JSON to {$patchesFile}: \n{$json}</info>");
            return;
        }
        $output->writeln("Writing {$patchesFile} file...");
        file_put_contents($patchesFile, $json);
    }

    /**
     * array_merge_recursive does indeed merge arrays, but it converts values with duplicate
     * keys to arrays rather than overwriting the value in the first array with the duplicate
     * value in the second array, as array_merge does. I.e., with array_merge_recursive,
     * this happens (documented behavior):
     *
     * array_merge_recursive(array('key' => 'org value'), array('key' => 'new value'));
     *     => array('key' => array('org value', 'new value'));
     *
     * array_merge_recursive_distinct does not change the datatypes of the values in the arrays.
     * Matching keys' values in the second array overwrite those in the first array, as is the
     * case with array_merge, i.e.:
     *
     * array_merge_recursive_distinct(array('key' => 'org value'), array('key' => 'new value'));
     *     => array('key' => array('new value'));
     *
     * Parameters are passed by reference, though only for performance reasons. They're not
     * altered by this function.
     *
     * @param array $array1
     * @param array $array2
     * @return array
     * @author Daniel <daniel (at) danielsmedegaardbuus (dot) dk>
     * @author Gabriel Sobrinho <gabriel (dot) sobrinho (at) gmail (dot) com>
     */
    protected function array_merge_recursive_distinct( array &$array1, array &$array2 )
    {
        $merged = $array1;
        foreach ( $array2 as $key => &$value )
        {
            if ( is_array ( $value ) && isset ( $merged [$key] ) && is_array ( $merged [$key] ) )
            {
                $merged [$key] = $this->array_merge_recursive_distinct ( $merged [$key], $value );
            }
            else
            {
                $merged [$key] = $value;
            }
        }
        return $merged;
    }
}
