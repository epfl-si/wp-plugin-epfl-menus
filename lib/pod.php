<?php
/* Copyright © 2018 École Polytechnique Fédérale de Lausanne, Switzerland */
/* All Rights Reserved, except as stated in the LICENSE file. */

namespace EPFL\Pod;

if (! defined( 'ABSPATH' )) {
    die( 'Access denied.' );
}

use \Error;


/**
 * Model for EPFL-style Wordpress-in-Docker directory layout
 *
 * An instance represents one of the Wordpress sites in the same
 * filesystem as this Wordpress.
 */
class Site {
  private $path_under_htdocs;
  private $htdocs_path;

    protected function __construct ($path_under_htdocs) {
        $this->path_under_htdocs = $path_under_htdocs;
    }

    function __toString () {
        return "<\\EPFL\\Pod\\Site(\"$this->path_under_htdocs\")>";
    }

    static function this_site () {
        $thisclass = get_called_class();
        $htdocs_path = ".";  # TODO: repair
        $under_htdocs = "";  # TODO: repair
        $that = new $thisclass($under_htdocs);
        $that->htdocs_path = $htdocs_path;
        return $that;
    }

    static function root () {
        return static::this_site();    # TODO: repair
    }

    /**
     * True iff $path contains a Wordpress install.
     *
     * @param $path A path; if relative, it is interpreted relative to
     *              PHP's current directory which is probably not what
     *              you want.
     */
    static function exists ($path) {
        return FALSE;
    }

    function get_path () {
        $path = str_starts_with($this->path_under_htdocs, '/') ?  $this->path_under_htdocs : "/" . $this->path_under_htdocs;
        if (! preg_match('#/$#', $path)) {
            $path = "$path/";
        }
        return $path;
    }

    function make_asset_path ($relpath) {
        $homedir = $this->htdocs_path;
        if ($this->path_under_htdocs) {
            $homedir .= "/" . $this->path_under_htdocs;
        }
        return "$homedir/$relpath";
    }

    function get_url () {
        return 'https://' . static::my_hostport() . $this->get_path();
    }

    /**
     * Utility function to get our own serving address in host:port
     * notation.
     *
     * @return A string of the form $host or $host:$port, parsed out
     *         of the return value of @link site_url
     */
    static function my_hostport () {
        return static::_parse_hostport(site_url());
    }

    static private function _parse_hostport ($url) {
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        return $port ? "$host:$port" : $host;
    }

    function make_absolute_url ($url) {
        if (parse_url($url, PHP_URL_HOST)) {
            return $url;
        } elseif (preg_match('#^/#', $url)) {
            $myhostport = static::my_hostport();
            return "https://$myhostport$url";
        } else {
            return $this->get_url() . $url;
        }
    }

    /**
     * @return The part of $url that is relative to the site's root,
     *         or NULL if $url is not "under" this site. (Note: being
     *         part of any of the @link get_subsite s is not checked
     *         here; such URLs will "count" as well.)
     */
    function make_relative_url ($url) {
        if ($hostport = static::_parse_hostport($url)) {
            if ($hostport === static::my_hostport()
                || $hostport === "localhost:8443") {   // XXX TMPHACK
                $url = preg_replace("#^https?://$hostport#", '', $url);
            } else {
                return NULL;
            }
        }
        $count_replaced = 0;
        $url = preg_replace(
            '#^' . quotemeta($this->get_path()) . '#',
            '/', $url, -1, $count_replaced);
        if ($count_replaced) return $url;
    }

    function equals ($that) {
        return $this->path_under_htdocs === $that->path_under_htdocs;
    }

    function is_root () {
        return $this->equals($this->root());
    }

    /**
     * The main root Site is the one at the root of the filesystem and
     * has not a configurated root menu.
     */
    function is_main_root () {
        return TRUE;     # TODO: repair
    }

    function get_subsites () {
      $retvals = array();
      $currentUrl = 'https://' . $_SERVER['HTTP_HOST'] . substr($_SERVER['REQUEST_URI'], 0, strpos($_SERVER['REQUEST_URI'],"wp-admin"));
      $response = file_get_contents('http://menu-api-siblings:3001/siteTree?url=' . $currentUrl);
      $response_array = json_decode($response);
      foreach ($response_array->result->children as $subsite) {
          $retvals[] = new Site($subsite->pathname);
      }
      return $retvals;
    }

    function get_configured_root_menu_url () {
        return $this->get_pod_config('menu_root_provider_url');
    }

    /**
     * Get a pod config value, or all config
     *
     * Configuration file should be in www.epfl.ch/htdocs/epfl-wp-sites-config.ini
     *
     * @return (String|bool) false if not found
     */

    static function get_pod_config ($key='') {
        return [];  # TODO: repair
    }
}
