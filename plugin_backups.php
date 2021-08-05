<?php
/**
 * Backup and Restore Additional Moodle Plugin
 *
 * This plugin only aims to backup and restore for additional plugins installed on Moodle,
 * so we don't need to manually move plugins during the Moodle upgrade process.
 *
 * @copyright 2021 Terus e-Learning
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author    Khairu Aqsara Sudirman <khairu@teruselearning.co.uk>
 */


define('CLI_SCRIPT', true);

require_once(__DIR__ . '/config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir.'/clilib.php');

list($options, $unrecognized) = cli_get_params(
    array(
        'help'=>false,
        'destination' => '',
        'folder' => '',
        'mode'=>'backup'
    ),
    array('h'=>'help'));

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error('Unknow Parameters');
}

if ($options['help']) {
    $help = <<<EOL
        Execute Additional Plugin Backup or Restore.
This script executes automated backups/restore completely additional moodle plugin.

Options:
-h, --help        Print out this help
--destination     Backup destination
--folder          Backup folder name
--mode            Operation mode (backup,restore) default value is backup

Example:
sudo -u www-data php plugin_backups.php --destination=/var/www/html --folder=plugin_backup --mode=backup
EOL;
    echo $help;
    die;
}

if($options['destination'] == ''){
    cli_heading('Backup destination');
    $prompt = "Enter Backup destination ex : /var/www/backup";
    $destination = cli_input($prompt);
}else{
    $destination = $options['destination'];
}

if($options['folder'] == ''){
    cli_heading('Backup folder name');
    $prompt = "Enter Backup folder name ex : plugin_backup";
    $folder = cli_input($prompt);
}else{
    $folder = $options['folder'];
}

if(!in_array($options['mode'], ['backup','restore'])){
    cli_error('Invalid mode parameter');
}

$run_backup = new Moodle_Additional_Plugin_Backup($destination, $folder);
if($options['mode'] == 'backup') {
    $run_backup->create_backup_directory()
        ->find_additional_installed_plugins()
        ->backup_additional_plugins();
}else{
    $run_backup->restore_additional_plugins();
}


class Moodle_Additional_Plugin_Backup
{
    public $path;
    public $folder;
    public $backup_path;
    public $plugins = array();

    /**
     * Moodle Backup and Restore Additional Plugin Constructor
     * @param $path string backup path
     * @param $folder string backup folder name
     */
    public function __construct($path, $folder){
        $this->path = $path;
        $this->folder = $folder;
        $this->backup_path = $path.DIRECTORY_SEPARATOR.$folder;
    }

    /**
     * Create backup directory
     * @return $this
     */
    public function create_backup_directory()
    {
        // check if backup destination is writeable
        if(is_writable($this->path)) {
            // check if backup destination is exists
            // if not then create the backup folder
            // otherwise delete it and re-create the backup folder
            if (!file_exists($this->backup_path)) {
                $this->create_folder();
            }else{
                $this->remove_backup_directory($this->backup_path);
                $this->create_folder();
            }
        }else{
            $this->show_error("Directory is not writeable");
        }
        return $this;
    }

    /**
     * Create Folder
     * @return $this
     */
    public function create_folder()
    {
        if (!mkdir($this->backup_path)) {
            $this->show_error("Failed to create directory");
        }
        return $this;
    }

