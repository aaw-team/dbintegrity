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
     * {@inheritDoc}
     * @see \TYPO3\CMS\Extbase\Persistence\Generic\Backend::insertObject()
     */
    protected function insertObject(\TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface $object, \TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface $parentObject = null, $parentPropertyName = '')
    {
        $className = get_class($object);
        if (!array_key_exists($className, $this->disableForeignKeyChecks)) {
            $this->disableForeignKeyChecks[$className] = ($object instanceof DisableForeignKeyChecksInterface);
        }
        if ($this->disableForeignKeyChecks[$className]) {
            $tableName = $this->getTableNameOfDomainObjectClassName($className);
            Management::disableForeignKeyChecks($tableName);
        }
        $return = parent::insertObject($object, $parentObject, $parentPropertyName);
        if ($this->disableForeignKeyChecks[$className]) {
            Management::enableForeignKeyChecks($tableName);
        }
        return $return;
    }

    /**
     * @param string $className
     * @return string
     */
    protected function getTableNameOfDomainObjectClassName(string $className): string
    {
        if (version_compare(TYPO3_version, '9.5', '<')) {
            return $tableName = $this->dataMapper->getDataMap($className)->getTableName();
        }
        return $this->dataMapFactory->buildDataMap($className)->getTableName();
    }
}
