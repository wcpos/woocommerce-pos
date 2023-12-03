<?php

namespace WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\DebugBar;

use WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\Plugin\UpdateChecker;
if (!\class_exists(\WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\DebugBar\PluginPanel::class, \false)) {
    class PluginPanel extends \WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\DebugBar\Panel
    {
        /**
         * @var UpdateChecker
         */
        protected $updateChecker;
        protected function displayConfigHeader()
        {
            $this->row('Plugin file', \htmlentities($this->updateChecker->pluginFile));
            parent::displayConfigHeader();
        }
        protected function getMetadataButton()
        {
            $requestInfoButton = '';
            if (\function_exists('WCPOS\\Vendor\\get_submit_button')) {
                $requestInfoButton = get_submit_button('Request Info', 'secondary', 'puc-request-info-button', \false, array('id' => $this->updateChecker->getUniqueName('request-info-button')));
            }
            return $requestInfoButton;
        }
        protected function getUpdateFields()
        {
            return \array_merge(parent::getUpdateFields(), array('homepage', 'upgrade_notice', 'tested'));
        }
    }
}