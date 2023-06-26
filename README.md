********************************************************************************
#Extended Reports

Luke Stevens, Murdoch Children's Research Institute https://www.mcri.edu.au
[https://github.com/lsgs/redcap-extended-reports](https://github.com/lsgs/redcap-extended-reports)
********************************************************************************
## Summary of Functionality

Provides some extensions & additional functionality for REDCap's built-in Data Exports & Reports functionality.
- Row per record output for reports with longitudinal data or data from repeating instruments.
- Custom SQL output (administrator configuration only)

********************************************************************************
## Row-Per-Record Results
### Configuration
The module enables REDCap's standard row-per-record-per-event and row-per-record-per-instance results to be collapsed to row-per-record.

#### Events
Options for reshaping event rows to columns are:
* Reshape by event then field (default): by event, each field e.g. event_1_arm_1.var1, event_1_arm_1.var2, event_2_arm_1.var1, event_2_arm_1.var2
* Reshape by field then event: by field, each event e.g. event_1_arm_1.var1, event_2_arm_1.var1, event_1_arm_1.var2, event_2_arm_1.var2 

#### Repeating Events/Instruments
Options for handling repeating data - repeating events or repeating instruments - are:
* Column per instance (default): the maximum number of instances will be determined and data from each instance will be returned in its own set of columns.
* First instance: one column per field containing only the value from the lowest numbered instance from each record.
* Last instance: one column per field containing only the value from the highest numbered instance from each record. 
* Lowest value: one column per field containing only the lowest value across all instances from each record.
* Highest value: one column per field containing only the highest value across all instances from each record. 
* Concatenate (space/comma/pipe): one column per field containing all values from each record concatenated into a single string value using the selected character.

With the "Column per instance" option, field names will incorporate event and instance number information in a dot- or space-separated form as follows:
* Raw CSV, repeating event: event_unique_name.instance.fieldname
* Raw CSV, repeating instrument: event_unique_name.fieldname.instance
* Labels CSV, repeating event: EventLabel\[ ArmLabel\] #Instance.FieldLabel
* Labels CSV, repeating instrument: EventLabel\[ ArmLabel\] FieldLabel #Instance
(Arm label included only if project has multiple arms)

### Exports
No row-per-record collapsing is applied to stats package or ODM exports.

### "Longitudinal Reports" Plugin
The functionality of this external module differs in some respects from the old (pre-external module framework) "Longitudinal Reports" plugin. For example, this module does not allow you full control of the order of fields, or include schedule dates or survey links as selectable options (you can create fields for these using smart variables if you need them in reports), whereas the old plugin does not integrate fully with REDCap's regular reports for all types of export, including API, nor offer customised reproting with SQL reports. The external module renders the plugin obsolete.

********************************************************************************
## SQL Reports 
### Configuration
Selecting the "Custom SQL Query" option after entering a report's title and description causes the field/event/filter/sort sections of the report configuration page to be hidden and replaced with a text area into which administrators may enter a custom SQL query.

Notes:
* Report View/Edit sections remain available and control report access in the usual way. "Edit" permission enables the ability to edit the report title, description and access permissions, not the SQL.
* Smart variables such as `[user-name]`, `[user-dag-name]`, `[user-role-name]`, `[project-id]` etc. can be utilised within the SQL (include quotes for string values where appropriate).
* You can mimic separate field name/field label behaviour by having your SQL return a column name containing a `$` character. For example:
  * `select record as record_id$Record, ...` gives a field name of `record_id` and field label "Record".
  * `select record as 'person_id$Person ID', ...` gives a field name of `person_id` and field label "Person ID" (single-quotes are required where your label contains spaces).
* If your project contains DAGs, and an SQL query returns a column named with the project's record id, then default behaviour is to apply DAG filtering of results, i.e. users will see only records from their own DAG. This can be overridden either by returning the column with a different name, or by selecting the checkbox.
* It is not possible to convert a non-SQL report into an SQL report. Just create a new one.

### Exporting SQL Reports 
SQL reports are exported only in CSV format via the Data Exports Reports & Stats page, but are additionally available in JSON and XML form via the API.

### Permissions 
SQL reports can be created and have their SQL edited only by REDCap administrators. Report title, description and visibility are editable by regular users in the usual way.
********************************************************************************