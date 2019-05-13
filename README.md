# SurveyUpdates

This external module allows you update a non-repeating form from data of a repeating form.
The most common scenario is when you have a Demographics form in your project and you periodically ask participants to update their data.

When participants update their data on a repeating form or a form in a repeating event, this EM will update 
the data on the non-repeating (Demographics) form. Only non-empty fields will be copied to the non-repeating
form unless the Force Update checkbox is selected.


## Fields required to create a configuration

1. Repeating form eventID
1. Repeating form name (currently, all fields must reside on the same form)
1. Repeating form field list (comma-separated list)
1. Non-repeating form eventID
1. Non-repeating form name (currently, all fields must reside on the same form)
1. Non-repeating form field list (comma-separated list)
1. Force update checkbox - when selected, all repeating fields will override non-repeating fields even if the fields are blank.

## Assumptions
1. This EM works for forms within a project - not across projects


## Valid Configuration Checks

1. The repeating eventID, form and fields must be consistent (event must contain form and form must contain fields)
1. The non-repeating eventID, form and fields must be consistent
1. There must be the same number of repeating form fields as there are non-repeating form fields
1. The repeating fields must be declared as the same type with the same validation as the non-repeating fields
