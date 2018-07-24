<?php
/*
 * Copyright 2018 Agentur am Wasser | Maeder & Partner AG
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

$EM_CONF[$_EXTKEY] = [
    'title' => 'DB Integrity',
    'description' => 'Extension development: manage database integrity',
    'category' => 'misc',
    'author' => 'Agentur am Wasser | Maeder & Partner AG',
    'author_email' => 'development@agenturamwasser.ch',
    'state' => 'alpha',
    'clearCacheOnLoad' => true,
    'version' => '0.1.0-dev',
    'constraints' => [
        'depends' => [
            'php' => '7.0.0-7.2.999',
            'typo3' => '8.7.17-8.7.999',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
