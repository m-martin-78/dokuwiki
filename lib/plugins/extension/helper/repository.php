<?php

/**
 * DokuWiki Plugin extension (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Michael Hamann <michael@content-space.de>
 */

use dokuwiki\Extension\Plugin;
use dokuwiki\Cache\Cache;
use dokuwiki\HTTP\DokuHTTPClient;
use dokuwiki\Extension\PluginController;

/**
 * Class helper_plugin_extension_repository provides access to the extension repository on dokuwiki.org
 */
class helper_plugin_extension_repository extends Plugin
{
    public const EXTENSION_REPOSITORY_API = 'https://www.dokuwiki.org/lib/plugins/pluginrepo/api.php';

    private $loaded_extensions = [];
    private $has_access;

    /**
     * Initialize the repository (cache), fetches data for all installed plugins
     */
    public function init()
    {
        /* @var PluginController $plugin_controller */
        global $plugin_controller;
        if ($this->hasAccess()) {
            $list = $plugin_controller->getList('', true);
            $request_data = ['fmt' => 'php'];
            $request_needed = false;
            foreach ($list as $name) {
                $cache = new Cache('##extension_manager##' . $name, '.repo');

                if (
                    !isset($this->loaded_extensions[$name]) &&
                    $this->hasAccess() &&
                    !$cache->useCache(['age' => 3600 * 24])
                ) {
                    $this->loaded_extensions[$name] = true;
                    $request_data['ext'][] = $name;
                    $request_needed = true;
                }
            }

            if ($request_needed) {
                $httpclient = new DokuHTTPClient();
                $data = $httpclient->post(self::EXTENSION_REPOSITORY_API, $request_data);
                if ($data !== false) {
                    $extensions = unserialize($data);
                    foreach ($extensions as $extension) {
                        $cache = new Cache('##extension_manager##' . $extension['plugin'], '.repo');
                        $cache->storeCache(serialize($extension));
                    }
                } else {
                    $this->has_access = false;
                }
            }
        }
    }

    /**
     * If repository access is available
     *
     * @param bool $usecache use cached result if still valid
     * @return bool If repository access is available
     */
    public function hasAccess($usecache = true)
    {
        if ($this->has_access === null) {
            $cache = new Cache('##extension_manager###hasAccess', '.repo');

            if (!$cache->useCache(['age' => 60 * 10, 'purge' => !$usecache])) {
                $httpclient = new DokuHTTPClient();
                $httpclient->timeout = 5;
                $data = $httpclient->get(self::EXTENSION_REPOSITORY_API . '?cmd=ping');
                if ($data !== false) {
                    $this->has_access = true;
                    $cache->storeCache(1);
                } else {
                    $this->has_access = false;
                    $cache->storeCache(0);
                }
            } else {
                $this->has_access = ($cache->retrieveCache(false) == 1);
            }
        }
        return $this->has_access;
    }

    /**
     * Get the remote data of an individual plugin or template
     *
     * @param string $name  The plugin name to get the data for, template names need to be prefix by 'template:'
     * @return array The data or null if nothing was found (possibly no repository access)
     */
    public function getData($name)
    {
        $cache = new Cache('##extension_manager##' . $name, '.repo');

        if (
            !isset($this->loaded_extensions[$name]) &&
            $this->hasAccess() &&
            !$cache->useCache(['age' => 3600 * 24])
        ) {
            $this->loaded_extensions[$name] = true;
            $httpclient = new DokuHTTPClient();
            $data = $httpclient->get(self::EXTENSION_REPOSITORY_API . '?fmt=php&ext[]=' . urlencode($name));
            if ($data !== false) {
                $result = unserialize($data);
                if (count($result)) {
                    $cache->storeCache(serialize($result[0]));
                    return $result[0];
                }
                return [];
            } else {
                $this->has_access = false;
            }
        }
        if (file_exists($cache->cache)) {
            return unserialize($cache->retrieveCache(false));
        }
        return [];
    }

    /**
     * Search for plugins or templates using the given query string
     *
     * @param string $q the query string
     * @return array a list of matching extensions
     */
    public function search($q)
    {
        $query = $this->parseQuery($q);
        $query['fmt'] = 'php';

        $httpclient = new DokuHTTPClient();
        $data = $httpclient->post(self::EXTENSION_REPOSITORY_API, $query);
        if ($data === false) return [];
        $result = unserialize($data);

        $ids = [];

        // store cache info for each extension
        foreach ($result as $ext) {
            $name = $ext['plugin'];
            $cache = new Cache('##extension_manager##' . $name, '.repo');
            $cache->storeCache(serialize($ext));
            $ids[] = $name;
        }

        return $ids;
    }

    /**
     * Parses special queries from the query string
     *
     * @param string $q
     * @return array
     */
    protected function parseQuery($q)
    {
        $parameters = ['tag'  => [], 'mail' => [], 'type' => [], 'ext'  => []];

        // extract tags
        if (preg_match_all('/(^|\s)(tag:([\S]+))/', $q, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $q = str_replace($m[2], '', $q);
                $parameters['tag'][] = $m[3];
            }
        }
        // extract author ids
        if (preg_match_all('/(^|\s)(authorid:([\S]+))/', $q, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $q = str_replace($m[2], '', $q);
                $parameters['mail'][] = $m[3];
            }
        }
        // extract extensions
        if (preg_match_all('/(^|\s)(ext:([\S]+))/', $q, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $q = str_replace($m[2], '', $q);
                $parameters['ext'][] = $m[3];
            }
        }
        // extract types
        if (preg_match_all('/(^|\s)(type:([\S]+))/', $q, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $q = str_replace($m[2], '', $q);
                $parameters['type'][] = $m[3];
            }
        }

        // FIXME make integer from type value

        $parameters['q'] = trim($q);
        return $parameters;
    }
}

// vim:ts=4:sw=4:et:
