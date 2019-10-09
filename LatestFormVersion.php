<?php
namespace Stanford\LatestFormVersion;
/** @var \Stanford\LatestFormVersion\LatestFormVersion $module */

require_once("emLoggerTrait.php");
require_once("src/LatestFormVersionInstance.php");

use \REDCap;
use \Exception;

class LatestFormVersion extends \ExternalModules\AbstractExternalModule
{
    use emLoggerTrait;

    private $subSettings;   // An array of subsettings under the updates key
    private $deleteAction = null;

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * This EM takes data from a survey form in an event and updates an existing form with data into the new survey form.
     * For instance, projects can have an Update Demographic Data form in several events (ex. year 1, year 2, etc) and they want
     * that data automatically transferred to their Participant Demographic form so that the Participant Demographic form
     * is always up-to-date. This EM ensures each Update Demographic Data form is in a different event and is not a repeating
     * form or in a repeating event.
     *
     * Projects specify which data on the Update Demographic Data form will be saved and is mapped to fields on the Participant
     * Demographic form. These fields are specified in the EM Config file.  Each corresponding field must be of the same "type"
     * of field otherwise the configuration will show an error. For instance, if the Update Demographic Data form specifies that
     * an input is an email address but the Participant Demographic Form does not specify a validation type, an error will be
     * displayed.
     *
     * The basic data flow is:
     *          Update Demographic Data form            REDCap field                        Form to be updated
     *              Update Cell Phone           [event_name][update_cell_phone]     =>      [baseline_event][cell_phone]
     *              Update Email Address        [event_name][update_email_address]  =>      [baseline_event][email_address]
     *              Update Address              [event_name][update_address]        =>      [baseline_event][address]
     *              Update City                 [event_name][update_city]           =>      [baseline_event][city]
     *              Update State                [event_name][update_state]          =>      [baseline_event][state]
     */

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

    /**
     * This function will validate each instance of a config. The main checks are
     *      1) Ensure destination form being updated is only in one event
     *      2) Ensure destination fields being updated are all on the same form
     *      3) Ensure there are the same number of source and destination fields
     *      3) Ensure all source fields are on one form
     *      4) Ensure all source fields and matching destination fields are "typed" the same
     *
     * @param $instances
     * @return array
     */
    public function validateConfigs( $instances) {
        $result = true;
        $messages = [];

        $this->emDebug("In validateConfigs");

        foreach ($instances as $i => $instance) {
            $this->emDebug("In instance " . $i . ", parameters: " . json_encode($instance));
            $su = new LatestFormVersionInstance($this, $instance);
            $this->emDebug("Retrieved the instance");

            // Get result
            list($valid, $message) = $su->validateConfig();
            $this->emDebug("Returned from validConfig with status: " . $valid . ", and message: " . $message);

            // Get messages
            if (!$valid) {
                $result = false;
                $messages[] = "<b>Configuration Issues with #" . ($i+1) . "</b>" . $message;
                $this->emDebug("Invalid configuration message: ", $messages);
            } else {
                $this->emDebug("Configuration ($i+1) is valid.");
            }
        }

        //$this->emDebug("Result $result and messages: " . json_encode($messages));
        return array( $result, $messages);
    }

    /**
     *          REDCap hook functions
     */

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

    /**
     * This function will check for a delete form action.  If the user is deleting the form, don't update the destination
     * form.
     */
   function redcap_every_page_before_render() {
        if (@$_POST['submit-action'] === 'submit-btn-deleteform') {
            $this->deleteAction = 'deleteForm';
        }
       $this->emLog(PAGE, $_POST, $_GET);

   }

    /**
     * When a record is saved, check to see if this is a source form and if so, process the data to update the destination form.
     * If this form is not in a list of source forms, skip.
     *
     * @param $project_id
     * @param $record
     * @param $instrument
     * @param $event_id
     * @param $group_id
     * @param $survey_hash
     * @param $response_id
     * @param $repeat_instance
     */
   function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id,
                              $survey_hash, $response_id, $repeat_instance) {

        // Retrieve each saved config
        $instances = $this->getSubSettings('instances');
        $this->emDebug("In save record for instruement: " . $instrument);

        // Loop over all of them
        foreach ($instances as $i => $instance) {

            $this->emDebug("Check for transfer: config " . ($i+1) . " - instrument $instrument, record $record, instance $repeat_instance");

            // Only process this config if the form is the source form and the form is not being deleted
            if (($instance["source_form"] == $instrument) && ($this->deleteAction !== 'deleteForm')) {
                $this->emDebug("Continuing processing since this is a source form: " . $instrument);

                // See if this config is valid
                try {
                    $su = new LatestFormVersionInstance($this, $instance);
                    list($valid, $messages) = $su->validateConfig();

                    // If valid and this is a source form,
                    if ($valid) {
                        $this->emLog("Transferring data for config " . ($i + 1) . " - form $instrument record $record/instance $repeat_instance");
                        list($saved, $messages) = $su->transferData($record, $event_id, $instrument);
                        if ($saved) {
                            $this->emDebug("Transferred data for config " . ($i + 1) . " for record $record and instance $repeat_instance");
                        } else {
                            $this->emLog($messages);
                        }
                    } else {
                        $this->emError("Skipping data transfer for config " . ($i + 1) . " for record $record and instance $repeat_instance because config is invalid" . json_encode($instance));
                    }
                } catch (Exception $ex) {
                    $this->emError("Cannot create instance of class LatestFormVersionInstance");
                    exit;
                }
            }
        }
   }
}