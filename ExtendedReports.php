<?php
/**
 * REDCap External Module: ExtendedReports
 * Extensions & additional functionality for REDCap's built in Data Exports & Reports functionality.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\ExtendedReports;

use ExternalModules\AbstractExternalModule;

require_once 'Report.php';

class ExtendedReports extends AbstractExternalModule
{
    protected const SQL_OPTION_TD0_HTML = '<b><i class="fas fa-th mr-1"></i>Custom SQL Query</b><div class="wrap" style="color:#888;font-size:11px;line-height:12px;margin-top:5px;">Administrators only<br>SELECT queries only</div>';
    protected const SQL_OPTION_TD1_HTML_ADMIN = '<input id="rpt-sql-check" type="checkbox" class="mb-1" /><div id="rpt-sql-block"><textarea name="rpt-sql" id="rpt-sql" class="x-form-field notesbox form-control" rows="5" style="font-size:12px;width:99%;"></textarea><div style="line-height:11px;"><div class="float-right"><a href="javascript:;" tabindex="-1" class="expandLink" id="rpt-sql-expand">Expand</a>&nbsp;&nbsp;</div><div class="float-left" style="color:#888;font-size:11px;line-height:12px;font-weight:normal;"><div class="font-weight-bold">Smart Variables</div><div class="pl-2">User-level and system-level smart variables may be utilised. Pay attention to the data type and quote strings as required.<br>E.g. <span style="font-family:monospace;">select [project-id] as pid, \\\'[user-name]\\\' as user;</span></div><div class="font-weight-bold">Data Access Groups</div><div class="pl-2">If your query returns a column named the same as the project\\\'s record id column then the rows will be filtered by the user\\\'s DAG (where applicable).<br>To avoid automatic DAG filtering you can:<ol><li>have your SQL return the column renamed to something else</li><li>tick this box to <strong>disable DAG filtering</strong> <input type=\"checkbox\" name=\"rpt-sql-disable-dag-filter\"></li></ol></div></div></div></div>';
    protected const SQL_OPTION_TD1_HTML_PLEB = '<span class="rpt-sql-pleb">MAG<i class="fas fa-magic rpt-sql-wiggle5" style="margin-right:-5px;"></i>C<sup><i class="far fa-star rpt-sql-wiggle5 ml-1" style="font-size:85%"></i><i class="far fa-star rpt-sql-wiggle5" style="font-size:70%"></i><i class="far fa-star rpt-sql-wiggle5" style="font-size:50%"></sup></span>';
    protected const SQL_NEWLINE_REPLACEMENT = '|EXTRPT:NL|';
    protected const TAG_REPORT_LABEL = '@REPORT-LABEL';
    protected $report_id = null;

    /**
     * redcap_every_page_before_render($project_id=null)
     * Catch a report view or export and if there's some "extended" config apply the necessary tweaks.
     */
    public function redcap_every_page_before_render($project_id=null) {
        if (str_contains(PAGE,'DataExport')) {
            // Action tag: @REPORT-LABEL='?'
            $this->replaceLabels();
        }

        // $a = 'what does new report save look like?'; // report_edit_ajax.php $_GET report_id=0 &_POST: __TITLE__ description rpt-sql field(array) etc.
        // $b = 'what does existing report save look like?'; // report_edit_ajax.php $_GET: report_id &_POST: __TITLE__ description rpt-sql field(array) etc.
        // $c = 'what does viewing a report look like?'; // report_ajax.php $_GET: pagenum $_POST: report_id Return list ($report_table, $num_results_returned)
        // $d = 'what does downloading a report look like?'; // data_export_ajax.php $_POST: export_format report_id
        // $e = 'what does an api download of a report look like?'; // PAGE=='api/index.php' && API==true:  no project context, need to do separate api call to verify token & permissions and process json result according to extension attributes
        if (!(isset($_POST['report_id']) || isset($_GET['report_id']))) return;
        $this->report_id = $_POST['report_id'] ?? $_GET['report_id'] ?? null;
        if (!is_numeric($this->report_id)) return; // can be 'ALL' or 'SELECTED'

        // is this an API report export?
        if (
            PAGE=='api/index.php' && //API===true && 
            isset($_POST['report_id']) && isset($_POST['content']) && $_POST['content']==='report'
           ) {
            $this->replaceLabels();
            $this->apiReportExport();
            // $this->exitAfterHook(); when successful
            return;
        } 
        
        if (PAGE=='DataExport/report_edit_ajax.php' && count($_POST)) {
            // saving a new report -> save custom settings using incremented max redcap_reports.report_id ($_GET['report_id']==0)
            // saving existing report -> save custom settings using report_id = $_GET['report_id']
            try {
                $this->saveReport($this->report_id);
            } catch (\Exception $ex) {
                \REDCap::logEvent('Extended Reports module', 'Report save failed \n<br> '.$ex->getMessage());
            }

        } else if (PAGE == 'DataExport/report_ajax.php' || (\DataExport::isPublicReport() && isset($_POST['report_id']))) {
            // viewing a report - get the html to display
            $report = new Report($project_id, intval($this->report_id), $this);
            if ($report->is_extended) {
                $report->viewReport();
                $this->exitAfterHook();
            }

        } else if (PAGE == 'DataExport/data_export_ajax.php') {
            // exporting a report - get the export files (only csvraw and csvlabel export option gets any manipulation)
            $report = new Report($project_id, intval($this->report_id), $this);
            if ($report->is_extended && (\htmlspecialchars($_POST['export_format'], ENT_QUOTES)==='csvraw' || \htmlspecialchars($_POST['export_format'], ENT_QUOTES)==='csvlabels')) {
                if(!isset($_GET['extended_report_hook_bypass'])) {
                    $report->exportReport();
                    $this->exitAfterHook();
                }
            }

        } else if (isset($_POST['report_id']) && (PAGE == 'DataExport/report_copy_ajax.php' || PAGE == 'DataExport/report_delete_ajax.php')) {
            // copying or deleting a report
            global $user_rights;
            $deReport = \DataExport::getReports($this->report_id);
            if (empty($deReport)) return;

            $reports_edit_access = \DataExport::getReportsEditAccess(USERID, $user_rights['role_id'], $user_rights['group_id'], $this->report_id);
            if (empty($reports_edit_access)) return;

            switch (PAGE) {
                case 'DataExport/report_copy_ajax.php': $this->copyReport($this->report_id); break;                
                case 'DataExport/report_delete_ajax.php': $this->deleteReport($this->report_id); break;                
                default: break;
            }
        }
    }
    
    /**
     * Apply tweaks to the Report List and Report Edit pages to enable the configuration of the extensions.
     */
    public function redcap_every_page_top($project_id) {
        if (PAGE!='DataExport/index.php') return;
        if (!defined("USERID")) return; // in case logging in on data export page - $this->getUser() throws exception

        if (isset($_GET['create']) && isset($_GET['addedit'])) {
            // creating a new report, give option to super users for sql report
            if ($this->getUser()->isSuperUser()) $this->includeSqlOption(true); 
            $this->includeReshapeOptions(new Report($project_id, 0, $this));

        } else if (isset($_GET['addedit']) && isset($_GET['report_id'])) {
            // edit existing report
            $report = new Report(intval($project_id), intval($_GET['report_id']), $this);

            if ($report->is_extended && $report->is_sql) {
                $this->includeSqlOption(false, $this->stripTabs($report->sql_query), $report->sql_disable_dag_filter);
            } else {
                $this->includeReshapeOptions($report);
            }
        } else if (isset($_GET['report_id'])) {
            // viewing report

        } else {
            // live filters for 'ALL' report
            $lfAll = \DataExport::displayReportDynamicFilterOptions('ALL');
            echo "<div id='rpta-live-filters' style='display:none;'>$lfAll</div>";
            ?>
            <script type='text/javascript'>
                $(document).ready(function(){
                    let rptaLF = $('#rpta-live-filters');
                    $(rptaLF).find('span:first').append('<br>');
                    $(rptaLF).find('select').removeAttr('onchange').on('change', function(){
                        let lfId = $(this).attr('id');
                        let lfVal = $(this).val();
                        window.location.href = app_path_webroot+'DataExport/index.php?pid='+pid+'&report_id=ALL&pagenum=1&'+lfId+'='+encodeURIComponent(lfVal);
                    });
                    $(rptaLF).insertAfter('span.rprt_btns:first').show();
                });
            </script>
            <?php

            // report list page - tweak buttons for export/stats
            $rptConfig = $this->getSubSettings('report-config');
            $extendedReports = array();
            foreach($rptConfig as $rpt) {
                if ($rpt['rpt-is-sql'] || $rpt['rpt-reshape-event'] || $rpt['rpt-reshape-instance']) {
                    $extension = ($rpt['rpt-is-sql']) ? 1 : 0;
                    $extension = ($rpt['rpt-reshape-event'] || $rpt['rpt-reshape-instance']) ? 2 : $extension;
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
                                 // mark SQL reports and hide Stats & Charts button
                                $('#reprow_'+e.id+' td').eq(2).find('div.wrap').append('<span class="mx-1 text-muted"><i class="far fa-star"></i></span>'); 
                                $('#reprow_'+e.id).find('i.fa-chart-bar').parent('button').hide();
                                nSql++;
                            } else if (e.ext===2) {
                                $('#reprow_'+e.id+' td').eq(2).find('div.wrap').append('<span class="mx-1 text-muted"><i class="fas fa-shapes"></i></span>'); 
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
                                            $('#export_choices_table tr:nth-child(n+3)').hide(); /* export options except CSV Raw/Labels */
                                            $('select[name=returnBlankForGrayFormStatus]').closest('div').hide(); /* export 0 for grey status */
                                            break;

                                        case '2': // Reshaping
                                        default:
                                            $('#exportFormatDialog > div').eq(0).show(); // "Select your export settings..."
                                            $('#exportFormatForm table td:nth-child(2)').show(); /* de-identification options */
                                            $('#export_choices_table tr:nth-child(n+3)').show(); /* all export options */
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
     * includeSqlOption()
     * Include additional HTML, JS and CSS for SQL Query option.
     * - Only super users may enter or edit SQL queries
     * - Regular users may still edit report title, description, user view/edit access and public visibility
     */
    protected function includeSqlOption($create=true, $sql='', $disableDagFilter=false) {
        global $lang;
        $queryText = preg_replace("/\r?\n|\r/",self::SQL_NEWLINE_REPLACEMENT,trim($sql));
        $queryText = str_replace('\\','\\\\',$queryText);
        
        $displaycb = ($create) ? 'block' : 'none';
        $displayta = ($create) ? 'none' : 'block';
        $td0Html = static::SQL_OPTION_TD0_HTML;
        if ($this->getUser()->isSuperUser()) {
            $td1Html = static::SQL_OPTION_TD1_HTML_ADMIN;
        } else {
            $td1Html = static::SQL_OPTION_TD1_HTML_PLEB;
            ?>
            <style type="text/css">
                .rpt-sql-pleb { color: #800000; font-size: x-large; font-style: italic; font-family: "MS PGothic",Osaka,Arial,sans-serif; }
                .rpt-sql-wiggle5 { animation: wiggle 5s linear infinite; }
                @keyframes wiggle {
                    0%, 10% { transform: rotateZ(0); }
                    12% { transform: rotateZ(-15deg); }
                    14% { transform: rotateZ(10deg); }
                    16% { transform: rotateZ(-10deg); }
                    18% { transform: rotateZ(6deg); }
                    20% { transform: rotateZ(-4deg); }
                    22%, 100% { transform: rotateZ(0); }
                }
            </style>
            <?php
        }
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

                var sqlText = '<?=\js_escape($queryText)?>'.replaceAll('<?=self::SQL_NEWLINE_REPLACEMENT?>','\n');

                if (sqlText.length>0) {
                    if($('#rpt-sql-block').length) {
                        $('#rpt-sql').html(sqlText);
                        $('#rpt-sql-expand').trigger('click');
                    }
                    hideRptTrs();
                }
                <?php if ($disableDagFilter) { ?>
                    $('input[name=rpt-sql-disable-dag-filter]').prop('checked', true);
                <?php } ?>
            });
        </script>
        <?php
    }

    /**
     * includeReshapeOptions(Report report)
     * Include additional HTML, JS and CSS for reshaping options on the report editing page 
     * param Report $report
     */
    protected function includeReshapeOptions(Report $report) {
        // check that project has multiple events and/or at least one repeating form
        global $Proj;
        if (!$Proj->longitudinal && !$Proj->hasRepeatingFormsEvents()) return;

        $selected = 'selected="selected"';
        $reshapeEventOptions = array(
            ''=>array('label'=>'-- default (row per event) --','selected'=>''),
            'ef'=>array('label'=>'Columns ordered by event then field','selected'=>''),
            'fe'=>array('label'=>'Columns ordered by field then event','selected'=>'')
        );
        $reshapeEventOptions[$report->reshape_event]['selected'] = $selected;
        $reshapeInstanceOptions = array(
            ''=>array('label'=>'-- default (row per instance) --','selected'=>''),
            'cols'=>array('label'=>'Column per instance','selected'=>''),
            'conc_space'=>array('label'=>'Concatenate all values with space','selected'=>''),
            'conc_comma'=>array('label'=>'Concatenate all values with comma','selected'=>''),
            'conc_pipe'=>array('label'=>'Concatenate all values with |','selected'=>''),
            'min'=>array('label'=>'Minimum value','selected'=>''),
            'max'=>array('label'=>'Maximum value','selected'=>''),
            'first'=>array('label'=>'Value from first instance','selected'=>''),
            'last'=>array('label'=>'Value from last instance','selected'=>'')
        );
        $reshapeInstanceOptions[$report->reshape_instance]['selected'] = $selected;

        $step5MarkupE = $step5MarkupI = '';
        if ($Proj->longitudinal) { 
            $step5MarkupE .= '<td class="labelrc" style="width:120px;">Events</td>';
            $step5MarkupE .= '<td class="labelrc" colspan="3">';
            $step5MarkupE .= '<div class="nowrap">';
            $step5MarkupE .= '<select class="x-form-text x-form-field sort-dropdown" style="width:100%;max-width:260px;" name="rpt-reshape-event">';
            foreach ($reshapeEventOptions as $opt => $prop) {
                $step5MarkupE .= '<option value="'.$opt.'" '.$prop['selected'].'>'.$prop['label'].'</option>';
            }
            $step5MarkupE .= '</select></div></td>';
        }
        if ($Proj->hasRepeatingFormsEvents()) {
            $step5MarkupI .= '<td class="labelrc" style="width:120px;">Instances</td>';
            $step5MarkupI .= '<td class="labelrc" colspan="3">';
            $step5MarkupI .= '<div class="nowrap">';
            $step5MarkupI .= '<select class="x-form-text x-form-field sort-dropdown" style="width:100%;max-width:260px;" name="rpt-reshape-instance">';
            foreach ($reshapeInstanceOptions as $opt => $prop) {
                $step5MarkupI .= '<option value="'.$opt.'" '.$prop['selected'].'>'.$prop['label'].'</option>';
            }
            $step5MarkupI .= '</select></div></td>';
        }
        ?>
        <style type="text/css">.ext-rpt-step5 { display:table-row; } </style>
        <script type="text/javascript">
            $(document).ready(function(){
                $('#create_report_table tbody')
                    .append('<tr class="nodrop ext-rpt-step5">'+
                        '<td class="labelrc create_rprt_hdr" colspan="4" style="padding:0;background:#fff;border-left:0;border-right:0;height:45px;" valign="bottom">'+
                        '<div style="color:#444;position:relative;top:10px;background-color:#e0e0e0;border:1px solid #ccc;border-bottom:1px solid #ddd;float:left;padding:5px 8px;"><i class="fas fa-cube mr-1" title="External Module: Extended Reports"></i>STEP 5</div></td></tr>'
                    )
                    .append('<tr class="nodrop ext-rpt-step5">'+
                        '<td class="labelrc create_rprt_hdr" colspan="4" valign="bottom"><i class="fas fa-shapes mx-1" style="vertical-align:top"></i>Row-Per-Record Reshaping <span style="font-weight:normal;">(optional)</span></td></tr>'
                    )
                    .append('<tr class="nodrop ext-rpt-step5"><?=$step5MarkupE?></tr>')
                    .append('<tr class="nodrop ext-rpt-step5"><?=$step5MarkupI?></tr>');
                if ($('select[name=rpt-reshape-event],select[name=rpt-reshape-instance]').length==2) {
                    // both longitudinal and with instances -> must select either no reshaping or option for both
                    $('#save-report-btn').attr('onclick', null);
                    $('#save-report-btn').on('click', function(){
                            let reshapeEventOpt = $('select[name=rpt-reshape-event]').val();
                            let reshapeInstanceOpt = $('select[name=rpt-reshape-instance]').val();
                            if (reshapeEventOpt=='' && reshapeInstanceOpt!=='') {
                                simpleDialog('For row-per-record reshaping an option must be selected for both events and instances.<br>Event option not selected.');
                            } else if (reshapeEventOpt!=='' && reshapeInstanceOpt=='') {
                                simpleDialog('For row-per-record reshaping an option must be selected for both events and instances.<br>Instance option not selected.');
                            } else {
                                saveReport(<?=$report->report_id?>);
                            }
                        });

                }
            });
        </script>
        <?php
    }

    /** 
     * Look up what the next report id will be in the redcap_reports table
     */
    protected function getNextReportId() {
        global $db;
        $result = $this->query("select auto_increment as next_report_id from information_schema.tables where table_schema=? and table_name='redcap_reports'",[$db]);
        return $result->fetch_assoc()['next_report_id'];
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

        $rptConfig = $this->getSubSettings('report-config');
        $hasEMConfig = false;
        foreach($rptConfig as $idx => $rpt) {
            if ($rpt['report-id']==$report_id) {
                $hasEMConfig = true;
                break;
            }
        }
        $reportIndex = ($hasEMConfig) ? $idx : count($rptConfig);
        
        // tweaks to $_POST for settings that are not submitted from client
        if (array_key_exists('rpt-sql', $_POST) && $_POST['rpt-sql']!='') { 
            if ($this->getUser()->isSuperUser()) {
                $_POST['rpt-is-sql'] = true;
                $_POST['advanced_logic'] = '['.$Proj->table_pk.']=""'; // never return any records if somehow run without sql

                if (array_key_exists('rpt-sql-disable-dag-filter', $_POST) && $_POST['rpt-sql-disable-dag-filter']=='on') {
                    $_POST['rpt-sql-disable-dag-filter'] = true;
                }

                $_POST['rpt-sql'] = rtrim(trim($this->stripTabs($_POST['rpt-sql'])), ";");
                if (!preg_match("/^select\s/i", $_POST['rpt-sql'])){ 
                    throw new \Exception('SQL is not a SELECT query \n<br> '.$_POST['rpt-sql']);
                }
            } else {
                unset($_POST['rpt-sql']);
                unset($_POST['rpt-sql-disable-dag-filter']);
            }
        }

        // check whether $_POST has any extended attributes to save
        $numAttrVals = 0;
        foreach(array_keys($_POST) as $key) {
            if (strpos($key, 'rpt-')===0 && !empty($_POST[$key])) {
                $numAttrVals++;
            }
        }

        // if report exists in em config AND there are no non-empty rpt- attribute values - can return
        if (!$hasEMConfig && $numAttrVals==0) return;

        // if report exists in em config and only empty rpt- attr values submitted then record in em config
        // if report not yet in em config and non-empty rpt- attr values submitted then record in em config
        $projectSettings = $this->getProjectSettings();
        $config = $this->getConfig();
        
        $projectSettings['report-config'][$reportIndex] = true;

        foreach($config['project-settings'] as $projectSettingArray) {
            if ($projectSettingArray['key']!=='report-config') continue;
            foreach($projectSettingArray['sub_settings'] as $subSettingAttrs) {
                $settingKey = $subSettingAttrs['key'];
                if ($settingKey==='report-id') {
                    $projectSettings['report-id'][$reportIndex] = "$report_id";
                } else if (array_key_exists($subSettingAttrs['key'], $_POST)) {
                    $projectSettings[$settingKey][$reportIndex] = $this->escape($_POST[$settingKey]);
                } 
            }
        }
        $this->setProjectSettings($projectSettings);

        $this->log("save report id $report_id", $this->escape(array_merge(['is-new'=>!$hasEMConfig], (is_null($rpt))?$this->escape($_POST):$rpt)));
        
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
        foreach($config['project-settings'] as $projectSettingArray) {
            if ($projectSettingArray['key']!=='report-config') continue;
            foreach($projectSettingArray['sub_settings'] as $subSettingAttrs) {
                $settingKey = $subSettingAttrs['key'];
                $settingValue = ($subSettingAttrs['type']=='checkbox') ? (bool) $rpt[$settingKey] : $rpt[$settingKey];
                $projectSettings[$settingKey][] = $settingValue;
            }
        }

        $this->setProjectSettings($projectSettings);

        $this->log("copy report in $report_id", $this->escape(array_merge(['copy-of'=>$report_id], $rpt)));

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
        $projectSettings['report-config'] = array_values($projectSettings['report-config']);

        foreach($config['project-settings'] as $projectSettingArray) {
            if ($projectSettingArray['key']!=='report-config') continue;
            foreach($projectSettingArray['sub_settings'] as $subSettingAttrs) {
                $settingKey = $subSettingAttrs['key'];
                unset($projectSettings[$settingKey][$erIdx]);
                if (is_array($projectSettings[$settingKey])) $projectSettings[$settingKey] = array_values($projectSettings[$settingKey]); // #27 #29
            }
        }

        $this->setProjectSettings($projectSettings);

        $this->log("delete report id $report_id", ['report-id'=>$report_id, 'extended-report-index'=>$erIdx]);

        return null;
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
		if (empty($_POST['report_id'])) return;

        $format = (isset($_POST['format'])) ? $this->escape($_POST['format']) : 'xml';

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
        $report_id = intval($_POST['report_id']);
        $result = $this->query("select project_id from redcap_reports where report_id=?",[$report_id]);
        while($row = $result->fetch_assoc()){
            $pid = $row['project_id'];
        }

		if (empty($pid)) return;

        $report = new Report($pid, $report_id, $this);

        if (!$report->is_extended) return; // no extensions on this report - return

        $url = APP_PATH_WEBROOT_FULL.'api/';
        $token = trim($this->escape($_POST['token']));
        $returnFormat = (isset($_POST['returnFormat'])) ? $this->escape($_POST['returnFormat']) : $format;
        $csvDelimiter = (isset($_POST['csvDelimiter'])) ? $this->escape($_POST['csvDelimiter']) : null;
        $decimalCharacter = (isset($_POST['decimalCharacter'])) ? $this->escape($_POST['decimalCharacter']) : null;
        $params = array(
            'token' => $token,
            'content' => $this->escape($_POST['content']),
            'format' => 'json',
            'returnFormat' => $returnFormat,
            'report_id' => $report_id,
            'extended_reports_background_call' => 1
        );

        $result = \http_post($url, $params);
        if (is_null($result)) return; // error with call (e.g. permissions) - let it proceed and fail again for the user in the usual way
        $resultArray = \json_decode($result, true);
        if (array_key_exists('error', $resultArray)) return; // error with call (e.g. permissions) - let it proceed and fail again for the user in the usual way

        // set project context
        global $Proj, $project_id, $longitudinal;
        $Proj = new \Project($pid, true);
        $project_id = $Proj->project_id;
        $longitudinal = $Proj->longitudinal;
        if (!defined('PROJECT_ID')) {
            define('PROJECT_ID', $project_id);
        }
        
        if (!defined("USERID")) {
            // set USERID from token in case needed for smart var piping 
            $ur = $this->query("select ur.username, ui.super_user from redcap_user_rights ur inner join redcap_user_information ui on ur.username=ui.username where ur.api_token=? and project_id=? and user_suspended_time is null limit 1", [ $token, $project_id ]);
            while($row = $ur->fetch_assoc()){
                $user = $row['username'];
                $super = $row['super_user'];
            }
            define('USERID', $user);
            if (!defined("SUPER_USER")) define("SUPER_USER", $super);
		}

        try {
            list($data_content, $num_records_returned) = $report->doExtendedReport($format, null, $csvDelimiter, $decimalCharacter);
        } catch (\Exception $ex) {
            switch ($returnFormat) {
                case 'csv': $data_content = $ex->getMessage(); break;
                case 'json': $data_content = '{error:'.$ex->getMessage().'}'; break;
                default: // xml
                    $data_content = '<?xml version="1.0" encoding="UTF-8" ?><error>'.$ex->getMessage().'</error>';
                    break;
            }
        }
        if (!defined("REDCAP_API_NO_EXIT")) define("REDCAP_API_NO_EXIT", true); // this prevents sendResponse() doing exit(), which causes EM framework to throw exceptions
        \RestUtility::sendResponse(200, $data_content, $format);
        $this->exitAfterHook();
        return;
    }

    public function stripTabs($str, $replace="  ") {
        /*// not sure why these aren't working!
        $s1 = str_replace('\\t','  ',$str);
        $s2 = str_replace('\t','  ',$str);
        $s3 = str_replace('	','  ',$str);
        $s4 = preg_replace('/\t/g', '  ', $str);
        $s5 = preg_replace('/\s/g', '  ', $str);*/
        $rtn = "";
        $split = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($split as $char) {
            $rtn .= (ord($char)===9) ? $replace : $char;
        }
        return $rtn;
    }

    /**
     * getUserRights($username=null)
     * Patched version of REDCap::getUserRights() developer method that until at least v14.1.6 does not return form-level export permissions
     */
    public static function getUserRights($username=null)
	{
		global $data_resolution_enabled, $Proj;
		// Make sure we are in the Project context
		if (empty($Proj)) return; // self::checkProjectContext(__METHOD__);
		// Get rights for this user or all users in project
		$rights = \UserRights::getPrivileges(PROJECT_ID, $username);
		$rights = $rights[PROJECT_ID];
		// Loop through each user
		if (!is_array($rights)) return [];
		foreach ($rights as $this_user=>$attr) {
			// Parse form-level rights
			$allForms = explode("][", substr(trim($attr['data_entry']), 1, -1));
			foreach ($allForms as $forminfo)
			{
				list($this_form, $this_form_rights) = explode(",", $forminfo, 2);
				$rights[$this_user]['forms'][$this_form] = $this_form_rights;
				unset($rights[$this_user]['data_entry']);
			}
            $allFormsExport = explode("][", substr(trim($attr['data_export_instruments']), 1, -1));
			foreach ($allFormsExport as $forminfoExport)
			{
				list($this_form_export, $this_form_export_rights) = explode(",", $forminfoExport, 2);
				$rights[$this_user]['forms_export'][$this_form_export] = $this_form_export_rights;
				unset($rights[$this_user]['data_export_instruments']);
			}
			// Data resolution workflow: disable rights if module is disabled
			if ($data_resolution_enabled != '2') $rights[$this_user]['data_quality_resolution'] = '0';
		}
		// Return rights
		return $rights;
	}

    /**
     * replaceLabels()
     * Fields with @REPORT-LABEL="?" get alternative field label in reports
     */
    protected function replaceLabels(): void {
        global $Proj;
        foreach ($Proj->metadata as $fldName => $fldAttr) {
            if (str_contains($fldAttr['misc'],static::TAG_REPORT_LABEL)) {
                $altLabel = \Form::getValueInQuotesActionTag($Proj->metadata[$fldName]['misc'], static::TAG_REPORT_LABEL);
                $Proj->metadata[$fldName]['element_label'] = $altLabel;
            }
        }
	}
}