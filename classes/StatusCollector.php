<?php namespace Webula\Beacon\Classes;

use App;
use Db;
use Config;
use Throwable;
use System\Models\Parameter;

/**
 * StatusCollector gathers the site status reported to the Lighthouse hub.
 *
 * Every section is collected independently, a failure in one section is reported
 * in "errors" instead of breaking the whole response. Keep PHP 7.0 compatible.
 */
class StatusCollector
{
    /**
     * @var string BEACON_VERSION reported to the hub, bump together with version.yaml
     */
    const BEACON_VERSION = '2.2.0';

    /**
     * @var string SCHEDULE_PARAMETER stores the last scheduler heartbeat
     */
    const SCHEDULE_PARAMETER = 'webula.beacon::schedule.last_run';

    /**
     * @var string SMALLBACKUP_SETTINGS item of the Webula.SmallBackup settings in system_settings (1.x and 2.x)
     */
    const SMALLBACKUP_SETTINGS = 'webula_smallbackup_settings';

    /**
     * @var array backupPrefixes of the Webula.SmallBackup files by backup type
     */
    protected static $backupPrefixes = ['db' => 'wsb-db-', 'theme' => 'wsb-theme-', 'storage' => 'wsb-storage-'];

    /**
     * @var array errorLevels counted as errors in the event log
     */
    protected static $errorLevels = ['error', 'critical', 'alert', 'emergency'];

    /**
     * @var array errors collected while building the status
     */
    protected $errors = [];

    /**
     * collect returns the full status payload.
     */
    public function collect()
    {
        $status = [
            'beacon' => ['version' => static::BEACON_VERSION, 'generated_at' => date('c')],
            'october' => $this->section('october', 'collectOctober'),
            'php' => $this->section('php', 'collectPhp'),
            'app' => $this->section('app', 'collectApp'),
            'database' => $this->section('database', 'collectDatabase'),
            'server' => $this->section('server', 'collectServer'),
            'scheduler' => $this->section('scheduler', 'collectScheduler'),
            'theme' => $this->section('theme', 'collectTheme'),
            'plugins' => $this->section('plugins', 'collectPlugins'),
            'event_log' => $this->section('event_log', 'collectEventLog'),
            'backup' => $this->section('backup', 'collectBackup'),
            'traffic' => $this->section('traffic', 'collectTraffic'),
        ];

        $status['errors'] = $this->errors;

        return static::toValidUtf8($status);
    }

    /**
     * toValidUtf8 replaces invalid UTF-8 in all strings (e.g. event log messages in a legacy encoding),
     * which would otherwise make json_encode fail. JSON_INVALID_UTF8_SUBSTITUTE needs PHP 7.2.
     */
    protected static function toValidUtf8($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = static::toValidUtf8($item);
            }

