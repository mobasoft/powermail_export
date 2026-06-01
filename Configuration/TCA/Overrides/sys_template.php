<?php
defined('TYPO3') or die('Access denied.');

call_user_func(static function (): void {
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile(
        'powermail_export',
        'Configuration/TypoScript',
        'Powermail Export'
    );
});
