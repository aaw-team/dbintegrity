<?php
declare(strict_types=1);
namespace AawTeam\Dbintegrity\Hook;

/*
 * Copyright 2018 Agentur am Wasser | Maeder & Partner AG
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use AawTeam\Dbintegrity\Database\Management;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * DataHandlerHook
 */
class DataHandlerHook implements SingletonInterface
{
    /**
     * @var int
     */
    protected $depthCounter = 0;

    /**
     * This hook gets called right before the database operations take
     * place. Here we disable the foreign key checks for tables with
     * foreign key constraints.
     *
     * @see DataHandlerHook::processDatamap_afterDatabaseOperations();
     * @param string $status
     * @param string $table
     * @param string|int $id
     * @param array $fieldArray
     * @param DataHandler $pObj
     */
    public function processDatamap_postProcessFieldArray(string $status, string $table, $id, array $fieldArray, DataHandler $pObj)
    {
        if ($status === 'new') {
            // If the table defines foreign key constraints, disable foreign key checks
            if (in_array($table, Management::getTablesWithForeignKeyConstraints())) {
                if ($this->depthCounter === 0) {
                    Management::disableForeignKeyChecks($table);
                }
                $this->depthCounter++;
            }
        }
    }

    /**
     * This hook gets called right after the database operations took
     * place. Here we re-enable the foreign key checks for tables with
     * foreign key constraints.
     *
     * @see DataHandlerHook::processDatamap_postProcessFieldArray();
     * @param string $status
     * @param string $table
     * @param string|int $id
     * @param array $fieldArray
     * @param DataHandler $pObj
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, $id, array $fieldArray, DataHandler $pObj)
    {
        if ($status === 'new') {
            // If the table defines foreign key constraints, re-enable foreign key checks
            if (in_array($table, Management::getTablesWithForeignKeyConstraints())) {
                if ($this->depthCounter > 0) {
                    $this->depthCounter--;
                }
                if ($this->depthCounter === 0) {
                    Management::enableForeignKeyChecks($table);
                }
            }
        }
    }
}
