<?php

namespace WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\DebugBar;

use WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\Theme\UpdateChecker;
if (!\class_exists(\WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\DebugBar\ThemePanel::class, \false)) {
    class ThemePanel extends \WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\DebugBar\Panel
    {
        /**
         * @var UpdateChecker
         */
        protected $updateChecker;
        protected function displayConfigHeader()
        {
            $this->row('Theme directory', \htmlentities($this->updateChecker->directoryName));
            parent::displayConfigHeader();
        }
        protected function getUpdateFields()
        {
            return \array_merge(parent::getUpdateFields(), array('details_url'));
        }
    }
}
