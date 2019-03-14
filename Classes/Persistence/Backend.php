<?php
declare(strict_types=1);
namespace AawTeam\Dbintegrity\Persistence;

/*
 * Copyright 2019 Agentur am Wasser | Maeder & Partner AG
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use AawTeam\Dbintegrity\Database\Management;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Backend
 *
 * Extend parent class: When inserting new objects that implement
 * DisableForeignKeyChecksInterface, disable FOREIGN_KEY_CHECKS for the
 * insert. That is needed, because of the way,
 * \TYPO3\CMS\Extbase\Persistence\Generic\Backend inserts new objects
 * into database: it first creates a record with 'empty' values in the
 * fields with relations (i.e. zero) and then updates that row with the
 * actual values. So we are safe to disable the fk checks at creation,
 * because the fields will always get updated afterwards (and the fk
 * checks will happen then).
 *
 * Note: this currently only possible with MySQL servers.
 *
 * @see \AawTeam\Dbintegrity\Persistence\DisableForeignKeyChecksInterface
 * @see \TYPO3\CMS\Extbase\Persistence\Generic\Backend
 */
class Backend extends \TYPO3\CMS\Extbase\Persistence\Generic\Backend
{
    /**
     * @var array
     */
    protected $disableForeignKeyChecks = [];

    /**
     * @var array
     */
    protected $nullableColumnsWithForeignKeyConstraints = [];

    /**
     * {@inheritDoc}
     * @see \TYPO3\CMS\Extbase\Persistence\Generic\Backend::insertObject()
     */
    protected function insertObject(\TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface $object, \TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface $parentObject = null, $parentPropertyName = '')
    {
        if (!$this->shouldDisableForeignKeyChecks($object)) {
            return parent::insertObject($object, $parentObject, $parentPropertyName);
        }

        // Wrap the parent method call in foreign key de-/reactivation
        $tableName = $this->getTableNameOfDomainObject($object);
        Management::disableForeignKeyChecks($tableName);
        $return = parent::insertObject($object, $parentObject, $parentPropertyName);
        Management::enableForeignKeyChecks($tableName);
        return $return;
    }

    /**
     * Extend parent method: Nullable columns that:
     *   1. belong to a DomainObject that wants to disable foreign key
     *      checks (@see Backend::shouldDisableForeignKeyChecks())
     *   2. are local part of a foreign key constraint
     *   3. can be null and define null as default value
     *   4. should have a null value (@see $object)
     *   5. are not present or have a 'zero-value' in $row
     *
     * should become null. Like this, we workaround the fact, that
     * extbase uses zero as 'null-value'. And on table creation, the
     * columns got such a value with fk checks disabled.
     *
     * @experimental
     * {@inheritDoc}
     * @see \TYPO3\CMS\Extbase\Persistence\Generic\Backend::addCommonFieldsToRow()
     */
    protected function addCommonFieldsToRow(\TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface $object, array &$row)
    {
        $return = parent::addCommonFieldsToRow($object, $row);

        if ($this->shouldDisableForeignKeyChecks($object)) {
            $tableName = $this->getTableNameOfDomainObject($object);

            // In-memory cache
            if (!array_key_exists($tableName, $this->nullableColumnsWithForeignKeyConstraints)) {
                $this->nullableColumnsWithForeignKeyConstraints[$tableName] = [];

                $possibleColumns = [];
                foreach ($this->getConnectionForTable($tableName)->getSchemaManager()->listTableColumns($tableName) as $column) {
                    if ($column->getNotnull() === false && $column->getDefault() === null) {
                        $possibleColumns[] = $column->getName();
                    }
                }

                $columnMap = [];
                $dataMap = $this->dataMapFactory->buildDataMap(get_class($object));
                // Build columnName => propertyName
                foreach ($object->_getProperties() as $propertyName => $propertyValue) {
                    $dataMapperColumnMap = $dataMap->getColumnMap($propertyName);
                    if ($dataMapperColumnMap) {
                        $columnMap[$dataMapperColumnMap->getColumnName()] = $propertyName;
                    }
                }

                foreach ($this->getConnectionForTable($tableName)->getSchemaManager()->listTableForeignKeys($tableName) as $foreignKey) {
                    foreach ($foreignKey->getLocalColumns() as $columnName) {
                        if (in_array($columnName, $possibleColumns) && array_key_exists($columnName, $columnMap)) {
                            $this->nullableColumnsWithForeignKeyConstraints[$tableName][$columnName] = $columnMap[$columnName];
                        }
                    }
                }
            }

            // Add the null values where needed
            foreach ($this->nullableColumnsWithForeignKeyConstraints[$tableName] as $columnName => $propertyName) {
                if (
                    $object->_getProperty($propertyName) === null
                    && (!array_key_exists($columnName, $row) || $row[$columnName] === 0)
                ) {
                    /** @var \TYPO3\CMS\Core\Log\Logger $logger */
                    $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
                    $logger->info('Added NULL value to row', [
                        'table' => $tableName,
                        'column' => $columnName,
                        'valueBefore' => $row[$columnName] ?? 'NOT_SET',
                    ]);

                    $row[$columnName] = null;
                }
            }
        }

        return $return;
    }

    /**
     * @param \TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface $object
     * @throws \InvalidArgumentException
     * @return bool
     */
    protected function shouldDisableForeignKeyChecks(\TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface $object): bool
    {
        $className = get_class($object);
        if (!array_key_exists($className, $this->disableForeignKeyChecks)) {
            $this->disableForeignKeyChecks[$className] = ($object instanceof DisableForeignKeyChecksInterface);
        }
        return $this->disableForeignKeyChecks[$className];
    }

    /**
     * @param \TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface $object
     * @return string
     */
    protected function getTableNameOfDomainObject(\TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface $object): string
    {
        $className = get_class($object);
        if (version_compare(TYPO3_version, '9.5', '<')) {
            return $tableName = $this->dataMapper->getDataMap($className)->getTableName();
        }
        return $this->dataMapFactory->buildDataMap($className)->getTableName();
    }

    /**
     * @param string $tableName
     * @return Connection
     */
    protected function getConnectionForTable(string $tableName): Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($tableName);
    }
}
