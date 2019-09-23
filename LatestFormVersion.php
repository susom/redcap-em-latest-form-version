<?php
namespace Stanford\LatestFormVersion;
/** @var \Stanford\LatestFormVersion\LatestFormVersion $module */

require_once("emLoggerTrait.php");
require_once("src/LatestFormVersionInstance.php");

use \REDCap;

class LatestFormVersion extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    private $subSettings;   // An array of subsettings under the updates key
    private $deleteAction = null;

    public function __construct()
    {
        parent::__construct();
    }

    // Converts an array of key => array[values(0..n)] into an array of [i] => [key] => value where i goes from 0..n
    // For example:
    // [
    //   [f1] =>
    //          [
    //              0 => "first",
    //              1 => "second"
    //          ],
    //   [f2] =>
    //          [
    //              0 => "primero",
    //              1 => "secondo"
    // ]
    // gets converted into:
    // [
    //    0   =>
    //          [
    //              "f1" => "first",
    //              "f2" => "primero"
    //          ],
    //    1   =>
    //          [
    //              "f1" => "second",
    //              "f2" => "secondo"
    // ]

    /**
     * This function takes the settings for each Summarize configuration and rearranges them into arrays of subsettings
     * instead of arrays of key/value pairs. This is called from javascript so each configuration
     * can be verified in real-time.
     *
     * @param $key - JSON key where the subsettings are stored
     * @param $settings - retrieved list of subsettings from the getProjectSettings function
     * @return array - the array of subsettings for each configuration
     */
    public function parseSubsettingsFromSettings($key, $settings) {
        $config = $this->getSettingConfig($key);
        if ($config['type'] !== "sub_settings") return false;

        // Get the keys that are part of this subsetting
        $keys = [];
        foreach ($config['sub_settings'] as $subSetting) {
            $keys[] = $subSetting['key'];
        }

        // Loop through the keys to pull values from $settings
        $subSettings = [];
        foreach ($keys as $key) {
            $values = $settings[$key];
            foreach ($values as $i => $value) {
                $subSettings[$i][$key] = $value;
            }
        }
        return $subSettings;
    }

    public function validateConfigs( $instances) {
        $result = true;
        $messages = [];

        foreach ($instances as $i => $instance) {
            $su = new LatestFormVersionInstance($this, $instance);

            // Get result
            list($valid, $message) = $su->validateConfig();

            // Get messages
            if (!$valid) {
                $result = false;
                $messages[] = "<b>Configuration Issues with #" . ($i+1) . "</b>" . $message;
                $this->emDebug("Invalid configuration message: ", $messages);
            }
        }

        //$this->emDebug("Result $result and messages: " . json_encode($messages));
        return array( $result, $messages);
    }

    /**
     * When the Project Settings are saved, this hook will be called to validate each configuration and possibly update each
     * record with a force update.
     *
     * @param $project_id - this is the standard parameter for this hook but since we are in project context, we don't use it
     */
    function redcap_module_save_configuration($project_id) {
        $instances = $this->getSubSettings('instance');
        list($results, $errors) = $this->validateConfigs($instances);

        $this->emDebug("On SAVE", $results, $errors);
   }

   function redcap_every_page_before_render() {
        if (@$_POST['submit-action'] === 'submit-btn-deleteform') {
            $this->deleteAction = 'deleteForm';
        }
       $this->emLog(PAGE, $_POST, $_GET);

   }

   function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id,
                              $survey_hash, $response_id, $repeat_instance) {

        // Retrieve each saved config
        $instances = $this->getSubSettings('instances');

        // Loop over all of them
        foreach ($instances as $i => $instance) {

            $this->emDebug("Check for transfer: config " . ($i+1) . " - instrument $instrument, record $record, instance $repeat_instance");
            // Only process this config if the event is the repeating event and if the
            // form is the repeating form and the form is not being deleted
            if (($instance["repeat_event_id"] == $event_id) &&
                ($instance["repeat_form"] == $instrument) &&
                ($this->deleteAction !== 'deleteForm')) {

                // See if this config is valid
                $su = new LatestFormVersionInstance($this, $instance);
                list($valid, $messages) = $su->validateConfig();

                // If valid put together the summarize block and save it for this record
                if ($valid) {
                    $this->emLog("Transferring data for config " . ($i+1) . " - form $instrument record $record/instance $repeat_instance");
                    list($saved, $messages) = $su->transferData($record, $event_id, $instrument);
                    if ($saved) {
                        $this->emDebug("Transferred data for config " . ($i+1) . " for record $record and instance $repeat_instance");
                    } else {
                        $this->emLog($messages);
                    }
                } else {
                    $this->emError("Skipping data transfer for config " . ($i+1) . " for record $record and instance $repeat_instance because config is invalid" . json_encode($instance));
                }
            }
        }
    }

}