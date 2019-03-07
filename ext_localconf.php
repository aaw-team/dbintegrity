<?php
/*
 * Copyright 2018 Agentur am Wasser | Maeder & Partner AG
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */
defined('TYPO3_MODE') or die();

$bootstrap = function () {
    /** @var \TYPO3\CMS\Extbase\SignalSlot\Dispatcher $signalSlotDispatcher */
    $signalSlotDispatcher = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\SignalSlot\Dispatcher::class);
    $signalSlotDispatcher->connect(
        'TYPO3\\CMS\\Install\\Service\\SqlExpectedSchemaService',
        'tablesDefinitionIsBeingBuilt',
        \AawTeam\Dbintegrity\Database\Management::class,
        'tablesDefinitionIsBeingBuilt'
    );

    // Register DataHandler hooks
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = \AawTeam\Dbintegrity\Hook\DataHandlerHook::class;
};
$bootstrap();
unset($bootstrap);
