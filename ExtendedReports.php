<?php
/**
 * REDCap External Module: ExtendedReports
 * Extensions & additional functionality for REDCap's built in Data Exports & Reports functionality.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\ExtendedReports;

use ExternalModules\AbstractExternalModule;
use DataExport;
use Exception;
use Piping;
use Project;
use RCView;
use REDCap;
use RestUtility;
use UIState;
use User;

class ExtendedReports extends AbstractExternalModule
{
    protected const SQL_OPTION_TD0_HTML = '<b><i class="fas fa-th mr-1"></i>Custom SQL Query</b><div class="wrap" style="color:#888;font-size:11px;line-height:12px;margin-top:5px;">Administrators only<br>SELECT queries only</div>';
    protected const SQL_OPTION_TD1_HTML_ADMIN = '<input id="rpt-sql-check" type="checkbox" class="mb-1" /><div id="rpt-sql-block"><textarea name="rpt-sql" id="rpt-sql" class="x-form-field notesbox" style="height:45px;font-size:12px;width:99%;"></textarea><div style="line-height:11px;"><div class="float-right"><a href="javascript:;" tabindex="-1" class="expandLink" id="rpt-sql-expand">Expand</a>&nbsp;&nbsp;</div><div class="float-left" style="color:#888;font-size:11px;line-height:12px;font-weight:normal;"><div class="font-weight-bold">Smart Variables</div><div class="pl-2">User-level and system-level smart variables may be utilised. Pay attention to the data type and quote strings as required.<br>E.g. <span style="font-family:monospace;">select [project-id] as pid, \\\'[user-name]\\\' as user;</span></div><div class="font-weight-bold">Data Access Groups</div><div class="pl-2">If your query returns a column named the same as the project\\\'s record id column then the rows will be filtered by the user\\\'s DAG (if applicable).<br>If you wish to avoid DAG filtering, have your SQL return the column renamed to something else.</div></div></div></div>';
    protected const SQL_OPTION_TD1_HTML_PLEB = '<span style="font-size: large;" class="text-muted">MAG<i class="fas fa-magic"></i>C</span>';
    protected const DEFAULT_CSV_DELIMITER = ',';
    protected const DEFAULT_CSV_LINE_END = PHP_EOL;
    protected const DEFAULT_ESCAPE_CHAR = '"';
    protected const DEFAULT_DECIMAL_CHAR = '.';
    protected static $csvInjectionChars = array("-", "@", "+", "=");

    /**
     * redcap_every_page_before_render($project_id=null)
     * Catch a report view or export and if there's some "extended" config apply the necessary tweaks.
     */
    public function redcap_every_page_before_render($project_id=null) {
        global $user_rights;
        // $a = 'what does new report save look like?'; // report_edit_ajax.php $_GET report_id=0 &_POST: __TITLE__ description rpt-sql field(array) etc.
        // $b = 'what does existing report save look like?'; // report_edit_ajax.php $_GET: report_id &_POST: __TITLE__ description rpt-sql field(array) etc.
        // $c = 'what does viewing a report look like?'; // report_ajax.php $_GET: pagenum $_POST: report_id Return list ($report_table, $num_results_returned)
        // $d = 'what does downloading a report look like?'; // data_export_ajax.php $_POST: export_format report_id
        // $e = 'what does an api download of a report look like?'; // PAGE=='api/index.php' && API==true:  no project context, need to do separate api call to verify token & permissions and process json result according to extension attributes


        if (PAGE=='DataExport/report_edit_ajax.php' && count($_POST)) {
            // saving a new report -> save custom settings using incremented max redcap_reports.report_id ($_GET['report_id']==0)
            // saving existing report -> save custom settings using report_id = $_GET['report_id']

            // check whether $_POST has any extended attributes to save
            foreach(array_keys($_POST) as $key) {
                if(strpos($key, 'rpt-')===0 && !empty($_POST[$key])) {
                    try {
                        $this->saveReport(\htmlspecialchars($_GET['report_id']));
                    } catch (Exception $ex) {
                        REDCap::logEvent('Extended Reports module', 'Report save failed \n<br> '.$ex->getMessage());
                    }
                    break;
                }
            }

        } else if (PAGE == 'DataExport/report_ajax.php') {
            // viewing a report - get the html to display
            $extendedReport = $this->getExtendedAttributes(\htmlspecialchars($_POST['report_id']));

            if (!is_null($extendedReport)) {
                $this->viewReport($extendedReport);
                $this->exitAfterHook();
            }

        } else if (PAGE == 'DataExport/data_export_ajax.php') {
            // exporting a report - get the export files (only csvraw and csvlabel export option gets any manipulation)
            $extendedReport = $this->getExtendedAttributes(\htmlspecialchars($_POST['report_id']));

            if (!is_null($extendedReport) && ($_POST['export_format']==='csvraw' || $_POST['export_format']==='csvlabels')) {
                $this->exportReport($extendedReport);
                $this->exitAfterHook();
            }

        } else if (isset($_POST['report_id']) && (PAGE == 'DataExport/report_copy_ajax.php' || PAGE == 'DataExport/report_delete_ajax.php')) {
            // copying or deleting a report
            $report_id = \htmlspecialchars($_POST['report_id']);
            $report = DataExport::getReports($report_id);
            if (empty($report)) return;

            $reports_edit_access = DataExport::getReportsEditAccess(USERID, $user_rights['role_id'], $user_rights['group_id'], $_POST['report_id']);
            if (empty($reports_edit_access)) return;

            switch (PAGE) {
                case 'DataExport/report_copy_ajax.php': $this->copyReport($report_id); break;                
                case 'DataExport/report_delete_ajax.php': $this->deleteReport($report_id); break;                
                default: break;
            }
        } else if (PAGE=='api/index.php' && API===true && isset($_POST['report_id']) && isset($_POST['content']) && $_POST['content']==='report') {
            $this->apiReportExport();
            // $this->exitAfterHook(); when successfull
        }
    }
    
    /**
     * Apply tweaks to the Report List and Report Edit pages to enable the configuration of the extensions.
     */
    public function redcap_every_page_top($project_id) {
        if (PAGE!='DataExport/index.php') return;
        if (!defined("USERID")) return; // in case logging in on data export page - $this->getUser() throws exception

        if ($this->getUser()->isSuperUser() && isset($_GET['create']) && isset($_GET['addedit'])) {
            // creating a new report, give option to super users for sql report
            $this->includeSqlOption(true);
            $this->includeReshapeOptions();
            return;
        } 
        
        if (isset($_GET['report_id'])) {

            $extendedReport = $this->getExtendedAttributes($_GET['report_id']);

            if (!is_null($extendedReport) && $extendedReport['rpt-is-sql']) {
                $this->includeSqlOption(false, $extendedReport['rpt-sql']);
            }
        } else {
            // report list page - tweak buttons for export/stats
            $rptConfig = $this->getSubSettings('report-config');
            $extendedReports = array();
            foreach($rptConfig as $rpt) {
                if ($rpt['rpt-is-sql'] || $rpt['rpt-is-reshaped']) {
                    $extension = ($rpt['rpt-is-sql']) ? 1 : 0;
                    $extension = ($rpt['rpt-is-reshaped']) ? 2 : $extension;
                    $extendedReports[] = array('id'=>$rpt['report-id'],'ext'=>$extension);
                }
            }
            if (count($extendedReports)) {
                $this->initializeJavascriptModuleObject();
                $jsObject = $this->framework->getJavascriptModuleObjectName();
                ?>
                <script type="text/javascript">
                    $(window).on('load', function(){
                        var em = <?=$jsObject?>;
                        em.extrpts = JSON.parse('<?=\js_escape(\json_encode($extendedReports))?>');
                        var nSql = 0;
                        var nReshape = 0;
                        setTimeout(function(){
                          em.extrpts.forEach(function(e){
                            if (e.ext===1) {
                                $('#reprow_'+e.id+' td').eq(2).find('div.wrap').append('<span class="mx-1 text-muted"><i class="fas fa-th"></i></span>'); 
                                $('#reprow_'+e.id).find('i.fa-chart-bar').parent('button').hide(); // hide Stats & Charts for SQL reports
                                nSql++;
                            } else if (e.ext===2) {
                                $('#reprow_'+e.id+' td').eq(2).find('div.wrap').append('<span class="mx-1 text-muted"><i class="fas fa-grip-vertical"></i><i class="fas fa-grip-horizontal ml-1"></i></span>'); 
                                nReshape++;
                            }
                          });
                        }, 300);

                        if (em.extrpts.length) {
                            var defaultShowExportFormatDialog = showExportFormatDialog;
                            showExportFormatDialog = function(report_id, odm_export_metadata) {
                                var ext = 0;
                                em.extrpts.forEach(function(e){
                                    if (e.id==report_id) {
                                        ext = e.ext;
                                    }
                                });
                                if (ext) {
                                    // attach ext rpt properties to dialog so can access there and tweak display
                                    $('#exportFormatForm').attr('report_id', report_id);
                                    $('#exportFormatForm').attr('ext', ext);
                                } else {
                                    $('#exportFormatForm').removeAttr('report_id');
                                    $('#exportFormatForm').removeAttr('ext');
                                }
                                defaultShowExportFormatDialog(report_id, odm_export_metadata);
                            };
                            $('body').on('dialogopen', function(event){
                                if(event.target.id=='exportFormatDialog') {
                                    switch ($('#exportFormatForm').attr('ext')) {
                                        case '1': // SQL Report
                                            $('#exportFormatDialog > div').eq(0).hide(); // "Select your export settings..."
                                            $('#exportFormatForm table td:nth-child(2)').hide(); /* de-identification options */
                                            $('#export_choices_table tr:nth-child(n+2)').hide(); /* export options except CSV Raw */
                                            $('select[name=returnBlankForGrayFormStatus]').closest('div').hide(); /* export 0 for grey status */
                                            break;

                                        case '2': // Reshaping
                                        default:
                                            $('#exportFormatDialog > div').eq(0).show(); // "Select your export settings..."
                                            $('#exportFormatForm table td:nth-child(2)').show(); /* de-identification options */
                                            $('#export_choices_table tr:nth-child(n+2)').show(); /* all export options */
                                            $('select[name=returnBlankForGrayFormStatus]').closest('div').show(); /* export 0 for grey status */
                                            break;
                                    }

                                }
                            });
                        }

                    });
                </script>
                <?php
            }
        }

    }

    /**
     * getExtendedAttributes
     * Read any module settings for the specified report id
     */
    public function getExtendedAttributes($report_id, $pid=null) {
        $rptConfig = $this->getSubSettings('report-config', $pid);
        foreach($rptConfig as $rpt) {
            if ($rpt['report-id']==$report_id) {
                return $rpt;
            }
        }
        return null;
    }

    /**
     * includeSqlOption()
     * Include additional HTML, JS and CSS for SQL Query option.
     * - Only super users may enter or edit SQL queries
     * - Regular users may still edit report title, description, user view/edit access and public visibility
     */
    protected function includeSqlOption($create=true, $sql='') {
        global $lang;
        $queryLines = (empty(trim($sql)))?'':preg_split("/\r?\n|\r/", trim($sql));
        $displaycb = ($create) ? 'block' : 'none';
        $displayta = ($create) ? 'none' : 'block';
        $td0Html = static::SQL_OPTION_TD0_HTML;
        $td1Html = ($this->getUser()->isSuperUser()) ? static::SQL_OPTION_TD1_HTML_ADMIN : static::SQL_OPTION_TD1_HTML_PLEB;
        ?>
        <style type="text/css">
            #rpt-sql-check { display: <?=$displaycb?>; }
            #rpt-sql-block { display: <?=$displayta?>; }
            #rpt-sql { font-family:monospace; }
        </style>
        <script type="text/javascript">
            $(document).ready(function(){
                function hideRptTrs() {
                    $('#create_report_form tr:gt(7), #create_report_form tr:eq(4) div').hide();
                };

                var trDescription = $('#create_report_form').find("tr:eq(2)");
                var trSql = trDescription.clone();
                trSql.find('td:eq(0)').html('<?=$td0Html?>');
                trSql.find('td:eq(1)').html('<?=$td1Html?>');
                trDescription.after(trSql);

                $('#rpt-sql-check').on('click', function(){
                    $(this).hide();
                    $('#rpt-sql-block').show();
                    hideRptTrs();
                });

                $('#rpt-sql-expand').on('click', function(){
                    growTextarea("rpt-sql");
                });

                var queryLines = JSON.parse('<?=\js_escape(\json_encode($queryLines))?>');

                if (queryLines.length>0) {
                    if($('#rpt-sql-block').length) {
                        $('#rpt-sql').html(queryLines.join('\n'));
                        $('#rpt-sql-expand').trigger('click');
                    }
                    hideRptTrs();
                }
            });
        </script>
        <?php
    }

    protected function includeReshapeOptions() {
        // check that project has multiple events and/or a repeating form
    }


    /** 
     * Look up what the next report id will be in the redcap_reports table
     */
    protected function getNextReportId() {
        global $db;
        $report_id = 0;
        $result = $this->query("select auto_increment as next_report_id from information_schema.tables where table_schema=? and table_name='redcap_reports'",[$db]);
        while($row = $result->fetch_assoc()){
            $report_id = $row['next_report_id'];
        }
        return $report_id;
    }

    /**
     * saveReport
     */
    protected function saveReport($report_id) {
        global $Proj;

        if ($report_id==0) {
            // first save of a new report
            // save custom settings using incremented max redcap_reports.report_id ($_GET['report_id']==0)
            $report_id = $this->getNextReportId();
        }

        // tweaks to $_POST for settings that are not submitted from client
        if (array_key_exists('rpt-sql', $_POST) && $_POST['rpt-sql']!='') {
            $_POST['rpt-is-sql'] = true;
            $_POST['advanced_logic'] = '['.$Proj->table_pk.']=""'; // never return any records if somehow run without sql

            $_POST['rpt-sql'] = rtrim(trim($_POST['rpt-sql']), ";");
            if (!preg_match("/^select\s/i", $_POST['rpt-sql'])){
                throw new Exception('SQL is not a SELECT query \n<br> '.$_POST['rpt-sql']);
            }
        }
        
        $rptConfig = $this->getSubSettings('report-config');
        $isNew = true;
        foreach($rptConfig as $idx => $rpt) {
            if ($rpt['report-id']==$report_id) {
                $isNew = false;
                break;
            }
        }
        $reportIndex = ($isNew) ? count($rptConfig) : $idx;

        $projectSettings = $this->getProjectSettings();
        $config = $this->getConfig();
        
        $projectSettings['report-config'][$reportIndex] = true;

        foreach($config['project-settings'][0]['sub_settings'] as $subSetting) {
            $settingKey = $subSetting['key'];
            if ($settingKey==='report-id') {
                $projectSettings['report-id'][$reportIndex] = "$report_id";
            } else if (array_key_exists($settingKey, $_POST)) {
                $projectSettings[$settingKey][$reportIndex] = $_POST[$settingKey];
            } 
        }
        $this->setProjectSettings($projectSettings);

        $this->log("save report", array_merge(['is-new'=>$isNew], $rpt));
        
        return null;
    }

    /** 
     * copyReport
     * Look up the configuration for the report being copied and the next report id.
     * Add a new report setting with the same config under the new id.
     */
    protected function copyReport($report_id) {
        $rptConfigs = $this->getSubSettings('report-config');
        $found = false;
        foreach($rptConfigs as $erIdx => $rpt) {
            if ($rpt['report-id']==$report_id) {
                $rpt['report-id'] = "".$this->getNextReportId();
                $found = true;
                break;
            }
        }

        if (!$found) return;

        $projectSettings = $this->getProjectSettings();
        $config = $this->getConfig();
        
        $projectSettings['report-config'][] = true;
        foreach($config['project-settings'][0]['sub_settings'] as $subSetting) {
            $settingKey = $subSetting['key'];
            $settingValue = ($subSetting['type']=='checkbox') ? (bool) $rpt[$settingKey] : $rpt[$settingKey];
            $projectSettings[$settingKey][] = $settingValue;
        }

        $this->setProjectSettings($projectSettings);

        $this->log("copy report", array_merge(['copy-of'=>$report_id], $rpt));

        return null;
    }

    /** 
     * deleteReport
     */
    protected function deleteReport($report_id) {
        $rptConfigs = $this->getSubSettings('report-config');
        $found = false;
        foreach($rptConfigs as $erIdx => $rpt) {
            if ($rpt['report-id']==$report_id) {
                $found = true;
                break;
            }
        }

        if (!$found) return;

        $projectSettings = $this->getProjectSettings();
        $config = $this->getConfig();
        
        unset($projectSettings['report-config'][$erIdx]);
        reset($projectSettings['report-config']);

        foreach($config['project-settings'][0]['sub_settings'] as $subSetting) {
            $settingKey = $subSetting['key'];
            unset($projectSettings[$settingKey][$erIdx]);
            reset($projectSettings[$settingKey]);
        }
        $this->setProjectSettings($projectSettings);

        $this->log("delete report", ['report-id'=>$report_id, 'extended-report-index'=>$erIdx]);

        return null;
    }

    protected function doExtendedReport($extendedAttributes, $format, $csvDelimiter=null, $decimalCharacter=null) {
        $csvDelimiter = (empty($csvDelimiter)) ? UIState::getUIStateValue('', 'export_dialog', 'csvDelimiter') : $csvDelimiter;
        $csvDelimiter = (empty($csvDelimiter)) ? static::DEFAULT_CSV_DELIMITER : $csvDelimiter;
        $decimalCharacter = (empty($decimalCharacter)) ? UIState::getUIStateValue('', 'export_dialog', 'decimalCharacter') : $decimalCharacter;
        $decimalCharacter = (empty($decimalCharacter)) ? static::DEFAULT_DECIMAL_CHAR : $decimalCharacter;

        if (array_key_exists('rpt-is-sql', $extendedAttributes) && $extendedAttributes['rpt-is-sql']) {
            return $this->doSqlReport($extendedAttributes['rpt-sql'], $format, $csvDelimiter, $decimalCharacter);
        } else {
            // ... extended
        }
    }

    protected function doSqlReport($sql, $format, $csvDelimiter, $decimalCharacter) {
        global $lang,$Proj,$user_rights,$project_id;
    
        $sql = rtrim(trim($sql), ";");
        $sql = Piping::pipeSpecialTags($sql, $project_id); // user and misc tags will work
        if (!preg_match("/^select\s/i", $sql)) {
            return array('Not a select query', 0);
		}

        $filteredRows = array();
        $thead = array();
        $output = array();

        try {
            $result = $this->query($sql,[]);
        } catch (Exception $ex) {
            $output[] = $ex->getMessage();
            $output[] = 0;
            return $output;
        }
        
        if ($result) {
            $finfo = $result->fetch_fields();
            $includesPk = false;
            foreach ($finfo as $f) {
                if ($f->name==$Proj->table_pk) $includesPk = true;
                $thead[] = $f->name;
            }

            if ($includesPk && $user_rights['group_id']) {
                // if sql query includes pk field then filter by user's DAG
                $recordFilter = array();
                $result1 = $this->query("select distinct record from redcap_data where project_id=? and field_name='__GROUPID__' and `value`=?",[$Proj->project_id,$user_rights['group_id']]);
                while($row = $result1->fetch_assoc()){
                    $recordFilter[] = $row['record'];
                }
                $result1->free();
            } else {
                $recordFilter = false;
            }

            while($row = $result->fetch_assoc()){
                if (!$recordFilter || (is_array($recordFilter) && in_array($row[$Proj->table_pk], $recordFilter))) {
                    $filteredRows[] = $row;
                }
            }
            $result->free();
        }

        $num_results_returned = count($filteredRows);
        $return_content = '';

        if ($format=='html') {
            $report_table = '';
            if ($num_results_returned === 0) {
                $report_table='<table id="report_table" class="dataTable cell-border" style="table-layout:fixed;margin:0;font-family:Verdana;font-size:11px;"><thead><tr></tr></thead><tbody><tr class="odd"><td style="color:#777;border:1px solid #ccc;padding:10px 15px !important;" colspan="0">No results were returned</td></tr></tbody></table>';
            } else {
                //"<table id='report_table' class='dataTable cell-border' style='table-layout:fixed;margin:0;font-family:Verdana;font-size:11px;'><thead><tr><th>Study ID<div class="rpthdr">record_<wbr>id</div></th><th>Event Name<div class="rpthdr">redcap_<wbr>event_<wbr>name</div></th><th>Repeat Instrument<div class="rpthdr">redcap_<wbr>repeat_<wbr>instrument</div></th><th>Repeat Instance<div class="rpthdr">redcap_<wbr>repeat_<wbr>instance</div></th></tr></thead><tr class="odd"><td><a href="/redcap_v10.7.1/DataEntry/record_home.php?pid=244&amp;id=1&amp;arm=1" class="rl">1</a>&nbsp; <span class="crl">Alice Adams</span></td><td>Person</td><td class='nodesig'></td><td class='nodesig'></td></tr><tr class="even"><td><a href="/redcap_v10.7.1/DataEntry/index.php?pid=244&amp;id=1&amp;page=event&amp;event_id=1494&amp;instance=1" class="rl">1</a>&nbsp; <span class="crl">Alice Adams</span></td><td>Event</td><td></td><td>1</td></tr><tr class="odd"><td><a href="/redcap_v10.7.1/DataEntry/record_home.php?pid=244&amp;id=2&amp;arm=1" class="rl""
                $report_table = "<table id='report_table' class='dataTable cell-border' style='table-layout:fixed;margin:0;font-family:Verdana;font-size:11px;'><thead><tr>";
                foreach($thead as $th) {
                    $title = (array_key_exists($th, $Proj->metadata)) ? REDCap::filterHtml($Proj->metadata[$th]['element_label']) : $th;
                    $title = (\strlen($title) > 50) ? \substr($title, 0, 30).'...'.\substr($title, -17, 17) : $title;
                    $report_table .= "<th><div class=\"mr-3\">$title<div class=\"rpthdr\">$th</div></div></th>";
                }
                $report_table .= "</tr></thead><tbody>";
                
                for ($i=0; $i<count($filteredRows); $i++) {
                    $rowclass = ($i%2) ? 'even' : 'odd';
                    $report_table .= "<tr class=\"$rowclass\">";
                    foreach ($filteredRows[$i] as $field => $td) {
                        if(is_numeric($td) && is_float(0+$td)) {
                            $report_table .= "<td>".str_replace('.',$decimalCharacter,"$td",1)."</td>";
                        } else if ($field==$Proj->table_pk) {
                            $report_table .= "<td><a target='_blank' href='".APP_PATH_WEBROOT."DataEntry/record_home.php?pid=$project_id&id=$td'>$td</a></td>";
                        } else {
                            $report_table .= "<td>$td</td>";
                        }
                    }
                    $report_table .= "</tr>";
                }
            }
            $return_content = $report_table;

        } else if ($format=='csvraw' || $format=='csvlabel') { 
            $return_content = ""; // If no results will return empty CSV with no headers (follows pattern of regualr data exports)
            if ($num_results_returned > 0) {

                foreach($thead as $th) {
                    $return_content .= $this->makeCsvValue($th, $csvDelimiter).$csvDelimiter;
                }
                $return_content = rtrim($return_content, $csvDelimiter).static::DEFAULT_CSV_LINE_END;
                
                foreach ($filteredRows as $row) {
                    foreach ($row as $td) {
                        $return_content .= $this->makeCsvValue($td, $csvDelimiter, $decimalCharacter).$csvDelimiter;
                    }
                    $return_content = rtrim($return_content, $csvDelimiter).static::DEFAULT_CSV_LINE_END;
                }
            }

        } else if ($format=='json') { 
            $content = array(); // If no results will return empty array (follows pattern of regualr data exports)
            if ($num_results_returned > 0) {
                foreach ($filteredRows as $row) {
                    $rowObj = new \stdClass();
                    foreach ($row as $field => $value) {
                        $rowObj->$field = $value;
                    }
                    $content[] = $rowObj;
                }
            }

            $output[] = \json_encode($content);
            $output[] = $num_results_returned;

        } else if ($format=='xml') { 
            $indent = '    ';
            $return_content = '<?xml version="1.0" encoding="UTF-8" ?>'.PHP_EOL.'<records>';
            
            if ($num_results_returned > 0) { // If no results will return empty records node (follows pattern of regualr data exports)
                foreach ($filteredRows as $row) {
                    $return_content .= PHP_EOL.$indent.'<item>';
                    foreach ($row as $field => $value) {
                        $return_content .= PHP_EOL.$indent.$indent."<$field>";
                        $return_content .= PHP_EOL.$indent.$indent.$indent."<![CDATA[$value]]>";
                        $return_content .= PHP_EOL.$indent.$indent."</$field>";
                    }
                    $return_content .= PHP_EOL.$indent.'</item>';
                }
            }
            $return_content = trim($return_content).'</records>';
        }

        $output[] = trim($return_content);
        $output[] = $num_results_returned;
        return $output;
    }

    protected function makeCsvValue($value="", $delimiter="", $decimalCharacter="") {
        $delimiter = ($delimiter==="") ? static::DEFAULT_CSV_DELIMITER : $delimiter;
        $decimalCharacter = ($decimalCharacter==="") ? static::DEFAULT_DECIMAL_CHAR : $decimalCharacter;
        $value = trim($value);
        if (in_array(substr($value, 0, 1), self::$csvInjectionChars)) {
            // Prevent CSV injection for Excel - add space in front if first character is -, @, +, or = (http://georgemauer.net/2017/10/07/csv-injection.html)
            $value = " $value";
        }
        if (strpos($value, static::DEFAULT_ESCAPE_CHAR)!==false) {
            // If value contains " then escape as "" e.g. A quote: "Wow!". -> "A quote: ""Wow!""."
            $value = static::DEFAULT_ESCAPE_CHAR . str_replace(static::DEFAULT_ESCAPE_CHAR, static::DEFAULT_ESCAPE_CHAR.static::DEFAULT_ESCAPE_CHAR, $value) . static::DEFAULT_ESCAPE_CHAR;
        } else if (strpos($value, $delimiter)!==false) {
            // If value contains a comma (and no ") then wrap with "
            $value = static::DEFAULT_ESCAPE_CHAR.$value.static::DEFAULT_ESCAPE_CHAR;
        } else if(is_numeric($value) && is_float(0+$value)) {
            $value = str_replace('.',$decimalCharacter,"$value",1);
        }
        return $value;
    }

    protected function doReshapedReport($extendedReport) {
        global $lang;
        return array('reshaped report', 0);
    }

    /**
     * viewReport($extendedAttributes)
     * Get HTML of extended report for display
     * See viewReport.php
     */
    protected function viewReport($extendedAttributes) {
        require_once 'viewReport.php';
    }

    /**
     * exportReport($extendedAttributes)
     * Get HTML of CSV file download dialog
     * See exportReport.php
     */
    protected function exportReport($extendedAttributes) {
        require_once 'exportReport.php';
    }

    /**
     * apiReportExport($extendedAttributes)
     * Get around having to copy all of the built-in API checking code by doing the regular API call and getting JSON data here 
     * - if it works then reshape etc.
     * - if we get an error (e.g. bad token) then echo it back
     */
    protected function apiReportExport() {
        if (isset($_POST['extended_reports_background_call'])) return; // for preventing infinite loop!

        // error with call - let it proceed and fail again for the user in the usual way
        if (!isset($_POST['token']) || !isset($_POST['content']) || $_POST['content']!='report' || !isset($_POST['report_id']) ) return;

        $format = (isset($_POST['format'])) ? \htmlspecialchars($_POST['format']) : 'xml';

        switch ($format) {
            case 'csv':
            case 'json':
            case 'xml':
                break; // csv, json, xml handled here
            case 'odm':
            default:
                return; // odm, other not handled here
        }

        // get pid for report
        $report_id = \htmlspecialchars($_POST['report_id']);
        $result = $this->query("select project_id from redcap_reports where report_id=?",[$report_id]);
        while($row = $result->fetch_assoc()){
            $pid = $row['project_id'];
        }

        $extendedAttributes = $this->getExtendedAttributes($report_id, $pid);
        if (is_null($extendedAttributes)) return; // no extensions on this report - return

        $url = APP_PATH_WEBROOT_FULL.'api/';
        $returnFormat = (isset($_POST['returnFormat'])) ? \htmlspecialchars($_POST['returnFormat']) : $format;
        $csvDelimiter = (isset($_POST['csvDelimiter'])) ? \htmlspecialchars($_POST['csvDelimiter']) : null;
        $decimalCharacter = (isset($_POST['decimalCharacter'])) ? \htmlspecialchars($_POST['decimalCharacter']) : null;
        $params = array(
            'token' => $_POST['token'],
            'content' => $_POST['content'],
            'format' => 'json',
            'returnFormat' => 'json',
            'report_id' => $_POST['report_id'],
            'extended_reports_background_call' => 1
        );

        $result = \http_post($url, $params);
        if (is_null($result)) return; // error with call (e.g. permissions) - let it proceed and fail again for the user in the usual way
        $resultArray = \json_decode($result, true);
        if (array_key_exists('error', $resultArray)) return; // error with call (e.g. permissions) - let it proceed and fail again for the user in the usual way

        global $Proj, $project_id;
        $Proj = new Project($pid);
        $project_id = $Proj->project_id;
        
        if (!defined("USERID")) {
            // set USERID from token in case needed for smart var piping 
            $token = trim(\htmlspecialchars($_POST['token']));
            $ur = $this->query("select ur.username, ui.super_user from redcap_user_rights ur inner join redcap_user_information ui on ur.username=ui.username where ur.api_token=? and project_id=? and user_suspended_time is null limit 1", [ $token, $project_id ]);
            while($row = $ur->fetch_assoc()){
                $user = $row['username'];
                $super = $row['super_user'];
            }
            define('USERID', $user);
            if (!defined("SUPER_USER")) define("SUPER_USER", $super);
		}

        if (array_key_exists('rpt-is-sql', $extendedAttributes) && $extendedAttributes['rpt-is-sql']) {
            // sql report - result will be empty
            list($data_content, $num_records_returned) = $this->doExtendedReport($extendedAttributes, $format, $csvDelimiter, $decimalCharacter);

            if (!defined("REDCAP_API_NO_EXIT")) define("REDCAP_API_NO_EXIT", true); // this prevents sendResponse() doing exit(), which causes EM framework to throw exceptions

            RestUtility::sendResponse(200, $data_content, $format);

        } else {
            // extended report - process $result as per config
            // $resultArray ...
            return; //temp until implemented
        }
    
        $this->exitAfterHook();
        return;
    }
}