<?php namespace Webula\Beacon\Updates;

use Db;
use October\Rain\Database\Updates\Migration;

/**
 * RemoveBackendSettings deletes the secret stored by the backend settings of Beacon 1.x,
 * the secret now lives only in the file configuration. A named class, so October CMS 1 can run it.
 */
class RemoveBackendSettings extends Migration
{
    public function up()
    {
        Db::table('system_settings')->where('item', 'webula_beacon_settings')->delete();
    }

    public function down()
    {
        // The old secret is not restored, it has to be set in the file configuration
    }
}
