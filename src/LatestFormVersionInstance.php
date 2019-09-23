<?php
namespace Stanford\LatestFormVersion;

use \REDCap;

/**
 * Class LatestFormVersionInstance
 * @package Stanford\LatestFormVersion
 *
 * This class validates each Survey Update configuration entered by users for their project.
 * Once the entered form/field lists are validated, it will save the specified field values
 * in the repeating form to the fields in the non-repeating form on save.
 *
 */
class LatestFormVersionInstance
{
    /** @var \Stanford\LatestFormVersion\LatestFormVersion $module */
    private $module;

    // Determines which fields should be included in the summarize block
    private $source_form;
    private $source_fields;
    private $destination_form;
    private $destination_fields;
    private $overwrite;
    private $pid;

    public function __construct($module, $instance)
    {
        $this->module = $module;

        $this->source_form          = $instance['source_form'];
        $this->source_fields        = $this->parseConfigList( $instance['source_fields'] );
        $this->destination_form     = $instance['destination_form'];
        $this->destination_fields   = $this->parseConfigList( $instance['destination_fields'] );
        $this->overwrite            = $instance['overwrite'];

        global $Proj;
        $this->Proj = $Proj;
        $this->Proj->setRepeatingFormsEvents();
        $this->pid = $Proj->project_id;
    }

    /**
     * This function splits the configuration into an array
     *
     * @param $list - original list
     * @return array - split array
     */
    private function parseConfigList($list) {
        $listArray = array();
        $lists = preg_split('/\W/', $list, 0, PREG_SPLIT_NO_EMPTY);
        foreach ($lists as $oneEntry) {
            $listArray[] = trim($oneEntry);
        }
        return $listArray;
    }

    /**
     * This function validates the surveyUpdate config.  It performs the following checks:
     *  1) It validates the fields on the source form
     *  2) It validates the fields on the destination form
     *  3) It verifies that there are the same number of fields on the source and destination forms
     *  4) It verifies that the fields are the same type on both forms
     *
     * @return array  --
     *         valid - true/false - the configuration is valid
     *         message - when valid is false, the message of why the configuration is not valid
     */
    public function validateConfig() {

        // First verify the source form/field
        list($valid, $message) = $this->checkFormsFields($this->source_form, $this->source_fields, false);
        if (!$valid) {
            return array($valid, $message);
        }

        // Then, perform the same checks on the destination fields/event
        list($valid, $message) = $this->checkFormsFields($this->destination_form, $this->destination_fields, true);

        if ($valid) {
            // Make sure there are the same number of source and destination fields
            if (count($this->source_fields) !== count($this->destination_fields)) {
                $valid = false;
                $message = "<li>There must be the same number of sources fields (" . count($this->source_fields) .
                    ") and destination fields (" . count($this->destination_fields) . ")</li>";
            }

            // The last check is to make sure the source and destination fields are of the same type
            if (empty($message)) {
                $nonMatchingFields = array();
                for ($ncnt = 0; $ncnt < count($this->source_fields); $ncnt++) {
                    $repeatFieldType = $this->Proj->metadata[$this->source_fields[$ncnt]]['element_type'];
                    $repeatFieldValidation = $this->Proj->metadata[$this->source_fields[$ncnt]]['element_validation_type'];
                    $destFieldType = $this->Proj->metadata[$this->destination_fields[$ncnt]]['element_type'];
                    $destFieldValidation = $this->Proj->metadata[$this->destination_fields[$ncnt]]['element_validation_type'];
                    if (($repeatFieldType !== $destFieldType) || ($repeatFieldValidation !== $destFieldValidation)) {
                        $nonMatchingFields[] = $this->source_fields[$ncnt] . " => " . $this->destination_fields[$ncnt];
                    }
                }

                if (!empty($nonMatchingFields)) {
                    $valid = false;
                    $message = "<li>These field types do not match: " . implode(',', $nonMatchingFields) . "</li>";
                }
            }
        }

        $this->module->emDebug("Valid: $valid, and message: " . $message);
        return array($valid, $message);
    }

    /**
     * This function ensures that the specified fields are on the specified form. If the singleton input is true,
     * the form is checked to ensure it is only in one event.
     *
     * @param $form - Selected form in the configuration
     * @param $fields - Selected fields in the configuration
     * @return array -
     *         valid - true/false - if form inputs are valid
     *         message - reason why inputs are not valid when false
     */
    private function checkFormsFields($form, $fields, $singleton=false) {

        $valid = true;
        $message = '';

        // Make sure all the fields are on the form
        $fieldsOnForm = array_keys($this->Proj->forms[$form]['fields']);
        $arrayDiff = array_diff($fields, $fieldsOnForm);
        if (!empty($arrayDiff)) {
            $message = "<li>These fields are not on form $form: " . implode(',', $arrayDiff) . "</li>";
            return array(false, $message);
        }

        // If this is suppose to be a singleton form, make sure it is only in one event
        if ($singleton) {
            $included_in_num_events = 0;
            foreach($this->Proj->eventsForms as $event_id => $form_list) {
                if (in_array($form, $form_list)) {
                    $included_in_num_events += 1;
                }
            }
            if ($included_in_num_events != 1) {
                $message = "<li>The form $form must be in one and only one event.</li>";
                return array(false, $message);
            }
        }

        return array($valid, $message);
    }

    /**
     * This function will copy the data on the repeating form to the non-repeating form when there
     * is data in the repeating form fields or if the force overwrite checkbox is selected.
     *
     * @param $record
     * @param $instrument
     * @param $repeat_instance
     * @return array
     */
    public function transferData($record, $event_id, $instrument)
    {
        $this->module->emDebug("In transferData: record $record, instrument $instrument");
        $saved = true;
        $errors = '';

        // Retrieve the data from the source form
        $data = REDCap::getData('array', $record, $this->source_fields, $event_id);
        $this->module->emDebug("Retrieve data from getData: " . json_encode($data));
        $this_record = $data[$record];
        $repeat_key = array_keys($this_record);
        //if ($repeat_key == "repeat_instances") {
            if ($this->Proj->isRepeatingEvent($this->repeat_eventid)) {
                $repeat_data = $data[$record]["repeat_instances"][$this->repeat_eventid][""][$repeat_instance];
            } else {
                $repeat_data = $data[$record]["repeat_instances"][$this->repeat_eventid][$instrument][$repeat_instance];
            }
        //} else {
        //    $repeat_data = $data[$record][$this->repeat_eventid];
        //}

        $new_data = array();
        for ($ncnt=0; $ncnt < count($this->repeat_fields); $ncnt++) {

            // Only save if the field has a value or if the force overwrite checkbox is selected
            $value = $repeat_data[$this->repeat_fields[$ncnt]];
            if (($value !== '') || $this->overwrite) {
                $new_data[$this->destination_fields[$ncnt]] = $value;
            }
        }

        $saveData[$record][$this->destination_eventid] = $new_data;
        $return = REDCap::saveData('array', $saveData, 'overwrite');
        if (!empty($return["errors"])) {
            $saved = false;
            $errors = "Error saving summarize block: " . json_encode($return["errors"]);
            $this->module->emError($errors);
        } else {
            $this->module->emDebug("Saving data for record $record, instrument $instrument, repeat instance $repeat_instance", $saveData);
        }

        return array($saved, $errors);
    }

}