<?php
namespace Stanford\SurveyUpdates;

use \REDCap;

/**
 * Class SurveyUpdatesInstance
 * @package Stanford\SurveyUpdates
 *
 * This class validates each Survey Update configuration entered by users for their project.
 * Once the entered form/field lists are validated, it will save the specified field values
 * in the repeating form to the fields in the non-repeating form on save.
 *
 */
class SurveyUpdatesInstance
{
    /** @var \Stanford\SurveyUpdates\SurveyUpdates $module */
    private $module;

    // Determines which fields should be included in the summarize block
    private $repeat_eventid;
    private $repeat_form;
    private $repeat_fields;
    private $destination_eventid;
    private $destination_form;
    private $destination_fields;
    private $overwrite;
    private $pid;

    public function __construct($module, $instance)
    {
        $this->module = $module;

        $this->repeat_eventid       = $instance['repeat_event_id'];
        $this->repeat_form          = $instance['repeat_form'];
        $this->repeat_fields        = $this->parseConfigList( $instance['repeat_fields'] );
        $this->destination_eventid  = $instance['destination_event_id'];
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
     *  1) It validates the fields on the repeating form
     *  2) It validates the fields on the non-repeating form
     *  3) It verifies that there are the same number of fields on the repeating form
     *     as the non-repeating form
     *  4) It verifies that the fields are the same type on both forms
     *
     * @return array  --
     *         valid - true/false - the configuration is valid
     *         message - when valid is false, the message of why the configuration is not valid
     */
    public function validateConfig() {

        // First verify the repeating form fields/event
        list($valid, $message) = $this->checkFormsFields($this->repeat_eventid,
            $this->repeat_form, $this->repeat_fields, true);
        if (!$valid) {
            return array($valid, $message);
        }

        // Then, perform the same checks on the destination fields/event
        list($valid, $message) = $this->checkFormsFields($this->destination_eventid,
            $this->destination_form, $this->destination_fields, false);

        if ($valid) {
            // Make sure there are the same number of source and destination fields
            if (count($this->repeat_fields) !== count($this->destination_fields)) {
                $valid = false;
                $message = "<li>There must be the same number of sources fields (" . count($this->repeat_fields) .
                    ") and destination fields (" . count($this->destination_fields) . ")</li>";
            }

            // The last check is to make sure the source and destination fields are of the same type
            if (empty($message)) {
                $nonMatchingFields = array();
                for ($ncnt = 0; $ncnt < count($this->repeat_fields); $ncnt++) {
                    $repeatFieldType = $this->Proj->metadata[$this->repeat_fields[$ncnt]]['element_type'];
                    $repeatFieldValidation = $this->Proj->metadata[$this->repeat_fields[$ncnt]]['element_validation_type'];
                    $destFieldType = $this->Proj->metadata[$this->destination_fields[$ncnt]]['element_type'];
                    $destFieldValidation = $this->Proj->metadata[$this->destination_fields[$ncnt]]['element_validation_type'];
                    if (($repeatFieldType !== $destFieldType) || ($repeatFieldValidation !== $destFieldValidation)) {
                        $nonMatchingFields[] = $this->repeat_fields[$ncnt] . " => " . $this->destination_fields[$ncnt];
                    }
                }

                if (!empty($nonMatchingFields)) {
                    $valid = false;
                    $message = "<li>These field types do not match: " . implode(',', $nonMatchingFields) . "</li>";
                }
            }
        }

        //$this->module->emDebug("Valid: $valid, and message: " . $message);
        return array($valid, $message);
    }

    /**
     * This function ensures that the form is in the specified event and that the specified fields
     * are on the specified form. This function will also verify that the form is repeating or
     * non-repeating as specified.
     *
     * @param $event_id - Selected event ID in the configuration
     * @param $form - Selected form in the configuration
     * @param $fields - Selected fields in the configuration
     * @param $repeat - true/false - depending if form is repeating or not
     * @return array -
     *         valid - true/false - if form inputs are valid
     *         message - reason why inputs are not valid when false
     */
    private function checkFormsFields($event_id, $form, $fields, $repeat) {

        $valid = true;
        $message = '';

        // Make sure the form is valid in the event_id
        $formsInEvents = $this->Proj->eventsForms[$event_id];
        $status = in_array($form, $formsInEvents);
        if (!$status) {
            $message = "<li>Form $form is not in event $event_id</li>";
            return array($status, $message);
        }

        // Make sure all the fields are on the form
        $fieldsOnForm = array_keys($this->Proj->forms[$form]['fields']);
        $arrayDiff = array_diff($fields, $fieldsOnForm);
        if (!empty($arrayDiff)) {
            $message = "<li>These fields are not on form $form: " . implode(',', $arrayDiff) . "</li>";
            return array(false, $message);
        }

        // Make sure the form is repeating - can be a repeating form or a form in a repeating event
        $repeating = $this->Proj->RepeatingFormsEvents[$event_id];

        if ($repeating === 'WHOLE' || in_array($form, array_keys($repeating))) {
            // This form is repeating - should it be?
            if (!$repeat) {
                $message = "<li>This form $form is repeating but should not be for pid $this->pid</li>";
            }
        } else {
            // This form is not repeating - should it be?
            if ($repeat) {
                $message = "<li>This form $form is not repeating but should be for pid $this->pid</li>";
            }
        }

        //$this->module->emLog("Valid: " . $valid . ", Message: " . $message);
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
    public function transferData($record, $instrument, $repeat_instance)
    {
        $this->module->emLog("In transferData: record $record, instrument $instrument," .
            "repeat instance $repeat_instance event_id " . $this->repeat_eventid .
            " repeating event " . $this->Proj->isRepeatingEvent($this->repeat_eventid) .
            " repeating form " . $this->Proj->isRepeatingForm($this->repeat_eventid, $this->repeat_form));
        $saved = true;
        $errors = '';
        // Retrieve the data from the repeating form
        $data = REDCap::getData('array', $record, $this->repeat_fields, $this->repeat_eventid);

        if ($this->Proj->isRepeatingEvent($this->repeat_eventid)) {
            $repeat_data = $data[$record]["repeat_instances"][$this->repeat_eventid][""][$repeat_instance];
        } else {
            $repeat_data = $data[$record]["repeat_instances"][$this->repeat_eventid][$instrument][$repeat_instance];
        }

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