            return $value;
        }

        if (!is_string($value) || preg_match('//u', $value)) {
            return $value;
        }

        return function_exists('mb_convert_encoding')
            ? mb_convert_encoding($value, 'UTF-8', 'UTF-8')
            : preg_replace('/[\x80-\xFF]/', '?', $value);
    }

    /**
     * section runs one collector method and records its failure. Catches Throwable, not only
     * Exception, so PHP errors (missing extension or class, TypeError) fail just this section.
     */
    protected function section($name, $method)
    {
        try {
            return $this->$method();
        }
        catch (Throwable $ex) {
            $this->errors[$name] = $ex->getMessage();
            return null;
        }
    }

    /**
     * collectOctober detects the October CMS generation, exact version and build.
     */
    protected function collectOctober()
    {
        $major = defined('System\Facades\System::VERSION') ? constant('System\Facades\System::VERSION') : null;

        return [
            'major' => $major,
            'version' => $this->getPackageVersion('october/system'),
            'build' => Parameter::get('system::core.build'),
            'laravel' => App::version(),
        ];
    }

    /**
     * collectPhp returns the PHP runtime and the most relevant limits and extensions.
     */
    protected function collectPhp()
    {
        $extensions = [];
        foreach (['curl', 'gd', 'imagick', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'sodium', 'zip'] as $extension) {
            $extensions[$extension] = extension_loaded($extension);
        }

        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'extensions' => $extensions,
        ];
    }

    /**
     * collectApp returns application settings that matter for a live site.
     */
    protected function collectApp()
    {
        return [
            'env' => App::environment(),
            'debug' => (bool) Config::get('app.debug'),
            'url' => Config::get('app.url'),
            'timezone' => Config::get('app.timezone'),
            'locale' => Config::get('app.locale'),
            'backend_uri' => Config::get('backend.uri', Config::get('cms.backendUri')),
        ];
    }

    /**
     * collectDatabase returns the driver and server version.
     */
    protected function collectDatabase()
    {
        $connection = Db::connection();

        return [
            'driver' => $connection->getDriverName(),
            'server_version' => $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
        ];
    }

    /**
     * collectServer returns web server software and disk space of the application path.
     */
    protected function collectServer()
    {
        // Hostings often disable these functions, since PHP 8 a disabled function does not exist at all
        $free = function_exists('disk_free_space') ? @disk_free_space(base_path()) : null;
        $total = function_exists('disk_total_space') ? @disk_total_space(base_path()) : null;

        return [
            'software' => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : null,
            'os' => PHP_OS,
            'hostname' => gethostname(),
            'disk_free_mb' => static::toMegabytes($free),
            'disk_total_mb' => static::toMegabytes($total),
        ];
    }

    /**
     * toMegabytes converts a disk size to MB. Shared hostings often disable the disk functions
     * (they return null or false) or report zero, so anything but a positive number is unknown.
     */
    protected static function toMegabytes($bytes)
    {
        return is_numeric($bytes) && $bytes > 0 ? (int) round($bytes / 1048576) : null;
    }

    /**
     * collectScheduler returns the last heartbeat written by the scheduled task,
     * which tells whether the cron (schedule:run) works on the site.
     */
    protected function collectScheduler()
    {
        return [
            'last_run' => Parameter::get(static::SCHEDULE_PARAMETER),
        ];
    }

    /**
     * collectTheme returns the active theme code.
     */
    protected function collectTheme()
    {
        return [
            'active' => \Cms\Classes\Theme::getActiveThemeCode(),
        ];
    }

    /**
     * collectPlugins returns plugins with their versions and state:
     *   active        - registered in the database, files present, enabled
     *   disabled      - disabled in the backend
     *   missing       - registered in the database, but the plugin directory is gone
     *   not_installed - plugin directory present, but not registered (not migrated yet)
     */
    protected function collectPlugins()
    {
        $onDisk = $this->findPluginsOnDisk();
        $plugins = [];

        foreach (Db::table('system_plugin_versions')->orderBy('code')->get() as $row) {
            $row = (array) $row;
            $key = strtolower($row['code']);

            if (!isset($onDisk[$key])) {
                $state = 'missing';
            }
            elseif (!empty($row['is_disabled'])) {
                $state = 'disabled';
            }
            else {
                $state = 'active';
            }

            unset($onDisk[$key]);

            $plugins[] = [
                'code' => $row['code'],
                'version' => $row['version'],
                'state' => $state,
                'is_disabled' => !empty($row['is_disabled']),
                'is_frozen' => !empty($row['is_frozen']),
            ];
        }

        foreach ($onDisk as $code) {
            $plugins[] = [
                'code' => $code,
                'version' => null,
                'state' => 'not_installed',
                'is_disabled' => false,
                'is_frozen' => false,
            ];
        }

        return $plugins;
    }

    /**
     * findPluginsOnDisk returns plugin codes found in the plugins directory,
     * keyed by lowercase code. The code casing is read from the Plugin.php namespace.
     */
    protected function findPluginsOnDisk()
    {
        $plugins = [];

        $files = glob(plugins_path('*/*/Plugin.php')) ?: [];

        foreach ($files as $file) {
            $author = basename(dirname(dirname($file)));
            $name = basename(dirname($file));
            $code = $author . '.' . $name;

            $head = (string) file_get_contents($file, false, null, 0, 2000);
            if (preg_match('/namespace\s+([A-Za-z0-9_]+)\\\\([A-Za-z0-9_]+)\s*;/', $head, $matches)) {
                $code = $matches[1] . '.' . $matches[2];
            }

            $plugins[strtolower($author . '.' . $name)] = $code;
        }

        return $plugins;
    }

    /**
     * collectEventLog summarizes errors from the database event log (no full traces).
     */
    protected function collectEventLog()
    {
        $query = function () {
            return Db::table('system_event_logs')->whereIn('level', static::$errorLevels);
        };

        $latest = [];
        foreach ($query()->orderBy('id', 'desc')->limit(5)->get() as $row) {
            $row = (array) $row;
            $message = strtok((string) $row['message'], "\n");
            $message = str_replace(base_path() . DIRECTORY_SEPARATOR, '', (string) $message);
            $latest[] = [
                'level' => $row['level'],
                'message' => mb_substr($message, 0, 200),
                'created_at' => $row['created_at'],
            ];
        }

        return [
            'errors_24h' => $query()->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))->count(),
            'errors_7d' => $query()->where('created_at', '>=', date('Y-m-d H:i:s', time() - 604800))->count(),
            'latest' => $latest,
        ];
    }

    /**
     * collectBackup returns the Webula.SmallBackup setup and its newest backup files. The settings are read
     * straight from system_settings, so it works without the plugin classes for 1.x (db_auto, ... booleans)
     * and 2.x (db_mode, ... = manual / schedule / trigger). The trigger key is never reported.
     */
    protected function collectBackup()
    {
        if (!is_dir(plugins_path('webula/smallbackup'))) {
            return ['installed' => false];
        }

        $settings = [];
        $value = Db::table('system_settings')->where('item', static::SMALLBACKUP_SETTINGS)->value('value');
        $decoded = $value ? json_decode($value, true) : null;
        if (is_array($decoded)) {
            $settings = $decoded;
        }

        $version = Db::table('system_plugin_versions')->where('code', 'Webula.SmallBackup')->value('version');
        $folder = isset($settings['backup_folder']) && trim((string) $settings['backup_folder']) !== ''
            ? trim((string) $settings['backup_folder'])
            : 'storage/app/backup';

        $modes = [];
        foreach (array_keys(static::$backupPrefixes) as $type) {
            $modes[$type] = static::backupMode($settings, $type);
        }

        return [
            'installed' => true,
            'version' => $version,
            'configured' => (bool) $settings,
            'folder' => $folder,
            'cleanup_days' => isset($settings['cleanup_interval']) ? (int) $settings['cleanup_interval'] : 7,
            'db_compression' => !empty($settings['db_use_compression']),
            'storage_output' => isset($settings['storage_output']) ? (string) $settings['storage_output'] : null,
            'has_trigger' => !empty($settings['trigger_key']) && in_array('trigger', $modes, true),
            'modes' => $modes,
            'files' => $this->findBackupFiles($folder),
        ];
    }

    /**
     * backupMode returns manual, schedule or trigger for one backup type (db, theme, storage).
     */
    protected static function backupMode(array $settings, $type)
    {
        if (isset($settings[$type . '_mode']) && is_string($settings[$type . '_mode'])) {
            return $settings[$type . '_mode'];
        }

        return !empty($settings[$type . '_auto']) ? 'schedule' : 'manual';
    }

    /**
     * findBackupFiles returns for each backup type the number of files, their total size and the newest file
     * (name, time of the last change, size). The folder must stay inside the application path.
     */
    protected function findBackupFiles($folder)
    {
        $path = realpath(base_path($folder));
        $root = realpath(base_path());

        if ($path === false || !is_dir($path)) {
            return ['exists' => false];
        }

        if (strpos($path . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR) !== 0) {
            throw new \RuntimeException('The backup folder is outside of the application path.');
        }

        $result = ['exists' => true];
        foreach (static::$backupPrefixes as $type => $prefix) {
            $files = glob($path . DIRECTORY_SEPARATOR . $prefix . '*') ?: [];
            $newest = null;
            $total = 0;

            foreach ($files as $file) {
                $modified = (int) @filemtime($file);
                $total += (int) @filesize($file);

                if (!$newest || $modified > $newest['modified']) {
                    $newest = ['modified' => $modified, 'file' => $file];
                }
            }

            $result[$type] = [
                'count' => count($files),
                'total_mb' => round($total / 1048576, 1),
                'newest' => $newest ? [
                    'name' => basename($newest['file']),
                    'modified_at' => date('c', $newest['modified']),
                    'size_mb' => round((int) @filesize($newest['file']) / 1048576, 1),
                ] : null,
            ];
        }

        return $result;
    }

    /**
     * collectTraffic returns the effective setup of the Internal Traffic Statistics of October 4 (Dashboard module),
     * which fill the database quickly with an unlimited retention. DashboardSetting::instance() only reads and without
     * a stored record returns the defaults (enabled, unlimited retention), the timezone falls back like
     * TrafficLogger::getTimezone(). Excluded administrator roles exist since October 4.1 (null before), admins_excluded
     * tells whether every backend user is excluded ("*" = all roles, or the role of each user). Other generations
     * report available false.
     */
    protected function collectTraffic()
    {
        $settingClass = 'Dashboard\Models\DashboardSetting';
        if (!class_exists($settingClass)) {
            return ['available' => false];
        }

        $settings = $settingClass::instance();

        $timezone = (string) $settings->traffic_stats_timezone;
        if ($timezone === '') {
            $timezone = (string) (Config::get('cms.timezone') ?: Config::get('app.timezone'));
        }

        $retention = $settings->traffic_stats_retention;

        $roles = null;
        $adminsExcluded = null;
        if (method_exists($settings, 'getFilterExcludeRolesOptions')) {
            $roles = array_values(array_map('strval', (array) $settings->filter_exclude_roles));
            $userRoles = array_map('strval', \Backend\Models\User::pluck('role_id')->all());
            $adminsExcluded = in_array('*', $roles, true) || !array_diff($userRoles, $roles);
        }

        return [
            'available' => true,
            'configured' => $settingClass::isConfigured(),
            'enabled' => (bool) $settings->traffic_stats_enabled,
            'timezone' => $timezone,
            'retention_months' => is_numeric($retention) && (int) $retention > 0 ? (int) $retention : null,
            'exclude_bots' => (bool) $settings->filter_exclude_bots,
            'exclude_roles' => $roles,
            'admins_excluded' => $adminsExcluded,
        ];
    }

    /**
     * getPackageVersion reads an installed Composer package version (Composer 2 API
     * with a fallback to installed.json for older installs), null without Composer.
     */
    protected function getPackageVersion($package)
    {
        if (class_exists('Composer\InstalledVersions') && \Composer\InstalledVersions::isInstalled($package)) {
            return \Composer\InstalledVersions::getPrettyVersion($package);
        }

        $file = base_path('vendor/composer/installed.json');
        if (!is_file($file)) {
            return null;
        }

        $installed = json_decode(file_get_contents($file), true);
        $packages = isset($installed['packages']) ? $installed['packages'] : $installed;

        foreach ((array) $packages as $item) {
            if (isset($item['name']) && $item['name'] === $package) {
                return isset($item['version']) ? $item['version'] : null;
            }
        }

        return null;
    }
}
