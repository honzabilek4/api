<?php

namespace Directus\Console\Common;

use Directus\Application\Application;
use Directus\Console\Common\Exception\SettingUpdateException;
use Zend\Db\TableGateway\TableGateway;

class Setting
{
    private $directus_path;
    private $db;
    private $settingsTableGateway;

    public function __construct($base_path)
    {
        if ($base_path == null) {
            $base_path = base_path();
        }

        $this->directus_path = $base_path;
        $app = new Application($base_path, require $this->directus_path . '/config/api.php');
        $this->db = $app->getContainer()->get('database');

        $this->settingsTableGateway = new TableGateway('directus_settings', $this->db);
    }

    /**
     *  Check if base settings have already been defined.
     *
     *  The function return true if base settings have already been defined,
     *  false in any othe case.
     *
     * @return boolean True if settings have already been defined. False in any
     *                  other case.
     *
     */
    public function isConfigured()
    {
        try {
            $rowset = $this->settingsTableGateway->select();
            if ($rowset->count() > 0) {
                return true;
            }
            return false;
        } catch (PDOException $ex) {
            return false;
        }
    }

    /**
     *  Check if a settings has already been defined.
     *
     *  The function return true if the setting has already been defined for a collection,
     *  false in any othe case.
     *
     * @param string $collection The collection to which this setting applies.
     * @param string $setting The name of the setting to check.
     *
     * @return boolean True if setting has been defined for the collection. False in any
     *                  other case.
     *
     */
    public function settingExists($collection, $setting)
    {
        try {
            $rowset = $this->settingsTableGateway->select([
                'scope' => $collection,
                'key' => $setting
            ]);
            if ($rowset->count() > 0) {
                return true;
            }
            return false;
        } catch (PDOException $ex) {
            return false;
        }
    }

    /**
     *  Creates a setting and sets its value for Directus.
     *
     *  The function will create the given setting with the passed value.
     *
     * @param string $collection The collection to which this setting applies.
     * @param string $setting The name of the setting to create.
     * @param string $value The value of the setting.
     *
     * @return void
     *
     * @throws SettingUpdateException Thrown when the creation of the setting fails.
     *
     */
    public function createSetting($collection, $setting, $value)
    {

        $insert = [
            'scope' => $collection,
            'key' => $setting,
            'value' => $value
        ];

        try {
            $this->settingsTableGateway->insert($insert);
        } catch (\PDOException $ex) {
            throw new SettingUpdateException('Could not create setting ' . $collection . '.' . $setting . ': ' . 'PDO Error: ' . string($ex));
        }

    }

    /**
     *  Sets the value of a setting for Directus, creating it if needed.
     *
     *  The function will change the given setting to the passed value if it already
     *  exists and will create it if it doesn't.
     *
     * @param string $scope The collection to which this setting applies.
     * @param string $setting The name of the setting to change.
     * @param string $value The value of the setting.
     *
     * @return void
     *
     * @throws SettingUpdateException Thrown when the changing the setting fails.
     *
     */
    public function setSetting($scope, $setting, $value)
    {

        if (!$this->settingExists($scope, $setting)) {
            return $this->createSetting($scope, $setting, $value);
        }

        $update = [
            'value' => $value
        ];

        try {
            $this->settingsTableGateway->update($update, [
                'scope' => $scope,
                'key' => $setting
            ]);
        } catch (\PDOException $ex) {
            throw new SettingUpdateException('Could not change setting ' . $scope . '.' . $setting . ': ' . 'PDO Error: ' . string($ex));
        }
    }

}
