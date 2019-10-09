<?php
namespace Stanford\LatestFormVersion;
/** @var \Stanford\LatestFormVersion\LatestFormVersion $module */


// HANDLE BUTTON ACTION
if (empty($_POST['action'])) exit();

$action = $_POST['action'];
$module->emDebug("Action: " . $action);

$message = $delay = $callback = null;

// fix raw data

// static function formatRawSettings($moduleDirectoryPrefix, $pid, $rawSettings){

switch ($action) {
    case "getStatus":
        $raw = $_POST['raw'];
        $data = \ExternalModules\ExternalModules::formatRawSettings($module->PREFIX, $module->getProjectId(), $raw);
        // At this point we have the settings in individual arrays for each value.  The equivalent to ->getProjectSettings();


        // For this module, we want the subsettings of 'instance' - the repeating block of config
        $module->emDebug("Raw instance data: " . json_encode($data));
        $instances = $module->parseSubsettingsFromSettings('instances', $data);
        // $module->emDebug("formatted instances: " .  json_encode($instances));

        // foreach ($instances as $k => $v) {
        //     foreach ($v as $key => $val) {
        //         $module->emDebug($key, $val);
        //     }
        // }
        // $module->emDebug("formatted settings: ", $data);

        list($result,$message) = $module->validateConfigs( $instances );
        $module->emDebug("Return from validateConfigs: " . $action . ", result: " . $result . ", message: " . json_encode($message));
        break;
}

header('Content-Type: application/json');

echo json_encode(
    array(
        'result'   => $result,
        'message'  => $message,
        'callback' => $callback,
        'delay'    => $delay
    )
);


exit();
