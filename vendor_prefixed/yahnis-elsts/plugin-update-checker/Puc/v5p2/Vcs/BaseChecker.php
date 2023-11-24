<?php

namespace WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\Vcs;

if (!\interface_exists(\WCPOS\Vendor\YahnisElsts\PluginUpdateChecker\v5p2\Vcs\BaseChecker::class, \false)) {
    interface BaseChecker
    {
        /**
         * Set the repository branch to use for updates. Defaults to 'master'.
         *
         * @param string $branch
         * @return $this
         */
        public function setBranch($branch);
        /**
         * Set authentication credentials.
         *
         * @param array|string $credentials
         * @return $this
         */
        public function setAuthentication($credentials);
        /**
         * @return Api
         */
        public function getVcsApi();
    }
}
