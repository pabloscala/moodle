<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
* @package    backup-convert
* @subpackage cc-library
* @copyright  2011 Darko Miletic <dmiletic@moodlerooms.com>
* @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

require_once 'xmlbase.php';

/**
 *
 * Various helper utils
 * @author Darko Miletic dmiletic@moodlerooms.com
 *
 */
abstract class cc_helpers {

    /**
     *
     * Checks extension of the supplied filename
     * @param string $filename
     */
    public static function is_html($filename) {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($extension, array('htm', 'html'));
    }

    /**
     *
     * Generates unique identifier
     * @param string $prefix
     * @param string $suffix
     * @return string
     */
    public static function uuidgen($prefix = '', $suffix = '', $uppercase = true) {
        $uuid = trim(sprintf('%s%04x%04x%s', $prefix, mt_rand(0, 65535), mt_rand(0, 65535), $suffix));
        $result = $uppercase ? strtoupper($uuid) : strtolower($uuid) ;
        return $result;
    }

    /**
     *
     * Creates new folder with random name
     * @param string $where
     * @param string $prefix
     * @param string $suffix
     * @return mixed - directory short name or false in case of faliure
     */
    public static function randomdir($where, $prefix = '', $suffix = '') {
        $dirname    = false;
        $randomname = self::uuidgen($prefix, $suffix, false);
        $newdirname = $where.DIRECTORY_SEPARATOR.$randomname;
        if (mkdir($newdirname)) {
            chmod($newdirname, 0755);
            $dirname = $randomname;
        }
        return $dirname;
    }

    /**
     *
     * Get list of embedded files
     * @param string $html
     * @return multitype:mixed
     */
    public static function embedded_files($html) {
        $result = array();
        $doc = new XMLGenericDocument();
        if ($doc->loadHTML($html)) {
            $list = $doc->nodeList("//img[starts-with(@src,'@@PLUGINFILE@@')]/@src");
            foreach ($list as $filelink) {
                $result[] = str_replace('@@PLUGINFILE@@', '', $filelink->nodeValue);
            }
        }
        return $result;
    }

    public static function embedded_mapping($packageroot, $contextid = null) {
        $main_file = $packageroot . DIRECTORY_SEPARATOR . 'files.xml';
        $mfile = new XMLGenericDocument();
        if (!$mfile->load($main_file)) {
            return false;
        }
        $query = "/files/file[filename!='.']";
        if (!empty($contextid)) {
            $query .= "[contextid='{$contextid}']";
        }
        $files = $mfile->nodeList($query);
        $depfiles = array();
        foreach ($files as $node) {
            $mainfile   = intval($mfile->nodeValue('sortorder', $node));
            $filename   = $mfile->nodeValue('filename', $node);
            $filepath   = $mfile->nodeValue('filepath', $node);
            $source     = $mfile->nodeValue('source', $node);
            $author     = $mfile->nodeValue('author', $node);
            $license    = $mfile->nodeValue('license', $node);
            $hashedname = $mfile->nodeValue('contenthash', $node);
            $hashpart   = substr($hashedname, 0, 2);
            $location   = 'files'.DIRECTORY_SEPARATOR.$hashpart.DIRECTORY_SEPARATOR.$hashedname;
            $type       = $mfile->nodeValue('mimetype', $node);
            $depfiles[$filepath.$filename] = array( $location,
                                                    ($mainfile == 1),
                                                    strtolower(str_replace(' ', '_',$filename)),
                                                    $type,
                                                    $source,
                                                    $author,
                                                    $license,
                                                    strtolower(str_replace(' ', '_',$filepath)));
        }

        return $depfiles;
    }

    public static function add_files(cc_i_manifest &$manifest, $packageroot, $outdir, $allinone = true) {
        if (pkg_static_resources::instance()->finished) {
            return;
        }
        $files = cc_helpers::embedded_mapping($packageroot);
        $rdir = $allinone ? new cc_resource_location($outdir) : null;
        foreach ($files as $virtual => $values) {
            $clean_filename = $values[2];
            if (!$allinone) {
                $rdir = new cc_resource_location($outdir);
            }
            $rtp = $rdir->fullpath(true).$clean_filename;
            //Are there any relative virtual directories?
            //let us try to recreate them
            $justdir = $rdir->fullpath(true).$values[7];
            if (!file_exists($justdir)) {
                if (!mkdir($justdir, 0777, true)) {
                    throw new RuntimeException('Unable to create directories!');
                }
            }

            $source = $packageroot.DIRECTORY_SEPARATOR.$values[0];
            if (!copy($source, $rtp)) {
                throw new RuntimeException('Unable to copy files!');
            }
            $resource = new cc_resource($rdir->rootdir(), $clean_filename, $rdir->dirname(true));
            $res = $manifest->add_resource($resource, null, cc_version11::webcontent);
            pkg_static_resources::instance()->add($virtual, $res[0], $rdir->dirname(true).$clean_filename, $values[1], $resource);
        }

        pkg_static_resources::instance()->finished = true;
    }

