# Latest Form Version

This external module allows you update a singleton form from data on a form that is in one or more events.
The most common scenario is when you have a Demographics form in your project and you periodically ask participants to update their data.

When participants update their data (most likely on a survey), this EM will update 
the data on the singleton (Demographics) form. Only non-empty fields will be copied to the singleton
form unless the Force Update checkbox is selected.


## Fields required to create a configuration

Source:
1. Repeating form name (currently, all fields must reside on the same form)
1. Repeating form field list (comma-separated list)

Destination:
1. Singleton form name (currently, all fields must reside on the same form)
1. Singleton form field list (comma-separated list)

Type of update
1. Force update checkbox - when selected, all destination fields will override singleton fields even if the fields are blank.

## Assumptions
1. This EM works for forms within a project - not across projects.  Also, the destination form will update the source form in every event the destination form is enabled.


## Valid Configuration Checks

1. The destination form and fields must be consistent (form must contain fields) and it must not be a repeating form or in a repeating event.
1. The source form must be enabled in only one event and cannot be repeating.
1. There must be the same number of source form fields as there are destination form fields
1. The destination field and source fields must be declared as the same 'type' (i.e. both declared date fields with date format of Y-M-D).