    /**
     * Remove Directory and all contents
     * @param $dir string folder path
     * @return $this
     */
    public function remove_backup_directory($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir. DIRECTORY_SEPARATOR .$object) && !is_link($dir."/".$object))
                        $this->remove_backup_directory($dir. DIRECTORY_SEPARATOR .$object);
                    else
                        unlink($dir. DIRECTORY_SEPARATOR .$object);
                }
            }
            rmdir($dir);
        }
        return $this;
    }

    /**
     * Find installed Contrib only moodle plugin
     * @return $this
     * @throws dml_exception
     */
    public function find_additional_installed_plugins()
    {
        $syscontext = context_system::instance();
        $pluginman = core_plugin_manager::instance();
        $plugininfo = $pluginman->get_plugins();

        $plugin_array = array();
        foreach ($plugininfo as $type => $plugins) {
            foreach ($plugins as $name => $plugin) {
                if (empty($plugin->is_standard())) {
                    $rs_plugin = [
                        'type' => $type,
                        'name' => $name,
                        'typerootdir' => $plugin->typerootdir,
                        'rootdir' => $plugin->rootdir,
                        'displayname' => $plugin->displayname,
                        'versiondisk' => $plugin->versiondisk
                    ];
                    array_push($plugin_array, $rs_plugin);
                }
            }
        }

        $fp = fopen($this->backup_path.DIRECTORY_SEPARATOR.'meta.json', 'w');
        fwrite($fp, json_encode($plugin_array));
        fclose($fp);
        $this->plugins = $plugin_array;
        return $this;
    }

    /**
     * Copy plugin folder from source to destination
     * @param $sourceDirectory
     * @param $destinationDirectory
     * @param string $childFolder
     */
    public function copy_additional_moodle_plugins($sourceDirectory, $destinationDirectory, $childFolder = '')
    {
        $directory = opendir($sourceDirectory);

        if (is_dir($destinationDirectory) === false) {
            mkdir($destinationDirectory);
        }

        if ($childFolder !== '') {
            if (is_dir("$destinationDirectory/$childFolder") === false) {
                mkdir("$destinationDirectory/$childFolder");
            }

            while (($file = readdir($directory)) !== false) {
                if ($file === '.' || $file === '..') {
                    continue;
                }
                if (is_dir("$sourceDirectory/$file") === true) {
                    $this->copy_additional_moodle_plugins("$sourceDirectory/$file", "$destinationDirectory/$childFolder/$file");
                } else {
                    copy("$sourceDirectory/$file", "$destinationDirectory/$childFolder/$file");
                }
            }
            closedir($directory);
            return;
        }

        while (($file = readdir($directory)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (is_dir("$sourceDirectory/$file") === true) {
                $this->copy_additional_moodle_plugins("$sourceDirectory/$file", "$destinationDirectory/$file");
            }
            else {
                copy("$sourceDirectory/$file", "$destinationDirectory/$file");
            }
        }

        closedir($directory);
    }

    /**
     * Do backup Additional Plugins
     */
    public function backup_additional_plugins()
    {
        if(sizeof($this->plugins) > 0){
            foreach($this->plugins as $plugin){
                print("[+] Backing up ".$plugin['displayname']." (".$plugin['type']."_".$plugin['name'].") Version ".$plugin['versiondisk']."\n");
                $this->copy_additional_moodle_plugins($plugin['rootdir'], $this->backup_path.DIRECTORY_SEPARATOR.$plugin['name']);
            }
            print("[+] The backup process is complete\n");
            exit(0);
        }else{
            $this->show_error("Unable to find installed additional plugins");
        }
    }

    /**
     * Do Restore Additional Plugins
     */
    public function restore_additional_plugins()
    {
        if(file_exists($this->backup_path.DIRECTORY_SEPARATOR."meta.json")){
            $meta = file_get_contents($this->backup_path.DIRECTORY_SEPARATOR."meta.json");
            $meta = json_decode($meta, true);
            if(sizeof($meta) > 0){
                foreach($meta as $plugin){
                    print("[+] Restoring ".$plugin['displayname']." (".$plugin['type']."_".$plugin['name'].") Version ".$plugin['versiondisk']."\n");
                    if(file_exists($plugin['rootdir']) && is_dir($plugin['rootdir'])){
                        $this->remove_backup_directory($plugin['rootdir']);
                    }
                    $this->copy_additional_moodle_plugins($this->backup_path.DIRECTORY_SEPARATOR.$plugin['name'], $plugin['rootdir']);
                }
                print("[+] The restore process is complete\n");
                exit(0);
            }
        }else{
            $this->show_error("Unable to find meta file, make sure plugins are backed up");
        }
    }

    /**
     * Print out error message
     * @param $msg string Error Message
     */
    public function show_error($msg)
    {
        print "[!] {$msg}\n";
        exit(1);
    }
}


