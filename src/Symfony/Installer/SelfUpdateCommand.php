<?php

/*
 * This file is part of the Symfony Installer package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Installer;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * This command is inspired by the self-update command included
 * in the PHP-CS-Fixer library. See:
 * https://github.com/fabpot/PHP-CS-Fixer/blob/master/Symfony/CS/Console/Command/SelfUpdateCommand.php
 *
 * @author Igor Wiedler <igor@wiedler.ch>
 * @author Stephane PY <py.stephane1@gmail.com>
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
class SelfUpdateCommand extends Command
{
    /** @var Filesystem */
    private $fs;
    /** @var OutputInterface */
    private $output;
    private $tempDir;
    // URL where the latest installer version can be downloaded
    private $remoteInstallerFile;
    // the installer currently installed in the local machine
    private $currentInstallerFile;
    // the new installer downloaded to replace the current installer
    private $newInstallerFile;
    // a backup of the current installer in case a rollback is performed
    private $currentInstallerBackupFile;
    // a boolean flag which indicates that, in case of a rollback, it's safe to
    // restore the installer backup because it corresponds to the most recent version
    private $restorePreviousInstaller;

    protected function configure()
    {
        $this
            ->setName('self-update')
            ->setAliases(array('selfupdate'))
            ->setDescription('Update the installer to the latest version.')
            ->setHelp('The <info>%command.name%</info> command updates the installer to the latest available version.')
        ;
    }

    /**
     * The self-update command is only available when using the installer via the PHAR file.
     */
    public function isEnabled()
    {
        return 'phar://' === substr(__DIR__, 0, 7);
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->fs = new Filesystem();
        $this->output = $output;

        $this->remoteInstallerFile = 'http://symfony.com/installer';
        $this->currentInstallerFile = realpath($_SERVER['argv'][0]) ?: $_SERVER['argv'][0];
        $this->tempDir = is_writable(dirname($this->currentInstallerFile)) ? dirname($this->currentInstallerFile) : sys_get_temp_dir();
        $this->currentInstallerBackupFile = basename($this->currentInstallerFile, '.phar').'-backup.phar';
        $this->newInstallerFile = $this->tempDir.'/'.basename($this->currentInstallerFile, '.phar').'-temp.phar';
        $this->restorePreviousInstaller = false;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->installerIsUpdated()) {
            return;
        }

        try {
            $this
                ->downloadNewVersion()
                ->checkNewVersionIsValid()
                ->backupOldVersion()
                ->replaceOldVersionbyNewVersion()
            ;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    private function installerIsUpdated()
    {
        $isUpdated = false;
        $localVersion = $this->getApplication()->getVersion();

        if (false === $remoteVersion = @file_get_contents('http://get.sensiolabs.org/symfony.version')) {
            throw new \RuntimeException('The new version of the Symfony Installer couldn\'t be downloaded from the server.');
        }

        if ($localVersion === $remoteVersion) {
            $this->output->writeln('<info>Symfony Installer is already up to date.</info>');
            $isUpdated = true;
        }

        return $isUpdated;
    }

    private function downloadNewVersion()
    {
        // check for permissions in local filesystem before start downloading files
        if (!is_writable($this->currentInstallerFile)) {
            throw new \RuntimeException('Symfony Installer update failed: the "'.$this->currentInstallerFile.'" file could not be written');
        }

        if (!is_writable($this->tempDir)) {
            throw new \RuntimeException('Symfony Installer update failed: the "'.$this->tempDir.'" directory used to download files temporarily could not be written');
        }

        if (false === $newInstaller = @file_get_contents($this->remoteInstallerFile)) {
            throw new \RuntimeException('The new version of the Symfony Installer couldn\'t be downloaded from the server.');
        }

        $newInstallerPermissions = $this->currentInstallerFile ? fileperms($this->currentInstallerFile) : 0777 & ~umask();
        $this->fs->dumpFile($this->newInstallerFile, $newInstaller, $newInstallerPermissions);

        return $this;
    }

    private function checkNewVersionIsValid()
    {
        // creating a Phar instance for an existing file is not allowed
        // when the Phar extension is in readonly mode
        if (!ini_get('phar.readonly')) {
            // test the phar validity
            $phar = new \Phar($this->newInstallerFile);

            // free the variable to unlock the file
            unset($phar);
        }

        return $this;
    }

    private function backupOldVersion()
    {
        $this->fs->copy($this->currentInstallerFile, $this->currentInstallerBackupFile, true);
        $this->fs->copy($this->newInstallerFile, $this->currentInstallerFile, true);

        $this->restorePreviousInstaller = true;

        return $this;
    }

    private function replaceOldVersionbyNewVersion()
    {
        $fs->copy($this->newInstallerFile, $this->currentInstallerFile, true);

        return $this;
    }

    private function rollback()
    {
        $this->fs->remove($this->newInstallerFile);

        if ($this->restorePreviousInstaller) {
            $this->fs->copy($this->currentInstallerBackupFile, $this->currentInstallerFile, true);
        }
    }
}