    /**
     *
     * Excerpt from IMS CC 1.1 overview :
     * No spaces in filenames, directory and file references should
     * employ all lowercase or all uppercase - no mixed case
     *
     * @param cc_i_manifest $manifest
     * @param string $packageroot
     * @param integer $contextid
     * @param string $outdir
     * @param boolean $allinone
     * @throws RuntimeException
     */
    public static function handle_static_content(cc_i_manifest &$manifest, $packageroot, $contextid, $outdir, $allinone = true){
        cc_helpers::add_files($manifest, $packageroot, $outdir, $allinone);
        return pkg_static_resources::instance()->get_values();
    }

    public static function handle_resource_content(cc_i_manifest &$manifest, $packageroot, $contextid, $outdir, $allinone = true){
        $result = array();
        cc_helpers::add_files($manifest, $packageroot, $outdir, $allinone);
        $files = cc_helpers::embedded_mapping($packageroot, $contextid);
        //$rdir = $allinone ? new cc_resource_location($outdir) : null;
        $rootnode = null;
        $rootvals = null;
        $depfiles = array();
        $depres = array();
        $flocation = null;
        foreach ($files as $virtual => $values) {
            $clean_filename = $values[2];
            $vals = pkg_static_resources::instance()->get_identifier($virtual);
            $resource = $vals[3];
            $identifier = $resource->identifier;
            $flocation = $vals[1];
            if ($values[1]) {
                $rootnode = $resource;
                $rootvals = $flocation;
                continue;
            }

            $depres[] = $identifier;
            $depfiles[] = $vals[1];
            $result[$virtual] = array($identifier, $flocation, false);
        }

        if (!empty($rootnode)) {
            $rootnode->files = array_merge($rootnode->files, $depfiles);
            $result[$virtual] = array($rootnode->identifier, $rootvals, true);
        }

        return $result;
    }

    public static function process_linked_files($content, cc_i_manifest &$manifest, $packageroot, $contextid, $outdir) {
        /**
        - detect all embedded files
        - locate their physical counterparts in moodle 2 backup
        - copy all files in the cc package stripping any spaces and using inly lowercase letters
        - add those files as resources of the type webcontent to the manifest
        - replace the links to the resourcse using $IMS-CC-FILEBASE$ and their new locations
        - cc_resource has array of files and array of dependencies
        - most likely we would need to add all files as independent resources and than
        attach them all as dependencies to the forum tag
        */
        $lfiles = self::embedded_files($content);
        $text = $content;
        $deps = null;
        if (!empty($lfiles)) {
            $files = self::handle_static_content($manifest,
                                                 $packageroot,
                                                 $contextid,
                                                 $outdir);
            foreach ($lfiles as $lfile) {
                if (array_key_exists($lfile, $files)) {
                    $text = str_replace('@@PLUGINFILE@@'.$lfile,
                                        '$IMS-CC-FILEBASE$../'.$files[$lfile][1],
                    $text);
                    $deps[] = $files[$lfile][0];
                }
            }
        }
        return array($text, $deps);
    }

    public static function relative_location($originpath, $linkingpath) {
        return false;
    }

}


final class cc_resource_location {
    /**
     *
     * Root directory
     * @var string
     */
    private $rootdir = null;
    /**
     *
     * new directory
     * @var string
     */
    private $dir = null;
    /**
     *
     * Full precalculated path
     * @var string
     */
    private $fullpath = null;

    /**
     *
     * ctor
     * @param string $rootdir - path to the containing directory
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function __construct($rootdir) {
        $rdir = realpath($rootdir);
        if (empty($rdir)) {
            throw new InvalidArgumentException('Invalid path!');
        }
        $dir = cc_helpers::randomdir($rdir, 'i_');
        if ($dir === false) {
            throw new RuntimeException('Unable to create directory!');
        }
        $this->rootdir  = $rdir;
        $this->dir      = $dir;
        $this->fullpath = $rdir.DIRECTORY_SEPARATOR.$dir;
    }

    /**
     *
     * Newly created directory
     * @return string
     */
    public function dirname($endseparator=false) {
        return $this->dir.($endseparator ? '/' : '');
    }

    /**
     *
     * Full path to the new directory
     * @return string
     */
    public function fullpath($endseparator=false) {
        return $this->fullpath.($endseparator ? DIRECTORY_SEPARATOR : '');
    }

    /**
     * Returns containing dir
     * @return string
     */
    public function rootdir($endseparator=false) {
        return $this->rootdir.($endseparator ? DIRECTORY_SEPARATOR : '');
    }
}

class pkg_static_resources {

    /**
     * @var array
     */
    private $values = array();

    /**
     * @var boolean
     */
    public $finished = false;

    /**
     * @var pkg_static_resources
     */
    private static $instance = null;

    private function __clone() {}
    private function __construct() {}

    /**
     * @return pkg_static_resources
     */
    public static function instance() {
        if (empty(self::$instance)) {
            $c = __CLASS__;
            self::$instance = new $c();
        }
        return self::$instance;
    }

    /**
     *
     * add new element
     * @param string $identifier
     * @param string $file
     * @param boolean $main
     */
    public function add($key, $identifier, $file, $main, $node = null) {
        $this->values[$key] = array($identifier, $file, $main, $node);
    }

    /**
     * @return array
     */
    public function get_values() {
        return $this->values;
    }

    public function get_identifier($location) {
        $result = false;
        if (array_key_exists($location, $this->values)) {
            $result = $this->values[$location];
        }
        return $result;
    }

    public function reset() {
        $this->values   = array();
        $this->finished = false  ;
    }
}