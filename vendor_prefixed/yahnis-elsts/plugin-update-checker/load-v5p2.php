<?php

namespace WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2;

use WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5\PucFactory as MajorFactory;
use WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\PucFactory as MinorFactory;
require __DIR__ . '/Puc/v5p2/Autoloader.php';
new \WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\Autoloader();
require __DIR__ . '/Puc/v5p2/PucFactory.php';
require __DIR__ . '/Puc/v5/PucFactory.php';
//Register classes defined in this version with the factory.
foreach (array('WCPOS\\Vendor\\Plugin\\UpdateChecker' => \WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\Plugin\UpdateChecker::class, 'WCPOS\\Vendor\\Theme\\UpdateChecker' => \WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\Theme\UpdateChecker::class, 'WCPOS\\Vendor\\Vcs\\PluginUpdateChecker' => \WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\Vcs\PluginUpdateChecker::class, 'WCPOS\\Vendor\\Vcs\\ThemeUpdateChecker' => \WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\Vcs\ThemeUpdateChecker::class, 'GitHubApi' => \WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\Vcs\GitHubApi::class, 'BitBucketApi' => \WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\Vcs\BitBucketApi::class, 'GitLabApi' => \WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\Vcs\GitLabApi::class) as $pucGeneralClass => $pucVersionedClass) {
    \WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5\PucFactory::addVersion($pucGeneralClass, $pucVersionedClass, '5.2');
    //Also add it to the minor-version factory in case the major-version factory
    //was already defined by another, older version of the update checker.
    \WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\PucFactory::addVersion($pucGeneralClass, $pucVersionedClass, '5.2');
}
