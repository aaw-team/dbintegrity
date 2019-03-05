<?php
declare(strict_types=1);
namespace AawTeam\Dbintegrity\Persistence;

/*
 * Copyright 2019 Agentur am Wasser | Maeder & Partner AG
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

/**
 * PersistenceManager
 *
 * Extend parent class: use \AawTeam\Dbintegrity\Persistence\Backend as
 * backend.
 *
 * @see \AawTeam\Dbintegrity\Persistence\Backend
 * @see \TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager
 */
class PersistenceManager extends \TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager
{
    /**
     * @var \AawTeam\Dbintegrity\Persistence\Backend
     * @inject
     */
    protected $backend;
}
