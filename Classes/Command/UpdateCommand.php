<?php
declare(strict_types=1);
namespace AawTeam\Dbintegrity\Command;

/*
 * Copyright 2018 Agentur am Wasser | Maeder & Partner AG
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use AawTeam\Dbintegrity\Database\Management;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * UpdateCommand
 *
 * 1. Manage foreign key constraints (Configuration/ForeignKeyConstraints.php)
 * @todo 2. Manage view creation
 * @todo 3. Manage structure/data migration
 */
class UpdateCommand extends \Symfony\Component\Console\Command\Command
{
    const ERRORCODE_LOCKING = 1;

    /**
     * {@inheritDoc}
     * @see \Symfony\Component\Console\Command\Command::configure()
     */
    protected function configure()
    {
        $this->setDescription('Checks/Updates all configured database integrity constraints');
        $this->setHelp('');
        $this
            ->addOption(
                'check',
                null,
                InputOption::VALUE_NONE,
                'Test whether any update is needed, but take no action'
            )
            ->addOption(
                'extension',
                null,
                InputOption::VALUE_REQUIRED,
                'Process only the extension with this extension key',
                null
            )
        ;
    }

    /**
     * {@inheritDoc}
     * @see \Symfony\Component\Console\Command\Command::execute()
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Acquire lock
        try {
            $locker = $this->getLocker();
            $locker->acquire();
        } catch (\TYPO3\CMS\Core\Locking\Exception\LockCreateException $e) {
            $output->writeln('Error: cannot create lock: ' . $e->getMessage());
            return self::ERRORCODE_LOCKING;
        } catch (\Exception $e) {
            $output->writeln('Error: cannot acquire lock: ' . $e->getMessage());
            return self::ERRORCODE_LOCKING;
        }

        // Perform action for this extension only
        $extKey = $input->getOption('extension');

        if (Management::needsForeignKeyConstraintsUpdate($extKey)) {
            if ($input->getOption('check')) {
                $output->writeln('Foreign key constraints need to be updated. Please omit the "--check" option to run the update.');
            } else {
                $output->write('Running foreign key constraints update... ');
                $actionsCount = Management::updateForeignKeyConstraints($extKey);
                $output->writeln('done.');
                $output->writeln('Executed ' . $actionsCount . ' action(s)');
            }
        } else {
            $output->writeln('No foreign key constraints update needed.');
        }

        // Release the lock
        $locker->release();

        $output->writeln('All done, bye.');
        return 0;
    }

    /**
     * @return \TYPO3\CMS\Core\Locking\LockingStrategyInterface
     */
    protected function getLocker() : \TYPO3\CMS\Core\Locking\LockingStrategyInterface
    {
        return GeneralUtility::makeInstance(\TYPO3\CMS\Core\Locking\LockFactory::class)->createLocker('tx_dbintegrity_constraints');
    }
}
