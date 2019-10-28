# Latest Form Version

This external module allows you update a singleton form (destination) from data that is on a different form in one or more events (source).
The most common scenario is when you have a Demographics form in your project that you always want up-to-date 
and you periodically ask participants to update their demographics data in a survey.

When participants update their data in the periodic form, this EM will update 
the data on the singleton (Demographics) form from the survey data. Only non-empty fields will be copied to the singleton
form unless the Force Update checkbox is selected.

All data types except checkboxes are supported. An error will be displayed at the top of the configuration if checkboxes are selected for update. 
Since it cannot be determined if a checkbox was unchecked or just left blank, this module excludes the use of them.

## Fields required to create a configuration

Source:
1. Form name in one or more events (currently, all fields must reside on the same form). This is the source form.
1. Field list (comma-separated list) which all reside on the same source form.

Destination:
1. Singleton form name (currently, all fields must reside on the same form)
1. Singleton form field list (comma-separated list)

Type of update
1. Force update - when selected, all source fields will overwrite the destination fields even if the source fields are blank.

## Assumptions
1. This EM works for forms within a project - not across projects.  Also, the source form(s) will update the destination form in every event
 the source form is enabled.  Also, a save will occur every time the source form is saved.  
<div><b>** Care must be taken if the source form needs to be edited. **</b></div>
 

## Valid Configuration Checks

1. All source fields must reside on the source form and it must not be a repeating form or in a repeating event.
1. All destination fields must reside on the destination form must be enabled only in one event and cannot be repeating.
1. There must be the same number of source fields as there are destination fields
1. Source fields and destination fields must be declared as the same 'type' (i.e. both declared date fields with the same date format).
1. Source fields cannot be checkboxes.
