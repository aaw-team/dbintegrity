<?php
declare(strict_types=1);
namespace AawTeam\Dbintegrity\Database;

/*
 * Copyright 2018 Agentur am Wasser | Maeder & Partner AG
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

use Doctrine\DBAL\Platforms\MySqlPlatform;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Management
 */
class Management
{
    const FKCD_LOCAL_COLUMNS = 'localColumns';
    const FKCD_FOREIGN_TABLE = 'foreignTable';
    const FKCD_FOREIGN_COLUMNS = 'foreignColumns';
    const FKCD_EVENT_DELETE = 'onDelete';
    const FKCD_EVENT_UPDATE = 'onUpdate';

    const DOCTRINE_EVENT_DELETE = 'onDelete';
    const DOCTRINE_EVENT_UPDATE = 'onUpdate';

    const FKC_TASK_CREATE = 'create';
    const FKC_TASK_ALTER = 'alter';
    const FKC_TASK_DROP = 'drop';

    /**
     * @var array
     */
    protected static $foreignKeyConstraintsDefinitions = [];

    /**
     * @var array
     */
    protected static $foreignKeyConstraintsComparisons = [];

    /**
     * @param string $extKey
     * @return bool
     */
    public static function needsForeignKeyConstraintsUpdate(string $extKey = null): bool
    {
        $constraintDefinitions = self::getForeignKeyConstraintsDefinitions($extKey);

        // Merge definitions from all extensions
        if ($extKey === null) {
            $tmpDefinitions = [];
            foreach ($constraintDefinitions as $extension => $definition) {
                ArrayUtility::mergeRecursiveWithOverrule($tmpDefinitions, $definition);
            }
            $constraintDefinitions = $tmpDefinitions;
        }

        foreach ($constraintDefinitions as $tableName => $definition) {
            $tasks = self::compareForeignKeyConstraintsWithDefinition($tableName, $definition);

            if (!empty($tasks[self::FKC_TASK_CREATE]) || !empty($tasks[self::FKC_TASK_ALTER]) || !empty($tasks[self::FKC_TASK_DROP])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $extKey
     * @return int Number of actions executed
     */
    public static function updateForeignKeyConstraints(string $extKey = null): int
    {
        $constraintDefinitions = self::getForeignKeyConstraintsDefinitions($extKey);

        // Merge definitions from all extensions
        if ($extKey === null) {
            $tmpDefinitions = [];
            foreach ($constraintDefinitions as $extension => $definition) {
                ArrayUtility::mergeRecursiveWithOverrule($tmpDefinitions, $definition);
            }
            $constraintDefinitions = $tmpDefinitions;
        }

        $actions = 0;
        foreach ($constraintDefinitions as $localTableName => $foreignKeyDefinition) {
            $tasks = self::compareForeignKeyConstraintsWithDefinition($localTableName, $foreignKeyDefinition);

            foreach ($tasks[self::FKC_TASK_DROP] as $foreignKeyName) {
                self::dropForeignKeyConstraint($localTableName, $foreignKeyName);
                $actions++;
            }

            foreach ($tasks[self::FKC_TASK_CREATE] as $foreignKeyName) {
                self::addForeignKeyConstraint($localTableName, $foreignKeyName, $foreignKeyDefinition[$foreignKeyName]);
                $actions++;
            }

            foreach ($tasks[self::FKC_TASK_ALTER] as $foreignKeyName) {
                self::changeForeignKeyConstraint($localTableName, $foreignKeyName, $foreignKeyDefinition[$foreignKeyName]);
                $actions++;
            }

            // Clear the comparison cache
            unset(self::$foreignKeyConstraintsComparisons[$localTableName]);
        }
        return $actions;
    }

    /**
     * @return array
     */
    public static function getTablesWithForeignKeyConstraints(): array
    {
        $constraintDefinitions = [];
        foreach (self::getForeignKeyConstraintsDefinitions() as $extension => $definition) {
            ArrayUtility::mergeRecursiveWithOverrule($constraintDefinitions, $definition);
        }
        return array_keys($constraintDefinitions);
    }

    /**
     * @param string $tableName
     * @param string $foreignKeyConstraintName
     */
    protected static function dropForeignKeyConstraint(string $tableName, string $foreignKeyConstraintName)
    {
        self::getConnectionForTable($tableName)->getSchemaManager()->dropForeignKey($foreignKeyConstraintName, $tableName);
    }

    /**
     * @param string $tableName
     * @param string $foreignKeyName
     * @param array $definition
     */
    protected static function addForeignKeyConstraint(string $tableName, string $foreignKeyName, array $definition)
    {
        /** @var \Doctrine\DBAL\Schema\ForeignKeyConstraint $foreignKey */
        $foreignKeyConstraint = self::createForeignKeyConstraintFromDefinition($definition, $foreignKeyName);
        // Create a foreign key index, when none exists
        $needsFkIndex = true;
        foreach (self::getConnectionForTable($tableName)->getSchemaManager()->listTableIndexes($tableName) as $index) {
            // Key by this name exists
            if ($index->getName() == $foreignKeyConstraint->getName()) {

                // Check the columns, whether the fk index seem to be correct
                $diff = array_diff($foreignKeyConstraint->getLocalColumns(), $index->getColumns());
                if (!empty($diff)) {
                    throw new \Exception('Existing foreign key index "' . $tableName . '.' . $foreignKeyName . '" does not include the needed columns. Missing: "' . implode('", "', $diff) . '". Expected: "' . implode('", "', $foreignKeyConstraint->getLocalColumns()) . '")');
                }
                $needsFkIndex = false;
                break;
            }
        }
        if ($needsFkIndex) {
            $fkIndex = GeneralUtility::makeInstance(\Doctrine\DBAL\Schema\Index::class,
                $foreignKeyConstraint->getName(),
                $foreignKeyConstraint->getLocalColumns()
            );
            self::getConnectionForTable($tableName)->getSchemaManager()->createIndex($fkIndex, $tableName);
        }
        self::getConnectionForTable($tableName)->getSchemaManager()->createForeignKey($foreignKeyConstraint, $tableName);
    }

    /**
     * @param array $definition
     * @param string $foreignKeyName
     * @return \Doctrine\DBAL\Schema\ForeignKeyConstraint
     */
    protected static function createForeignKeyConstraintFromDefinition(array $definition, string $foreignKeyName = null): \Doctrine\DBAL\Schema\ForeignKeyConstraint
    {
        $options = [];
        if ($definition[self::FKCD_EVENT_UPDATE]) {
            $options[self::DOCTRINE_EVENT_UPDATE] = $definition[self::FKCD_EVENT_UPDATE];
        }
        if ($definition[self::FKCD_EVENT_DELETE]) {
            $options[self::DOCTRINE_EVENT_DELETE] = $definition[self::FKCD_EVENT_DELETE];
        }
        return GeneralUtility::makeInstance(\Doctrine\DBAL\Schema\ForeignKeyConstraint::class,
            [$definition[self::FKCD_LOCAL_COLUMNS]],
            $definition[self::FKCD_FOREIGN_TABLE],
            [$definition[self::FKCD_FOREIGN_COLUMNS]],
            $foreignKeyName,
            $options
        );
    }

    /**
     * @param string $tableName
     * @param string $foreignKeyName
     * @param array $definition
     */
    protected static function changeForeignKeyConstraint(string $tableName, string $foreignKeyName, array $definition)
    {
        $foreignKey = self::createForeignKeyConstraintFromDefinition($definition, $foreignKeyName);
        self::getConnectionForTable($tableName)->getSchemaManager()->dropAndCreateForeignKey($foreignKey, $tableName);
    }

    /**
     * Returns the names of the foreign key constraints, that do not
     * match the definition(s).
     *
     * @param string $tableName
     * @param array $definitions
     * @throws \Exception
     * @return array
     */
    protected static function compareForeignKeyConstraintsWithDefinition(string $tableName, array $definitions): array
    {
        if (array_key_exists($tableName, self::$foreignKeyConstraintsComparisons)) {
            return self::$foreignKeyConstraintsComparisons[$tableName];
        }

        $return = [
            self::FKC_TASK_CREATE => [],
            self::FKC_TASK_ALTER => [],
            self::FKC_TASK_DROP => [],
        ];

        /** @var \Doctrine\DBAL\Schema\ForeignKeyConstraint[] $existingForeignKeys */
        $existingForeignKeys = self::getConnectionForTable($tableName)->getSchemaManager()->listTableForeignKeys($tableName);
        $existingForeignKeyNames = [];

        foreach ($existingForeignKeys as $existingForeignKey) {
            $fkName = $existingForeignKeyNames[] = $existingForeignKey->getName();

            if (!array_key_exists($fkName, $definitions)) {
                // Not configured foreignKey, register for dropping
                $return[self::FKC_TASK_DROP][] = $fkName;
                continue;
            } elseif (!is_array($definitions[$fkName])) {
                throw new \Exception('Invalid foreign key constraint definition ("' . htmlspecialchars($fkName) . '") for table "' . htmlspecialchars($tableName) . '"');
            }

            // Definition of the fk currently being analyzed
            $definition = $definitions[$fkName];

            // Test 'main' definitions (localColumn, foreignTable, foreignColumn)
            $localColumns = $existingForeignKey->getLocalColumns();
            $foreignColumns = $existingForeignKey->getForeignColumns();

            if (!is_array($definition[self::FKCD_LOCAL_COLUMNS])) {
                $definition[self::FKCD_LOCAL_COLUMNS] = [$definition[self::FKCD_LOCAL_COLUMNS]];
            }
            if (!is_array($definition[self::FKCD_FOREIGN_COLUMNS])) {
                $definition[self::FKCD_FOREIGN_COLUMNS] = [$definition[self::FKCD_FOREIGN_COLUMNS]];
            }

            // localColumns
            if ($localColumns !== $definition[self::FKCD_LOCAL_COLUMNS]) {
                $return[self::FKC_TASK_ALTER][] = $fkName;
                continue;
            }
            // foreignColumns
            if ($foreignColumns !== $definition[self::FKCD_FOREIGN_COLUMNS]) {
                $return[self::FKC_TASK_ALTER][] = $fkName;
                continue;
            }
            // foreignTable
            if ($existingForeignKey->getForeignTableName() !== $definition[self::FKCD_FOREIGN_TABLE]) {
                $return[self::FKC_TASK_ALTER][] = $fkName;
                continue;
            }

            // Test constraint option 'ON DELETE'
            $definitionActionDelete = isset($definition[self::FKCD_EVENT_DELETE]) ? strtoupper($definition[self::FKCD_EVENT_DELETE]) : null;
            if ($existingForeignKey->onDelete()) {
                if ($existingForeignKey->onDelete() !== $definitionActionDelete) {
                    $return[self::FKC_TASK_ALTER][] = $fkName;
                    continue;
                }
            } elseif ($definitionActionDelete!== null && !in_array($definitionActionDelete, ['NO ACTION', 'RESTRICT'])) {
                // When existing fkc is null, it is equal to the 'default' value ('NO ACTION', 'RESTRICT')
                // if the definition does not match that default, register for alternation:
                $return[self::FKC_TASK_ALTER][] = $fkName;
                continue;
            }

            // Test constraint option 'ON UPDATE' (if supported by the platform)
            if (self::getConnectionForTable($tableName)->getDatabasePlatform()->supportsForeignKeyOnUpdate()) {
                $definitionActionUpdate = isset($definition[self::FKCD_EVENT_UPDATE]) ? strtoupper($definition[self::FKCD_EVENT_UPDATE]) : null;
                if ($existingForeignKey->onUpdate()) {
                    if ($existingForeignKey->onUpdate() !== $definitionActionUpdate) {
                        $return[self::FKC_TASK_ALTER][] = $fkName;
                        continue;
                    }
                } elseif ($definitionActionUpdate && !in_array($definitionActionUpdate, ['NO ACTION', 'RESTRICT'])) {
                    $return[self::FKC_TASK_ALTER][] = $fkName;
                    continue;
                }
            }
        }

        // Check missing constraints
        $missingConstraints = array_diff(array_keys($definitions), $existingForeignKeyNames);
        if (!empty($missingConstraints)) {
            $return[self::FKC_TASK_CREATE] = $missingConstraints;
        }

        // Store in in-memory cache
        self::$foreignKeyConstraintsComparisons[$tableName] = $return;

        return self::$foreignKeyConstraintsComparisons[$tableName];
    }

    /**
     * @throws \RuntimeException
     * @return array
     */
    protected static function getForeignKeyConstraintsDefinitions(string $extKey = null): array
    {
        if ($extKey !== null && array_key_exists($extKey, self::$foreignKeyConstraintsDefinitions)) {
            return self::$foreignKeyConstraintsDefinitions[$extKey];
        }

        if ($extKey === null) {
            $extensions = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::getLoadedExtensionListArray();
        } elseif (!\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded($extKey)) {
            throw new \Exception('Extension "' . htmlspecialchars($extKey) . '" is not loaded');
        } else {
            $extensions = [$extKey];
        }

        foreach ($extensions as $extension) {
            $definitionsFileName = GeneralUtility::getFileAbsFileName('EXT:' . $extension . '/Configuration/ForeignKeyConstraints.php');

            if (!is_file($definitionsFileName)) {
                self::$foreignKeyConstraintsDefinitions[$extension] = [];
                continue;
            } elseif (!is_readable($definitionsFileName)) {
                throw new \RuntimeException('Foreign key constraints configuration file cannot be read: "' . $definitionsFileName . '"');
            }

            $definitions = require $definitionsFileName;
            if (!is_array($definitions)) {
                $definitions = [];
            } else {
                // Validate definitions
                foreach ($definitions as $tableName => $tableDefinitions) {
                    if (!is_array($tableDefinitions)) {
                        throw new \Exception('Invalid foreign key constraint definition in "' . htmlspecialchars($tableName) . '"');
                    }
                    foreach ($tableDefinitions as $fkName => $definition) {
                        if (
                            !is_array($definition)

                            // local column:
                            // - must be defined
                            // - must be string or array
                            || !array_key_exists(self::FKCD_LOCAL_COLUMNS, $definition)
                            || (!is_string($definition[self::FKCD_LOCAL_COLUMNS]) && !is_array($definition[self::FKCD_LOCAL_COLUMNS]))

                            // foreign table
                            // - must be present and string
                            || !array_key_exists(self::FKCD_FOREIGN_TABLE, $definition) || !is_string($definition[self::FKCD_FOREIGN_TABLE])

                            // foreign column:
                            // - must be defined
                            // - must be string or array
                            || !array_key_exists(self::FKCD_FOREIGN_COLUMNS, $definition)
                            || (!is_string($definition[self::FKCD_FOREIGN_COLUMNS]) && !is_array($definition[self::FKCD_FOREIGN_COLUMNS]))

                            // events (onupdate, ondelete)
                            // - if present, must be string
                            || array_key_exists(self::FKCD_EVENT_DELETE, $definition) && !is_string($definition[self::FKCD_EVENT_DELETE])
                            || array_key_exists(self::FKCD_EVENT_UPDATE, $definition) && !is_string($definition[self::FKCD_EVENT_UPDATE])
                        ) {
                            throw new \Exception('Invalid foreign key constraint definition in "' . htmlspecialchars($tableName) . '.' . htmlspecialchars($fkName) . '"');
                        }
                    }
                }
            }
            self::$foreignKeyConstraintsDefinitions[$extension] = $definitions;
        }

        if ($extKey !== null) {
            return self::$foreignKeyConstraintsDefinitions[$extKey];
        }
        return self::$foreignKeyConstraintsDefinitions;
    }

    /**
     * @param array $sqlString
     * @return array
     */
    public function tablesDefinitionIsBeingBuilt(array $sqlString): array
    {
        foreach (self::getForeignKeyConstraintsDefinitions() as $extensionKey => $extensionDefinitions) {
            foreach ($extensionDefinitions as $tableName => $definitions) {
                foreach ($definitions as $foreignKeyName => $definition) {
                    // Add a CREATE TABLE statement with
                    //   1. All local columns as int(11). The data type dioes not matter, as long as anything is present. It will be overwritten by ext_tables.sql
                    //   2. A local index like 'fk_' + $fieldnames + 'idx'
                    //   3. A local FOREIGN KEY definition

                    // Create the foreignKeyConstraint WITHOUT name (TYPO3 does not support CONSTRAINT, only FOREIGN KEY at the moment)
                    /** @var \Doctrine\DBAL\Schema\ForeignKeyConstraint $foreignKeyConstraint */
                    $foreignKeyConstraint = self::createForeignKeyConstraintFromDefinition($definition);

                    $localColumnType = 'int(11)';
                    $localColumnsDefinition = implode(' ' . $localColumnType . ', ', $foreignKeyConstraint->getLocalColumns()) . ' ' . $localColumnType;
                    $localIndexDefinition = 'INDEX ' . $foreignKeyName . ' (' . implode(',', $foreignKeyConstraint->getLocalColumns()) . ')';
                    // Slide-in the key name
                    $foreignKeyDefinition = preg_replace(
                        '~^(FOREIGN KEY)~',
                        '$1 ' . $foreignKeyName,
                        self::getConnectionForTable($tableName)->getDatabasePlatform()->getForeignKeyDeclarationSQL($foreignKeyConstraint)
                    );

                    $creationSql = sprintf(
                        'CREATE TABLE %s ( %s, %s, %s );',
                        $tableName,
                        $localColumnsDefinition,
                        $localIndexDefinition,
                        $foreignKeyDefinition
                    );

                    // Prepend (!) this sql stuff to be sure, the definitions from ext_tables.sql overide them!
                    array_unshift($sqlString, $creationSql);
                }
            }
        }
        return ['sqlString' => $sqlString];
    }

    /**
     * @param string $tableName
     */
    public static function disableForeignKeyChecks(string $tableName)
    {
        if (is_a(self::getConnectionForTable($tableName)->getDatabasePlatform(), MySqlPlatform::class)) {
            self::getConnectionForTable($tableName)->executeQuery('SET FOREIGN_KEY_CHECKS = 0');
        }
    }

    /**
     * @param string $tableName
     */
    public static function enableForeignKeyChecks(string $tableName)
    {
        if (is_a(self::getConnectionForTable($tableName)->getDatabasePlatform(), MySqlPlatform::class)) {
            self::getConnectionForTable($tableName)->executeQuery('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * @return \TYPO3\CMS\Core\Database\Connection
     */
    protected static function getConnectionForTable(string $tableName)/*: \TYPO3\CMS\Core\Database\Connection*/
    {
        return GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)->getConnectionForTable($tableName);
    }
}
