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

    // Load extension configuration
    if (version_compare(TYPO3_version, '9.0', '<')){
        $extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['dbintegrity'], ['allowed_classes' => false]);
    } else {
        $extConf = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
            \TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class
        )->get('dbintegrity');
    }

    // Register logger
    if (is_array($extConf) && array_key_exists('logLevel', $extConf)) {
        $logLevel = (int)$extConf['logLevel'];
        if ($logLevel > -1 && $logLevel < 8) {
            $GLOBALS['TYPO3_CONF_VARS']['LOG']['AawTeam']['Dbintegrity']['writerConfiguration'] = [
                $logLevel => [
                    \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                        'logFileInfix' => 'dbintegrity',
                    ],
                ],
            ];
        }
    }
};
$bootstrap();
unset($bootstrap);
