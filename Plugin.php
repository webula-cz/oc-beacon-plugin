<?php namespace Webula\Beacon;

use System\Classes\PluginBase;
use System\Models\Parameter;
use Webula\Beacon\Classes\StatusCollector;

/**
 * Beacon Plugin - read-only status endpoint for the Webula Lighthouse hub.
 *
 * Written to run on October CMS 1 (Laravel 5.5, PHP 7.0) up to October CMS 4,
 * so it avoids newer PHP syntax (no nullsafe, match, arrow functions, typed properties).
 *
 * The secret is file-based only (config override or .env), never editable in the backend.
 *
 * @link https://docs.octobercms.com/4.x/extend/system/plugins.html
 */
class Plugin extends PluginBase
{
    /**
     * pluginDetails about this plugin.
     */
    public function pluginDetails()
    {
        return [
            'name' => 'webula.beacon::lang.plugin.label',
            'description' => 'webula.beacon::lang.plugin.comment',
            'author' => 'Webula',
            'icon' => 'icon-heartbeat',
            'homepage' => 'https://www.webula.cz',
        ];
    }

    /**
     * registerSchedule writes a heartbeat, so the hub can tell whether cron runs on the site.
     */
    public function registerSchedule($schedule)
    {
        $schedule->call(function () {
            Parameter::set(StatusCollector::SCHEDULE_PARAMETER, date('c'));
        })->everyFiveMinutes();
    }
}
