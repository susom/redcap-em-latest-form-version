<?php
namespace Stanford\LatestFormVersion;

use \REDCap;

/**
 * Class LatestFormVersionInstance
 * @package Stanford\LatestFormVersion
 *
 * This class validates each Survey Update configuration entered by users for their project.
 * Once the entered form/field lists are validated, it will save the specified field values
 * in the source form to the fields in the destination form on save.
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
    private $destination_event_id;
    private $overwrite;
    private $pid;
    private $repeat_instance;

    public function __construct($module, $instance, $repeat_instance)
    {
        $this->module = $module;

        $this->source_form          = $instance['source_form'];
        $this->source_fields        = $this->parseConfigList( $instance['source_fields'] );
        $this->destination_form     = $instance['destination_form'];
        $this->destination_fields   = $this->parseConfigList( $instance['destination_fields'] );
        $this->overwrite            = $instance['overwrite'];
        $this->repeat_instance      = $repeat_instance;

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
     * This function validates the config.  It performs the following checks:
     *  1) It validates that all the source fields are on the source form and the source form is not repeating
     *  2) It validates that all destination fields are on the destination form and that the destination form
     *     if not repeating and only in one event.
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

        // Find the destination form eventID
        foreach($this->Proj->eventsForms as $eventID => $formList) {
            if (in_array($this->destination_form, $formList)) {
                $this->destination_event_id = $eventID;
            }
        }

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

        return array($valid, $message);
    }

    /**
     * This function ensures that the specified fields are on the specified form. If the singleton input is true,
     * the form is checked to ensure it is only in one event.  It also checks to make sure the form is not repeating
     * or in a repeating event.
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
            $this->module->emDebug($message);
            return array(false, $message);
        }

        // Make sure none of the fields in the list are checkboxes
        foreach($fields as $eachField => $fieldInfo) {
            if ($this->Proj->metadata[$fieldInfo]['element_type'] === "checkbox") {
                $message = "<li>Checkboxes are not supported [$fieldInfo]. Please remove the checkbox(es) from the fieldlist.</li>";
                $this->module->emDebug($message);
                return array(false, $message);
            }
        }

        // Get the list of eventIDs that this form belongs to
        $eventIDList = array();
        foreach($this->Proj->eventsForms as $event_id => $form_list) {
            if (in_array($form, $form_list)) {
                $eventIDList[] = $event_id;
            }
        }

        // Make sure this form is not a repeating form or on a repeating event
        if ($this->Proj->hasRepeatingForms()) {

            // Next check to see if the form is repeating or in a repeating event
            $repeatingEvents = array_keys($this->Proj->RepeatingFormsEvents);
            foreach($repeatingEvents as $eventID) {

                if ($this->Proj->RepeatingFormsEvents[$eventID] === "WHOLE") {
                    // This is a repeating event so send an error
                    $message = "<li>The form $form cannot be in a repeating event.</li>";
                    $this->module->emDebug("EventID $eventID: " . $message);
                    return array(false, $message);
                } else if (!empty($this->Proj->RepeatingFormsEvents[$eventID])) {
                    $repeatingForms = $this->Proj->RepeatingFormsEvents[$eventID];
                    if (in_array($form, $repeatingForms)) {
                        // This is a repeating form so send an error
                        $message = "<li>The form $form cannot be a repeating form.</li>";
                        return array(false, $message);
                    }
                }
            }
        }

        // If this is suppose to be a singleton form, make sure there is one and only one
        if ($singleton && (count($eventIDList) != 1)) {
            $message = "<li>The form $form must be in one and only one event but belongs to " . count($eventIDList) . ".</li>";
            $this->module->emDebug($message);
            return array(false, $message);
        }

        return array($valid, $message);
    }

    /**
     * This function will copy the data on the repeating form to the non-repeating form when there
     * is data in the repeating form fields or if the force overwrite checkbox is selected.
     *
     * @param $record
     * @param $event_id
     * @param $instrument
     * @return array
     */
    public function transferData($record, $event_id, $instrument)
    {
        $this->module->emDebug("In transferData: record $record, instrument $instrument, event $event_id, repeat_instance: " . $this->repeat_instance);
        $saved = true;
        $errors = '';

        // Retrieve the data from the source form
        $data = REDCap::getData('json', $record, $this->source_fields, $event_id);
        $source_field_values = json_decode($data, true);
        $instance_field_values = $source_field_values[$this->repeat_instance-1];

        $new_data = array();
        for ($ncnt=0; $ncnt < count($this->source_fields); $ncnt++) {

            // Only save if the field has a value or if the force overwrite checkbox is selected
            $value = $instance_field_values[$this->source_fields[$ncnt]];
            if (($value !== '') || $this->overwrite) {
                $new_data[$this->destination_fields[$ncnt]] = $value;
            }
        }

        $saveData[$record][$this->destination_event_id] = $new_data;
        // $this->module->emDebug("This is the data to save: " .json_encode($saveData));
        $return = REDCap::saveData('array', $saveData, 'overwrite');
        if (!empty($return["errors"])) {
            $saved = false;
            $errors = "Error saving data in form $this->destination_form from form $this->source_form for record $record and eventID $event_id: " . json_encode($return["errors"]);
            $this->module->emError($errors);
        } else {
            $this->module->emDebug("Saving data for record $record, instrument $instrument from event $event_id");
        }

        return array($saved, $errors);
    }

}