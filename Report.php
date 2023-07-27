<?php
/**
 * \REDCap External Module: ExtendedReport
 * Class for properties and methods supporting report extensions.
 * @author Luke Stevens, Murdoch Children's Research Institute
 */
namespace MCRI\ExtendedReports;

class Report
{
    protected const DEFAULT_CSV_DELIMITER = ',';
    protected const DEFAULT_CSV_LINE_END = PHP_EOL;
    protected const DEFAULT_ESCAPE_CHAR = '"';
    protected const DEFAULT_DECIMAL_CHAR = '.';
    protected static $csvInjectionChars = array("-", "@", "+", "=");
    protected static $reportAttributeMap = array(
        "rpt-is-sql"=>"is_sql", 
        "rpt-sql-disable-dag-filter"=>"sql_disable_dag_filter", 
        "rpt-sql"=>"sql_query", 
        "rpt-reshape-event"=>"reshape_event", 
        "rpt-reshape-instance"=>"reshape_instance"
    );
    protected $project_id;
    public $report_id;
    protected $module;
    protected $report_attr = array(); // will be array of values read from the report's record in redcap_reports
    protected $dag_names = array();
    protected $record_labels = array();
    public $is_extended = false;
    public $is_sql = false;
    public $sql_disable_dag_filter = false;
    public $sql_query = '';
    public $is_reshaped = false;
    public $reshape_event = null;
    public $reshape_instance = null;

    public function __construct($project_id, $report_id, ExtendedReports $module) {
        $this->report_id = $report_id;
        $this->module = $module;
        $this->setProjectId($project_id, $report_id);
        $this->readExtendedAttributes($this->report_id);
    }

    protected function setProjectId($project_id, $report_id=null) {
        if (empty($project_id)) {
            // report id but no project id can happen e.g. on public report page
            $q = $this->module->query("select project_id from redcap_reports where report_id=?", [$this->report_id]);
            while ($result = $q->fetch_assoc($q)) {
                $project_id = $result['project_id'];
            }
        }
        $this->project_id = intval($project_id);
    }

    /**
     * readExtendedAttributes
     * Read any module settings for the specified report id
     */
    protected function readExtendedAttributes($report_id) {
        $this->is_extended = false;
        $rptConfig = $this->module->getSubSettings('report-config', $this->project_id);
        $hasExtConfig = false;
        foreach($rptConfig as $rpt) {
            if ($rpt['report-id']==$report_id) {
                $hasExtConfig = true; break; 
            }
        }
        if (!$hasExtConfig) return;

        // guard against out-of-date config e.g. after project not longitudinal anymore
        global $Proj;
        $p = $Proj ?? new \Project($this->project_id);

        // return the report as having extended attributes if ANY rpt- attributes is not empty 
        // (if all rpt- attrs are empty then not an extended report)
        foreach ($rpt as $attr => $val) {
            if (array_key_exists($attr, static::$reportAttributeMap) && !empty($val)) {
                $this->is_extended = true;
                $instanceVar = static::$reportAttributeMap[$attr];
                switch ($attr) {
                    case 'rpt-is-sql': 
                    case 'rpt-sql-disable-dag-filter': 
                        $val = (bool)$val;
                        break;
                    case 'rpt-reshape-event':
                        if (!$p->longitudinal && !empty($val)) $val = '';
                        break;
                    case 'rpt-reshape-instance':
                        if (!$p->hasRepeatingFormsEvents() && !empty($val)) $val = '';
                        break;
                    case 'rpt-sql':
                        default:
                        break;
                }
                $this->$instanceVar = $val;
            }
        }
    }

    /**
     * viewReport()
     * Basically a copy of redcap_v13.4.12/DataExport/report_ajax.php but 
     * 1. replacing list ($report_table, $num_results_returned) = DataExport::doReport($_POST['report_id'], 'report', 'html',
     *    with $this->doExtendedReport()
     * 2. Not showing "Total number of records queried
     * 
     * Return HTML content for ajax call to report_ajax.php
     * Will include display content such as: 
     * - export/print buttons
     * - num records returned
     * - html table of results
     */
    public function viewReport() {
        global $lang, $user_rights, $enable_plotting, $downloadFilesBtnEnabled;
        
        ## PERFORMANCE: Kill any currently running processes by the current user/session on THIS page
        \System::killConcurrentRequests(1);

        // Get report info
        $report_id = $_POST['report_id'];
        if ($report_id != 'ALL' && $report_id != 'SELECTED') {
            $report_id = (int)$report_id;
        }
        $report = \DataExport::getReports($report_id);

        // Checks for public reports
        if (\DataExport::isPublicReport() && !$this->canViewPublic($report)) {
            return;
        }

        $script_time_start = microtime(true);
        
        // Build dynamic filter options (if any)
        $dynamic_filters = ''; // live filters not implemented for extended reports \DataExport::displayReportDynamicFilterOptions($_POST['report_id']);
        /*// Obtain any dynamic filters selected from query string params
        list ($liveFilterLogic, $liveFilterGroupId, $liveFilterEventId) = \DataExport::buildReportDynamicFilterLogic($_POST['report_id']);
        // Total number of records queried
        $totalRecordsQueried = Records::getCountRecordEventPairs();
        // For report A and B, the number of results returned will always be the same as total records queried
        if ($_POST['report_id'] == 'ALL' || ($_POST['report_id'] == 'SELECTED' && (!isset($_GET['events']) || empty($_GET['events'])))) {
            if ($user_rights['group_id'] == '') {
                $num_results_returned = $totalRecordsQueried;
            } else {
                $num_results_returned = Records::getCountRecordEventPairs($user_rights['group_id']);
            }
        }*/
        // Get html report table
        /*list ($report_table, $num_results_returned) = DataExport::doReport($_POST['report_id'], 'report', 'html', false, false, false, false,
                                                        false, false, false, false, false, false, false,
                                                        (isset($_GET['instruments']) ? explode(',', $_GET['instruments']) : array()),
                                                        (isset($_GET['events']) ? explode(',', $_GET['events']) : array()),
                                                        false, false, false, true, true, $liveFilterLogic, $liveFilterGroupId, $liveFilterEventId);*/
        list($report_table, $num_results_returned) = $this->doExtendedReport('html');
        
        if ($this->is_sql) {
            $enable_plotting = false; // no Stats & Charts option for sql report
            ?>
            <style type="text/css">
                #exportFormatDialog div:nth-child(1) { display: none; }
                #exportFormatForm table td:nth-child(2) { display: none; } /* hide de-identification options */
                #export_choices_table tr:nth-child(n+3) { display: none; } /* hide export options except CSV Raw/Labels */
                #export_dialog_data_format_options > div:first-of-type { display: none } /* export 0 for grey status */
                #export_dialog_data_format_options > div:nth-of-type(3) { display: none } /* force decimal character */
            </style>
            <?php
        }
        
        // Report B only: If repeating instruments exist, and we're filtering using a repeating instrument, then the row counts can get skewed and be incorrect. This fixes it.
        //if ($_POST['report_id'] == 'SELECTED' && (!isset($_GET['events']) || empty($_GET['events']))) {
        //    $totalRecordsQueried = $num_results_returned;
        //}
        // Check report edit permission
        $report_edit_access = defined("SUPER_USER") ? SUPER_USER : 0;
        if ((!defined("SUPER_USER") || !SUPER_USER) && is_numeric($report_id)) {
            $reports_edit_access = \DataExport::getReportsEditAccess(USERID, $user_rights['role_id'], $user_rights['group_id'], $report_id);
            $report_edit_access = in_array($report_id, $reports_edit_access);
        }
        $script_time_total = round(microtime(true) - $script_time_start, 1);

        // Display report and title and other text
        print  	"<div id='report_div' style='margin:0 0 20px;'>" .
			\RCView::div(array('style'=>''),
				\RCView::div(array('class'=>'', 'style'=>'float:left;width:350px;padding-bottom:5px;'),
					(isset($_GET['__report'])
						? \RCView::div(array('class'=>'text-secondary fs12'),
							$lang['custom_reports_02'] .
							\RCView::b(array('style'=>'margin-left:5px;'),
								\User::number_format_user($num_results_returned)
							)
						)
						: \RCView::div(array('class'=>'font-weight-bold'),
							$lang['custom_reports_02'] .
							\RCView::span(array('style'=>'margin-left:5px;color:#800000;font-size:15px;'),
								\User::number_format_user($num_results_returned)
							)
						)
					).
					(isset($_GET['__report']) ? "" :
						\RCView::div(array(),
							''/*$lang['custom_reports_03'] .
							\RCView::span(array('id'=>'records_queried_count', 'style'=>'margin-left:5px;'),
								\User::number_format_user($totalRecordsQueried)
							) .
							(!$longitudinal ? "" :
								\RCView::div(array('class'=>'fs11 mt-1', 'style'=>'color:#999;font-family:tahoma,arial;'),
									$lang['custom_reports_09']
								)
							)*/
						) .
						\RCView::div(array('class'=>'fs11 mt-1 d-print-none', 'style'=>'color:#6f6f6f;'),
							$lang['custom_reports_19']." $script_time_total ".$lang['control_center_4469']
						)
					)
				) .
				\RCView::div(array('class'=>'d-print-none', 'style'=>'float:left;'),
					// Buttons: Stats, Export, Print, Edit
					(isset($_GET['__report']) ?
						// Public report buttons
						\RCView::div(array(),
							// Download Report Files
							(!($user_rights['data_export_tool'] != '0' && $downloadFilesBtnEnabled) ? '' :
								\RCView::button(array('class'=>'hidden download-files-btn report_btn jqbuttonmed fs12 text-successrc', 'onclick'=>"window.location.href = '".APP_PATH_SURVEY_FULL."?__report={$_GET['__report']}&__passthru=".urlencode("DataExport/file_export_zip.php")."'+getLiveFilterUrl();"),
									'<i class="fa-solid fa-circle-down"></i> ' .$lang['report_builder_220']
								)
							)
						)
						:
						// Private report buttons
						\RCView::div(array(),
							// Public link
							(!($report['is_public'] && $user_rights['user_rights']&& $GLOBALS['reports_allow_public'] > 0) ? "" :
								\RCView::a(array('href'=>($report['short_url'] == "" ? APP_PATH_WEBROOT_FULL.'surveys/index.php?__report='.$report['hash'] : $report['short_url']), 'target'=>'_blank', 'class'=>'text-primary fs12 nowrap me-3 ms-1 align-middle'),
									'<i class="fas fa-link"></i> ' .$lang['dash_35']
								)
							) .
							// Stats & Charts button
							(!$user_rights['graphical'] || !$enable_plotting ? '' :
								\RCView::button(array('class'=>'report_btn jqbuttonmed', 'onclick'=>"window.location.href = '".APP_PATH_WEBROOT."DataExport/index.php?pid=".PROJECT_ID."&report_id={$report_id}&stats_charts=1'+getInstrumentsListFromURL()+getLiveFilterUrl();", 'style'=>'color:#800000;font-size:12px;'),
									'<i class="fas fa-chart-bar"></i> ' .$lang['report_builder_78']
								) .
                                \RCView::SP
							) .
							// Export Data button
							($user_rights['data_export_tool'] == '0' ? '' :
								\RCView::button(array('class'=>'report_btn jqbuttonmed', 'onclick'=>"showExportFormatDialog('{$report_id}');", 'style'=>'color:#000066;font-size:12px;'),
									'<i class="fas fa-file-download"></i> ' .$lang['report_builder_48']
								)
							) .
              // Download Report Files
							(!($user_rights['data_export_tool'] != '0' && $downloadFilesBtnEnabled) ? '' :
								\RCView::button(array('class'=>'hidden download-files-btn report_btn jqbuttonmed fs12 text-successrc', 'onclick'=>"window.location.href = '".APP_PATH_WEBROOT."DataExport/file_export_zip.php?pid=".PROJECT_ID."&report_id={$report_id}'+getInstrumentsListFromURL()+getLiveFilterUrl();"),
                                    '<i class="fa-solid fa-circle-down"></i> ' .$lang['report_builder_220']
                                ) .
                                \RCView::SP
                            ) .
							// Print link
							\RCView::button(array('class'=>'report_btn jqbuttonmed', 'onclick'=>"$('div.dataTables_scrollBody, div.dataTables_scrollHead').css('overflow','visible');$('.DTFC_Cloned').hide();setProjectFooterPosition();window.print();", 'style'=>'font-size:12px;'),
								\RCView::img(array('src'=>'printer.png', 'style'=>'height:12px;width:12px;')) . $lang['custom_reports_13']
							) .
							\RCView::SP .
							(($report_id == 'ALL' || $report_id == 'SELECTED' || !$user_rights['reports'] || (is_numeric($report_id) && !$report_edit_access)) ? '' :
								// Edit report link
								\RCView::button(array('class'=>'report_btn jqbuttonmed', 'onclick'=>"window.location.href = '".APP_PATH_WEBROOT."DataExport/index.php?pid=".PROJECT_ID."&report_id={$report_id}&addedit=1';", 'style'=>'font-size:12px;'),
									'<i class="fas fa-pencil-alt fs10"></i> ' .$lang['custom_reports_14']
								)
							)
						)
					) .
					// Dynamic filters (if any)
					$dynamic_filters
				) .
				\RCView::div(array('class'=>'clear'), '')
			) .
			// Report title
			\RCView::div(array('id'=>'this_report_title', 'style'=>'margin:10px 0 '.($report['description'] == '' ? '8' : '0').'px;padding:5px 3px;color:#800000;font-size:18px;font-weight:bold;'),
				// Title
				\REDCap::filterHtml($report['title'])
			) .
			// Report description (if has one)
			($report['description'] == '' ? '' : 
				\RCView::div(array('id'=>'this_report_description', 'style'=>'max-width:1100px;padding:5px 3px;line-height:15px;'),
					\Piping::replaceVariablesInLabel(\REDCap::filterHtml($report['description']))
				) .
				// Output the JavaScript to display all Smart Charts on the page
				\Piping::outputSmartChartsJS()
			) .
			// Report table
			$report_table .
		"</div>";
    }

    protected function canViewPublic($report) {
        global $Proj, $lang, $secondary_pk, $custom_record_label;
        $report_id = $report['report_id'] ?? $_GET['report_id'];
        \DataExport::checkReportHash($report_id);
        $report = \DataExport::getReports($report_id);
        // Make sure user has access to this report if viewing inside a project
        $noAccess = false;
        $type = '';
        $errorMsg = '';
        if ($report['is_public'] != '1') {
            // If viewing a public report link that is no longer set as "public", return error message
            $type = 'error';
            $noAccess = true;
            $errorMsg = $lang['report_builder_184'];
        } else {
            $reports = \DataExport::getReports($report_id);
            // List of fields to verify if field is phi
            $fields = $reports['fields'];
            foreach ($fields as $field_name) {
                if ($Proj->metadata[$field_name]['field_phi'] == '1') {
                    $type = 'notice';
                    $noAccess = true;
                    $errorMsg = $lang['report_builder_188'];
                    break;
                }
            }
            // If using Custom Record Label/Secondary Unique Field, return error if any of those fields are identifiers
            if ($noAccess == false && in_array($Proj->table_pk, $fields) && trim($secondary_pk.$custom_record_label) != '') {
                if ($Proj->metadata[$secondary_pk]['field_phi'] == '1') {
                    // Secondary Unique Field is an Identifier
                    $type = 'notice';
                    $noAccess = true;
                    $errorMsg = $lang['report_builder_217'];
                } elseif (trim($custom_record_label) != '') {
                    // Get the variables in $custom_record_label and then check if any are Identifiers
                    $custom_record_label_fields = array_unique(array_keys(getBracketedFields($custom_record_label, true, true, true)));
                    foreach ($custom_record_label_fields as $field_name) {
                        if ($Proj->metadata[$field_name]['field_phi'] == '1') {
                            $type = 'notice';
                            $noAccess = true;
                            $errorMsg = $lang['report_builder_218'];
                            break;
                        }
                    }
                }
            }
        }
	    // Display the error if necessary
        if ($noAccess) {
            print \RCView::div(array('class' => 'red my-5'), \RCView::b(($type == 'error') ? $lang['global_01'] : $lang['global_03'] . $lang['colon']) . " " . $errorMsg);
        }
        return !$noAccess;
    }

    /**
     * exportReport($extendedAttributes)
     * 
     * OK - this is a bit complicated!
     * 
     * Rather than having to basically recreate (and maintain!) a copy of DataExport/data_export_ajax.php ...
     * 
     * > we have intercepted the client's post to redcap_vx.y.z/DataExport/data_export_ajax.php 
     * > we know the report requested has "extended properties" (is custom sql, needs reshaping etc.)
     * > only CSV requests handled this way - stats package files remain unmodified
     * 
     * NOW
     * > do a behind-the scenes post to the same script to get the report to run unmodified
     *   - for an sql report this just produces an empty result
     *   - for an ordinary report we will get the unmodified csv file
     *   - need to pass through a param in $_GET so as to not end up here again in an infinite loop!
     * > capture the title and content html for the export dialog and determine the reslut file doc id
     * > SQL report:
     *   - run the SQL report and get the CSV output - a new file
     * > Extended Report
     *   - read the data from the regular file
     *   - apply selected extended processing and generate reshaped CSV output in a new file
     * > replace the file doc id in the html content with the new file's id
     * > return title and content to client, now containing the new file id in the download link
     */
    public function exportReport() {
        global $Proj, $lang;

        $url = APP_PATH_WEBROOT_FULL."redcap_v".REDCAP_VERSION."/DataExport/data_export_ajax.php?pid=".$Proj->project_id."&xml_metadata_options=&extended_report_hook_bypass=1";
        $timeout = null;
        $content_type = 'application/x-www-form-urlencoded';
        $basic_auth_user_pass = '';

        $cookieString = '';
        foreach ($_COOKIE as $key => $value) {
            $cookieString.=\REDCap::escapeHtml($key)."=".\REDCap::escapeHtml($value)."; ";
        }

        $params = array();
        foreach ($_POST as $key => $value) {
            $params[\REDCap::escapeHtml($key)] = \REDCap::escapeHtml($value);
        }

        $params['redcap_csrf_token'] = $this->module->getCSRFToken();
        $param_string = http_build_query($params, '', '&');

        $curlpost = curl_init();
        curl_setopt($curlpost, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curlpost, CURLOPT_VERBOSE, 0);
        curl_setopt($curlpost, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curlpost, CURLOPT_AUTOREFERER, true);
        curl_setopt($curlpost, CURLOPT_MAXREDIRS, 10);
        curl_setopt($curlpost, CURLOPT_URL, $url);
        curl_setopt($curlpost, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlpost, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curlpost, CURLOPT_POSTFIELDS, $param_string);
        if (!sameHostUrl($url)) {
            curl_setopt($curlpost, CURLOPT_PROXY, PROXY_HOSTNAME); // If using a proxy
            curl_setopt($curlpost, CURLOPT_PROXYUSERPWD, PROXY_USERNAME_PASSWORD); // If using a proxy
        }
        curl_setopt($curlpost, CURLOPT_FRESH_CONNECT, 1); // Don't use a cached version of the url
        if (is_numeric($timeout)) {
            curl_setopt($curlpost, CURLOPT_CONNECTTIMEOUT, $timeout); // Set timeout time in seconds
        }

        curl_setopt($curlpost, CURLOPT_COOKIE, $cookieString);

        $response = curl_exec($curlpost);
        $info = curl_getinfo($curlpost);
        curl_close($curlpost);

        if (isset($info['http_code']) && ($info['http_code'] == 404 || $info['http_code'] == 407 || $info['http_code'] >= 500)) $response = "{title:'{$lang['global_01']}',content:'$response'}";

        $dialog = \json_decode($response, true);
        $dialog_title = (array_key_exists('title',$dialog)) ? $dialog['title'] : $lang['global_01'];
        $dialog_content = (array_key_exists('content',$dialog)) ? $dialog['content'] : $response;

        /******************************************************************************
         * Extract the doc id of the generated file (e.g. containing non-reshaped data)
         ******************************************************************************/
        $pattern = (\REDCap::versionCompare(REDCAP_VERSION, '13.0.0', '>='))
            ? "\?pid={$Proj->project_id}&amp;route=FileRepositoryController:download&amp;id=(\d+)\">"
            : "FileRepository\/file_download.php\?pid={$Proj->project_id}&amp;id=(\d+)\">";
        $matches = array();
        $match = preg_match("/$pattern/", $dialog_content, $matches);
        
        if ($match) {
            $doc_id = (count($matches)>0) ? $matches[1] : false;
            /******************************************************************************
             * Generate a replacement file with sql results or data reshaped etc.
             ******************************************************************************/

            // Save defaults for CSV delimiter and decimal character
            $csvDelimiter = (isset($_POST['csvDelimiter']) && \DataExport::isValidCsvDelimiter($_POST['csvDelimiter'])) ? $_POST['csvDelimiter'] : ",";
            \UIState::saveUIStateValue('', 'export_dialog', 'csvDelimiter', $csvDelimiter);
            if ($csvDelimiter == 'tab') $csvDelimiter = "\t";
            $decimalCharacter = isset($_POST['decimalCharacter']) ? $_POST['decimalCharacter'] : '';
            \UIState::saveUIStateValue('', 'export_dialog', 'decimalCharacter', $decimalCharacter);

            list($data_content, $num_records_returned) = $this->doExtendedReport($_POST['export_format'], $doc_id, $csvDelimiter, $decimalCharacter);

            $sql = "select docs_name from redcap_docs where docs_id = ?";
            $q = $this->module->query($sql, [$doc_id]);
            $csv_filename = $q->fetch_assoc($q)['docs_name'];

            $data_edoc_id = \DataExport::storeExportFile($csv_filename, trim($data_content), true, false);

            // replace original doc id for download/sendit links with new doc id
            $dialog_content = str_replace("&amp;id=$doc_id","&amp;id=$data_edoc_id",$dialog_content);
            $dialog_content = str_replace("displaySendItExportFile($doc_id);","displaySendItExportFile($data_edoc_id);",$dialog_content);
            \REDCap::logEvent('External Module: Extended Reports',"Extended properties applied to report output doc_id=$doc_id: new doc_id=$data_edoc_id");
        } else {
            $data_edoc_id = false;
        }
        if ($data_edoc_id === false) $dialog_content = "<p style='color:red'>An error occurred in processing the extended properties of this report. The file for download is unmodifed.</p>".$dialog_content;


        print \json_encode_rc(array('title'=>$dialog_title, 'content'=>$dialog_content));
    }
    
    public function doExtendedReport($format, $doc_id=null, $csvDelimiter=null, $decimalCharacter=null) {
        global $Proj;
        $csvDelimiter = (empty($csvDelimiter)) ? \UIState::getUIStateValue('', 'export_dialog', 'csvDelimiter') : $csvDelimiter;
        $csvDelimiter = (empty($csvDelimiter)) ? static::DEFAULT_CSV_DELIMITER : $csvDelimiter;
        $decimalCharacter = (empty($decimalCharacter)) ? \UIState::getUIStateValue('', 'export_dialog', 'decimalCharacter') : $decimalCharacter;
        $decimalCharacter = (empty($decimalCharacter)) ? static::DEFAULT_DECIMAL_CHAR : $decimalCharacter;

        $sql = 'select * from redcap_reports where report_id=?';
        $q = $this->module->query($sql, [$_POST['report_id']]);
        $this->report_attr = $q->fetch_assoc();

        $rows = array();
        if ($this->is_sql) {
            list($rows, $headers, $hasSplitCbOrInstances) = $this->doSqlReport();
            $this->report_attr['combine_checkbox_values'] = true;
        } else {
            list($rows, $headers, $hasSplitCbOrInstances) = $this->doReshapedReport($format);
        }

        if (is_array($rows)) {
            $num_results_returned = count($rows);
        } else {
            $this->module->log($rows);
            $num_results_returned = 0;
        }
        $return_content = '';

        if ($format=='html') {
            $report_table = '';
            if ($num_results_returned === 0) {
                $report_table='<table id="report_table" class="dataTable cell-border" style="table-layout:fixed;margin:0;font-family:Verdana;font-size:11px;"><thead><tr></tr></thead><tbody><tr class="odd"><td style="color:#777;border:1px solid #ccc;padding:10px 15px !important;" colspan="0">No results were returned</td></tr></tbody></table>';
            } else {
                //"<table id='report_table' class='dataTable cell-border' style='table-layout:fixed;margin:0;font-family:Verdana;font-size:11px;'><thead><tr><th>Study ID<div class="rpthdr">record_<wbr>id</div></th><th>Event Name<div class="rpthdr">redcap_<wbr>event_<wbr>name</div></th><th>Repeat Instrument<div class="rpthdr">redcap_<wbr>repeat_<wbr>instrument</div></th><th>Repeat Instance<div class="rpthdr">redcap_<wbr>repeat_<wbr>instance</div></th></tr></thead><tr class="odd"><td><a href="/redcap_v10.7.1/DataEntry/record_home.php?pid=244&amp;id=1&amp;arm=1" class="rl">1</a>&nbsp; <span class="crl">Alice Adams</span></td><td>Person</td><td class='nodesig'></td><td class='nodesig'></td></tr><tr class="even"><td><a href="/redcap_v10.7.1/DataEntry/index.php?pid=244&amp;id=1&amp;page=event&amp;event_id=1494&amp;instance=1" class="rl">1</a>&nbsp; <span class="crl">Alice Adams</span></td><td>Event</td><td></td><td>1</td></tr><tr class="odd"><td><a href="/redcap_v10.7.1/DataEntry/record_home.php?pid=244&amp;id=2&amp;arm=1" class="rl""
                $rowspan = ($hasSplitCbOrInstances && $this->report_attr['report_display_header']!=='VARIABLE') ? 2 : 1;
                $report_table = "<table id='report_table' class='dataTable cell-border' style='table-layout:fixed;margin:0;font-family:Verdana;font-size:11px;'>";
                $table_header = "<thead>";
                for ($headerRow=1; $headerRow<=$rowspan; $headerRow++) {
                    $table_header .= "<tr>";
                    foreach($headers as $th) {
                        $table_header .= $this->makeTH($th, $headerRow);
                    }
                    $table_header .= "</tr>";
                }
                $table_header .= "</thead>";
                
                $table_body = "<tbody>";
                for ($i=0; $i<count($rows); $i++) {
                    $rowclass = ($i%2) ? 'even' : 'odd';
                    $table_body .= "<tr class=\"$rowclass\">";
                    foreach ($rows[$i] as $fieldIdx => $td) {
                        if ($headers[$fieldIdx]['instance_count']>0 && $this->reshape_instance==='cols') {
                            // for split instances $td will be an array of instance values (array of arrays for checkboxes)
                            foreach ($td as $thisInstanceTd) {
                                $thisFieldValue = $this->makeOutputValue($thisInstanceTd, $headers[$fieldIdx]['field_name'], 'html', $decimalCharacter);
                                foreach ($thisFieldValue as $thisValue) {
                                    $table_body .= $this->makeTD($thisValue);;
                                }
                            }
                        } else {
                            $thisFieldValue = $this->makeOutputValue($td, $headers[$fieldIdx]['field_name'], 'html', $decimalCharacter);
                            foreach ($thisFieldValue as $thisValue) {
                                $table_body .= $this->makeTD($thisValue);
                            }
                        }
                    }
                    $table_body .= "</tr>";
                }
                $table_body .= "</tr></body>";
            }
            $return_content = $report_table.$table_header.$table_body.'</table>';

        } else if ($format=='csv' || $format=='csvraw' || $format=='csvlabels') { 
            $return_content = ""; // If no results will return empty CSV with no headers (follows pattern of regular data exports)
            if ($num_results_returned > 0) {
                foreach($headers as $th) {
                    foreach ($this->makeExportColTitles($th, $format) as $thisColTitle) {
                        $return_content .= $this->makeCsvValue($thisColTitle, $decimalCharacter, $csvDelimiter).$csvDelimiter;
                    }
                }
                $return_content = rtrim($return_content, $csvDelimiter).static::DEFAULT_CSV_LINE_END;
                
                foreach ($rows as $row) {
                    foreach ($row as $fldIdx => $td) {
                        $thisField = $headers[$fldIdx];
                        if ($headers[$fldIdx]['instance_count']>0 && $this->reshape_instance==='cols') {
                            // for split instances $td will be an array of instance values (array of arrays for checkboxes)
                            foreach ($td as $thisInstanceTd) {
                                $thisFieldValue = $this->makeOutputValue($thisInstanceTd, $headers[$fldIdx]['field_name'], $format, $decimalCharacter);
                                foreach ($thisFieldValue as $thisValue) {
                                    $return_content .= $this->makeCsvValue($thisValue, $decimalCharacter, $csvDelimiter).$csvDelimiter;
                                }
                            }
                        } else {
                            $thisFieldValue = $this->makeOutputValue($td, $thisField['field_name'], $format, $decimalCharacter, $csvDelimiter);
                            foreach ($thisFieldValue as $thisValue) {
                                $return_content .= $this->makeCsvValue($thisValue, $decimalCharacter, $csvDelimiter).$csvDelimiter;
                            }
                        }
                    }
                    $return_content = rtrim($return_content, $csvDelimiter).static::DEFAULT_CSV_LINE_END;
                }
            }

        } else if ($format=='json') { 
            $content = array(); // If no results will return empty array (follows pattern of regular data exports)
            if ($num_results_returned > 0) {
                foreach ($rows as $row) {
                    $rowObj = new \stdClass();
                    foreach ($row as $field => $value) {
                        $fieldName = $headers[$field]['field_name'];
                        $rowObj->$fieldName = $value;
                    }
                    $content[] = $rowObj;
                }
            }

            $output[] = \json_encode($content);
            $output[] = $num_results_returned;

        } else if ($format=='xml') { 
            $indent = '    ';
            $return_content = '<?xml version="1.0" encoding="UTF-8" ?>'.PHP_EOL.'<records>';
            
            if ($num_results_returned > 0) { // If no results will return empty records node (follows pattern of regular data exports)
                foreach ($rows as $row) {
                    $return_content .= PHP_EOL.$indent.'<item>';
                    foreach ($row as $field => $value) {
                        $fieldName = $headers[$field]['field_name'];
                        $return_content .= PHP_EOL.$indent.$indent."<$fieldName>";
                        $return_content .= PHP_EOL.$indent.$indent.$indent."<![CDATA[$value]]>";
                        $return_content .= PHP_EOL.$indent.$indent."</$fieldName>";
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

    protected function doSqlReport() {
        global $lang,$Proj,$user_rights,$project_id;
        
        $sql = rtrim(trim($this->sql_query), ";");
        $sql = \Piping::pipeSpecialTags($sql, $project_id); // user and misc tags will work
        if (!preg_match("/^select\s/i", $sql)) {
            return array('Not a select query', 0);
		}

        $filteredRows = array();
        $headers = array();
        $output = array();

        try {
            $result = $this->module->query($sql,[]);
        } catch (\Exception $ex) {
            $output[] = $ex->getMessage();
            $output[] = 0;
            return $output;
        }
        
        if ($result) {
            $finfo = $result->fetch_fields();
            $includesPk = false;
            foreach ($finfo as $f) {
                list($fName, $fLabel) = explode('$',$f->name,2);
                if ($fName==$Proj->table_pk) $includesPk = true;
                if (array_key_exists($fName, $Proj->metadata) && is_null($fLabel)) {
                    $fLabel = $this->truncateLabel($Proj->metadata[$fName]['element_label']);
                }
                $headers[] = array(
                    'field_name'=>$fName,
                    'element_label'=>$fLabel,
                    'instance_count'=>0
                );
            }

            if ($includesPk && $user_rights['group_id'] && !$this->sql_disable_dag_filter) {
                // if sql query includes pk field then filter by user's DAG
                $recordFilter = array();
                $result1 = $this->module->query("select distinct record from redcap_data where project_id=? and field_name='__GROUPID__' and `value`=?",[$Proj->project_id,$user_rights['group_id']]);
                while($row = $result1->fetch_assoc()){
                    $recordFilter[] = $row['record'];
                }
                $result1->free();
            } else {
                $recordFilter = false;
            }

            while($row = $result->fetch_assoc()){
                if (!$recordFilter || (is_array($recordFilter) && in_array($row[$Proj->table_pk], $recordFilter))) {
                    $filteredRows[] = array_values($row);
                }
            }
            $result->free();
        }
        return array($filteredRows, $headers, false);
    }

    protected function doReshapedReport($format) {
        global $lang, $Proj;

        $report_data = \REDCap::getReport($this->report_id, 'array', false, false); // note export as labels option does not work for 'array'

        // work our what our reshaped colmns are and thereby the way to reference the report data array for each reshaped row
        $headers = array();
        $eventUniqueNames = \REDCap::getEventNames(true);

        $efResult = $this->module->query("select count(*) as num_filter_events from redcap_reports_filter_events where report_id=?",[$this->report_id]);
        $num_filter_events = $efResult->fetch_assoc()['num_filter_events'];

        $sql = 'select r.report_id, r.project_id, ea.arm_id, ea.arm_name, em.event_id, em.descrip, ';
        $sql .= ($num_filter_events > 0) ? 'if(rfe.event_id is null,0,1) ' : '1 ';
        $sql .= 'as in_filter, ef.form_name, rf.field_name, rf.field_order, m.element_type
            from redcap_reports r
            inner join redcap_events_arms ea on r.project_id=ea.project_id
            inner join redcap_events_metadata em on ea.arm_id=em.arm_id
            inner join redcap_events_forms ef on em.event_id=ef.event_id
            inner join redcap_metadata m on r.project_id=m.project_id and ef.form_name=m.form_name
            inner join redcap_reports_fields rf on r.report_id=rf.report_id and m.field_name=rf.field_name 
            ';
        if ($num_filter_events > 0) {
            $sql .= 'inner join redcap_reports_filter_events rfe on r.report_id=rfe.report_id and em.event_id=rfe.event_id ';
        }
        
        if ($this->reshape_event=='ef') {
            // order columns by arm then event then field
            $sql .= 'where r.report_id=? order by ea.arm_num, em.day_offset, em.event_id, rf.field_order';
        } else if ($this->reshape_event=='fe') {
            // order columns by field then arm then event
            $sql .= 'where r.report_id=? order by rf.field_order, ea.arm_num, em.day_offset, em.event_id';
        } else {
            // no events - repeating instances only
            $sql .= 'where r.report_id=? order by rf.field_order';
        }
        
        $columnsResult = $this->module->query($sql, [$this->report_id]);
        $hasSplitCbOrInstances = false;

        while($thisHdr = $columnsResult->fetch_assoc()){
            $thisHdr['unique_name'] = $eventUniqueNames[$thisHdr['event_id']];

            $thisHdr['element_label'] = $Proj->metadata[$thisHdr['field_name']]['element_label'];
            if ($thisHdr['field_name'] == $thisHdr['form_name'].'_complete') {
                $thisHdr['element_label'] = $Proj->forms[$thisHdr['form_name']]['menu'].' '.$thisHdr['element_label']; // "Form Name Complete?"
            }

            // handle non-combined checkbox and instance columns here
            if ($thisHdr['element_type']=='checkbox' && !$this->report_attr['combine_checkbox_values']) {
                $hasSplitCbOrInstances = true;

                // *******************************************
                // TODO handle missing value checkbox columns?
                // *******************************************
                $thisHdr['subvalues'] = array_keys(\parseEnum($Proj->metadata[$thisHdr['field_name']]['element_enum']));
            } else {
                $thisHdr['subvalues'] = array();
            }

            // handle instance columns here
            $thisHdr['is_repeating_event'] = $Proj->isRepeatingEvent($thisHdr['event_id']);
            $thisHdr['is_repeating_form'] = $Proj->isRepeatingForm($thisHdr['event_id'], $Proj->metadata[$thisHdr['field_name']]['form_name']);
            $thisHdr['instance_count'] = 0;
            if ($thisHdr['is_repeating_event'] || $thisHdr['is_repeating_form']) {
                $hasSplitCbOrInstances = $hasSplitCbOrInstances || $this->reshape_instance==='cols';
                $instrumentKey = ($thisHdr['is_repeating_form']) ? $Proj->metadata[$thisHdr['field_name']]['form_name'] : '';
                $fieldMaxInstance = 0;
                foreach ($report_data as $thisRec) {
                    if (
                        array_key_exists('repeat_instances', $thisRec) &&
                        array_key_exists($thisHdr['event_id'], $thisRec['repeat_instances']) &&
                        array_key_exists($instrumentKey, $thisRec['repeat_instances'][$thisHdr['event_id']]) 
                    ) {
                        $thisRecMaxInstance = intval(array_key_last($thisRec['repeat_instances'][$thisHdr['event_id']][$instrumentKey]));
                    } else {
                        $thisRecMaxInstance = 0;
                    }
                    $fieldMaxInstance = ($thisRecMaxInstance > $fieldMaxInstance) ? $thisRecMaxInstance : $fieldMaxInstance;
                }
                $thisHdr['instance_count'] = $fieldMaxInstance;
            }

            $headers[] = $thisHdr;
        }

        // include dag header if selected for report
        if (count($report_data)>0 && $this->report_attr['output_dags']) { // find the position of the DAG col
            $eId = key($report_data[key($report_data)]);
            $row1Data = (is_numeric($eId)) ? $report_data[key($report_data)][$eId] : $eId;
            $dagPos = array_search('redcap_data_access_group', array_keys($row1Data));
            if ($dagPos !== false) {
                $dagHeader = array(
                    'report_id' => $this->report_id,
                    'event_id' => $Proj->firstEventId,
                    'field_name' => 'redcap_data_access_group',
                    'subvalues' => array()
                );
                array_splice($headers, $dagPos, 0, array($dagHeader));
                $this->dag_names = array();
                $dagIdToUN = \REDCap::getGroupNames(true);
                $dagIdToLbl = \REDCap::getGroupNames(false);
                foreach ($dagIdToUN as $id => $un) {
                    $this->dag_names[$un] = $dagIdToLbl[$id];
                }
            }
        }

        // make the reshaped rows
        $rows = array();
        foreach (array_keys($report_data) as $returnRecord) {
            if (is_null($returnRecord) || $returnRecord=='') continue;
            $thisRow = array();

            foreach ($headers as $thisHeader) {
                if ($thisHeader['is_repeating_event'] || $thisHeader['is_repeating_form']) {
                    // loop through instance data incrementing instance id until found all 
                    // there may be gaps in the instance number sequence if some have been deleted
                    switch ($this->reshape_instance) {
                        case 'conc_space': $sep = ' '; break;
                        case 'conc_comma': $sep = ','; break;
                        case 'conc_pipe': $sep = '|'; break;
                        default: $sep = ''; break;
                    }
                    $instrumentKey = ($thisHeader['is_repeating_form']) ? $Proj->metadata[$thisHeader['field_name']]['form_name'] : '';
                    $thisHdrInstances = $report_data[$returnRecord]['repeat_instances'][$thisHeader['event_id']][$instrumentKey] ?? array();

                    if ($this->reshape_instance=='first') {
                        $key = array_key_first($thisHdrInstances);
                        $thisRow[] = $thisHdrInstances[$key][$thisHeader['field_name']] ?? '';
                    } else if ($this->reshape_instance=='last') {
                        $key = array_key_last($thisHdrInstances);
                        $thisRow[] = $thisHdrInstances[$key][$thisHeader['field_name']] ?? '';
                    } else {
                        $thisRecValue = ($this->reshape_instance==='cols') ? array() : '';
                        for ($i = 1; $i <= $thisHeader['instance_count']; $i++) {
                            if (array_key_exists($i, $thisHdrInstances)) {
                                $iData = $thisHdrInstances[$i];
                                $thisInstanceValue = $iData[$thisHeader['field_name']] ?? '';
                                switch ($this->reshape_instance) {
                                    case 'cols':
                                        $thisRecValue[] = $thisInstanceValue; // separate col for each instance
                                        break;
                                    case 'min':
                                        if (is_numeric($thisInstanceValue)) {
                                            $thisInstanceValue = (float)$thisInstanceValue;
                                            $thisRecValue = (float)$thisRecValue;
                                        }
                                        $thisRecValue = ($thisRecValue=='' || $thisInstanceValue < $thisRecValue) ? $thisInstanceValue : $thisRecValue;
                                        break;
                                    case 'max':
                                        if (is_numeric($thisInstanceValue)) {
                                            $thisInstanceValue = (float)$thisInstanceValue;
                                            $thisRecValue = (float)$thisRecValue;
                                        }
                                        $thisRecValue = ($thisRecValue=='' || $thisInstanceValue > $thisRecValue) ? $thisInstanceValue : $thisRecValue;
                                        break;
                                    case 'conc_space':
                                    case 'conc_comma':
                                    case 'conc_pipe':
                                        $thisInstanceOutput = $this->makeOutputValue($thisInstanceValue, $thisHeader['field_name'], $format);
                                        $thisRecValue .= ((empty($thisInstanceOutput))?'':$thisInstanceOutput[0]).$sep;
                                        break;
                                    default:
                                        $thisRecValue = '';
                                        break;
                                }
                            } else {
                                if ($thisHeader['element_type']=='checkbox' && !$this->report_attr['combine_checkbox_values']) {
                                    $emptyCbTd = array();
                                    foreach(array_values($thisHeader['subvalues']) as $sv) {
                                        $emptyCbTd[$sv] = '';
                                    }
                                    $thisInstanceValue = $emptyCbTd;
                                } else {
                                    $thisInstanceValue = '';
                                }
                                if ($this->reshape_instance==='cols') {
                                    $thisRecValue[] = $thisInstanceValue;
                                } else {
                                    $thisRecValue .= $thisInstanceValue;
                                }
                            }
                        }
                        $thisRow[] = ($this->reshape_instance==='cols') ? $thisRecValue : rtrim($thisRecValue,$sep);
                    }
                } else {
                    if ($thisHeader['field_name'] == $Proj->table_pk) {
                        $thisRow[] = $returnRecord;
                    } else if (
                        array_key_exists($thisHeader['event_id'], $report_data[$returnRecord]) && 
                        array_key_exists($thisHeader['field_name'], $report_data[$returnRecord][$thisHeader['event_id']])
                       ) {
                        $thisRow[] = $report_data[$returnRecord][$thisHeader['event_id']][$thisHeader['field_name']]; // an array for split checkboxes
                    } else {
                        if ($thisHeader['element_type']=='checkbox' && !$this->report_attr['combine_checkbox_values']) {
                            $emptyCbTd = array();
                            foreach(array_values($thisHeader['subvalues']) as $sv) {
                                $emptyCbTd[$sv] = '';
                            }
                            $thisRow[] = $emptyCbTd;
                        } else {
                            $thisRow[] = '';
                        }
                    }
                }
            }

            $rows[] = $thisRow;
        }
        $crl = \Records::getCustomRecordLabelsSecondaryFieldAllRecords(array_keys($report_data));
        $this->record_labels = (is_array($crl)) ? $crl : array();
        return array($rows, $headers, $hasSplitCbOrInstances);
    }

    protected function makeTH($th, $thRow) {
        if ($thRow>1 && count($th['subvalues'])===0 && $th['instance_count']===0) return '';
        global $Proj;
        $headerCell = '';

        if ($thRow===1) {
            $headerCell = $this->makeReportColTitle($th);
        } else {
            if ($th['instance_count'] > 0) {
                for ($i=1; $i <= $th['instance_count']; $i++) {
                    if (count($th['subvalues'])===0) {
                        // instance of field other than checkbox
                        $headerCell .= "<th><div class=\"mr-3\">#$i</div></th>";
                    } else {
                        // instance of checkbox
                        $headerCell .= $this->makeCheckboxHeaders($th, $i);
                    }
                }
            } else {
                // non-repeating checkbox row 2 - choice label / varname + value
                $headerCell .= $this->makeCheckboxHeaders($th);
            }
        }
        return $headerCell;
    }

    protected function makeReportColTitle($th, $raw=false) {
        global $Proj;
        $fldName = \REDCap::filterHtml((is_array($th))?$th['field_name']:(string)$th);

        if ($raw) {
            $title = $fldName;
        } else if ($this->is_sql) {
            if (is_null($th['element_label'])) {
                $title = $fldName;
            } else {
                $title = $this->truncateLabel($th['element_label'])."<div class=\"rpthdr\">".$th['field_name']."</div>";
            }
        } else {
            if ($this->report_attr['report_display_header']==='VARIABLE') { 
                $title = $fldName; 
            } else if ($fldName==='redcap_data_access_group') {
                global $lang;
                $title = $lang['global_78'];
            } else {
                $evtLabel = '';
                if ($Proj->longitudinal) $evtLabel .= \REDCap::filterHtml($th['descrip']);
                if ($Proj->multiple_arms) $evtLabel .= ' '.\REDCap::filterHtml($th['arm_name']);
                $evtLabel = "<span style=\"color:#800000;\">$evtLabel</span>";
                $fldLabel = $this->truncateLabel(\REDCap::filterHtml($th['element_label']));

                if ($this->reshape_event=='ef') {
                    $title = "$evtLabel<br>$fldLabel";
                } else if ($this->reshape_event=='fe') {
                    $title = "$fldLabel<br>$evtLabel";
                } else {
                    $title = $fldLabel;  // instances only, no events
                }
            }
            if ($this->report_attr['report_display_header']!=='LABEL') $title .= "<div class=\"rpthdr\">$fldName</div>";
            if ($this->reshape_instance=='cols' && ($th['is_repeating_event'] || $th['is_repeating_form'])) {
                if (count($th['subvalues']) == 0) {
                    $colspan = " colspan=".$th['instance_count'];
                } else {
                    $colspan = " colspan=".($th['instance_count']*count($th['subvalues']));
                }
            } else {
                $colspan = (count($th['subvalues']) > 0) ? " colspan=".count($th['subvalues']) : '';
            }
            $rowspan = (count($th['subvalues']) > 0 || $th['instance_count'] > 0) ? "" : " rowspan=2";
        }
        return "<th $colspan $rowspan><div class=\"mr-3\">$title</div></th>";;
    }

    protected function makeExportColTitles($th, $format) {
        global $Proj;
        $colTitles = array();
        $varNames = array();
        if ($this->is_sql) {
            $evt = '';
            $sep = '';
            $th['element_label'] = $th['element_label'] ?? $th['field_name'];
        } else if ($format=='csvlabels') {
            $evt = ($Proj->multiple_arms) ? $th['descrip'].' '.$th['arm_name'] : $th['descrip'];
            $sep = ' ';
        } else {
            $evt = $th['unique_name'];
            $sep = '.';
        }
        if ($th['element_type']=='checkbox' && !$this->report_attr['combine_checkbox_values']) {
            $choices = \parseEnum($Proj->metadata[$th['field_name']]['element_enum']);
        
            foreach ($th['subvalues'] as $thsv) {
                if ($format=='csvlabels') {
                    $varNames[] = $this->truncateLabel($th['element_label'])." (choice=".$choices[$thsv].")";
                } else {
                    $varNames[] = $th['field_name'].'___'.$thsv;
                }
            }
        } else {
            if ($format=='csvlabels') {
                $varNames[] = $this->truncateLabel($th['element_label']);
            } else {
                $varNames[] = $th['field_name'];
            }
        }

        if ($th['instance_count'] > 0 && $this->reshape_instance==='cols') {
            $colCount = $th['instance_count'];
            if ($th['is_repeating_event']) {
                $pattern =  "|e|$sep|i|$sep|v|";
            } else { // is_repeating_form
                $pattern =  ($Proj->longitudinal) ? "|e|$sep|v|$sep|i|" : "|v|$sep|i|";
            }
        } else {
            $colCount = 1;
            if (!$Proj->longitudinal || $th['field_name'] == $Proj->table_pk) {
                $pattern =  "|v|";
            } else {
                $pattern =  "|e|$sep|v|";
            }
        }
        for ($i=1; $i <= $colCount; $i++) {
            foreach ($varNames as $vn) {
                $instanceDisplay = ($format=='csvlabels') ? "#$i" : "$i";
                $thisTitle = $pattern;
                $thisTitle = str_replace('|e|',$evt,$thisTitle);
                $thisTitle = str_replace('|v|',$vn,$thisTitle);
                $thisTitle = str_replace('|i|',$instanceDisplay,$thisTitle);
                $colTitles[] = $thisTitle;
            }
        }
        return $colTitles;
    }
        
    protected function makeCheckboxHeaders($th, $instance=0) {
        global $Proj;
        $inst = ($instance>0) ? "#$instance" : "";
        $headers = '';
        $choices = \parseEnum($Proj->metadata[$th['field_name']]['element_enum']);
        foreach ($th['subvalues'] as $thsv) {
            $title = $this->truncateLabel($choices[$thsv]);
            if ($this->report_attr['report_display_header']!=='LABEL') $title .= "<div class=\"rpthdr\">".$th['field_name']."___$thsv</div>";
            $headers .= $this->makeReportColTitle("$inst $title", true);
        }
        return $headers;
    }

    /**
     * makeOutputValue($value, $fieldName, $outputFormat, $decimalCharacter=null, $delimiter=null)
     * @param mixed $value A string or array of values
     * @param string $fielddName Name of the field
     * @param string $outputFormat view or export format, typically html, csv, csvraw, csvlabels
     * @param string $decimalCharacter Override default decimal character (.)
     * @param string $delimiter Override default delimiter character (,)
     * @return Array $return Array of values: single value except for non-combined checkboxes
     */
    protected function makeOutputValue($value, $fieldName, $outputFormat, $decimalCharacter=null, $delimiter=null) {
        global $Proj;
        $decimalCharacter = $decimalCharacter ?? static::DEFAULT_DECIMAL_CHAR;
        $delimiter = $delimiter ?? static::DEFAULT_CSV_DELIMITER;
        $field = $Proj->metadata[$fieldName];

        if ($fieldName==$Proj->table_pk) {
            $outValue = $this->makePkDisplay($value, $outputFormat);
        } else if ($fieldName=='redcap_event_name') { // no reshaping of events
            $outValue = $this->makeEventDisplay($value);
        } else if ($fieldName=='redcap_repeat_instrument') { // no reshaping of instances
            $outValue = $this->makeRptFormDisplay($value);
        } else if ($fieldName=='redcap_repeat_instance') { // no reshaping of instances
            $outValue = $this->makeInstanceDisplay($value);
        } else if ($fieldName=='redcap_data_access_group') { 
            $outValue = $this->makeDAGDisplay($value, $outputFormat, $decimalCharacter, $delimiter);
        } else if (in_array($field['element_type'], array("advcheckbox", "radio", "select", "checkbox", "dropdown", "sql", "yesno", "truefalse"))) {
            $outValue = $this->makeChoiceDisplay($value, $fieldName, $outputFormat, $decimalCharacter, $delimiter);
        } else if ($field['element_type']==='notes') {
            $outValue = $this->makeTextDisplay($value, $fieldName, '', $outputFormat, $decimalCharacter);
        } else if ($field['element_type']==='file') {
            $outValue = $this->makeFileDisplay($value, $fieldName, $outputFormat);
        } else if ($field['element_type']==='text') {
            $ontologyOption = $field['element_enum'];
            if ($ontologyOption!=='' && preg_match('/^\w+:\w+$/', $ontologyOption)) {
                // ontology fields are text fields with an element enum like "BIOPORTAL:ICD10"
                list($ontologyService, $ontologyCategory) = explode(':',$ontologyOption,2);
                $outValue = $this->makeOntologyDisplay($value, $ontologyService, $ontologyCategory);
            } else {
                // regular text fields have null element_enum
                $outValue = $this->makeTextDisplay($value, $fieldName, $field['element_validation_type'], $outputFormat, $decimalCharacter);
            }
        } else {
            $outValue = $value;
        }
        $outValue = (is_array($outValue)) ? $outValue : [$outValue];
        foreach ($outValue as $ovi => $ov) {
            switch ($outputFormat){
                case 'html': 
                    $outValue[$ovi] = $ov; // \REDCap::filterHtml($ov);
                    break;
                case 'csv':
                case 'csvraw':
                case 'csvlabels':
                    $outValue[$ovi] = $ov; // $this->makeCsvValue($ov, $decimalCharacter, $delimiter);
                    break;
                default:
                    break;
            }
        }
        return $outValue;
    }

    protected function makeTD($value) {
        return "<td>".\REDCap::filterHtml($value)."</td>";
    }

    /**
     * makeCsvValue(mixed $value, $decimalCharacter="", $delimiter="")
     * @param mixed $value A string or array of values
     * @param string $decimalCharacter Override default decimal character (.)
     * @param string $delimiter Override default delimiter character (,)
     * @return mixed $return string or array matching $value escaped for csv safety
     */
    protected function makeCsvValue($value, $decimalCharacter="", $delimiter="") {
        $delimiter = ($delimiter==="") ? static::DEFAULT_CSV_DELIMITER : $delimiter;
        $decimalCharacter = ($decimalCharacter==="") ? static::DEFAULT_DECIMAL_CHAR : $decimalCharacter;

        if (is_array($value)) {
            $returnValue = array();
            foreach ($value as $arrayval) {
                $returnValue[] = $this->makeCsvValue($arrayval, $decimalCharacter, $delimiter);
            }
        } else {
            $returnValue = '';
            $value = trim($value);
            if (in_array(substr($value, 0, 1), self::$csvInjectionChars)) {
                // Prevent CSV injection for Excel - add space in front if first character is -, @, +, or = (http://georgemauer.net/2017/10/07/csv-injection.html)
                $value = " $value";
            }
            if (strpos($value, static::DEFAULT_ESCAPE_CHAR)!==false) {
                // If value contains " then escape as "" e.g. A quote: "Wow!". -> "A quote: ""Wow!""."
                $returnValue = static::DEFAULT_ESCAPE_CHAR . str_replace(static::DEFAULT_ESCAPE_CHAR, static::DEFAULT_ESCAPE_CHAR.static::DEFAULT_ESCAPE_CHAR, $value) . static::DEFAULT_ESCAPE_CHAR;
            } else if (strpos($value, $delimiter)!==false) {
                // If value contains a comma (and no ") then wrap with "
                $returnValue = static::DEFAULT_ESCAPE_CHAR.$value.static::DEFAULT_ESCAPE_CHAR;
            } else if (strpos($value, PHP_EOL)!==false) {
                // If value contains line breaks (and no " or ,) then wrap with "
                $returnValue = static::DEFAULT_ESCAPE_CHAR.$value.static::DEFAULT_ESCAPE_CHAR;
            } else if(is_numeric($value) && is_float(0+$value)) {
                $returnValue = str_replace('.',$decimalCharacter,"$value",1);
            } else {
                $returnValue = $value;
            }
        }
        return $returnValue;
    }

    protected function makePkDisplay($record, $outputFormat) {
        global $Proj;
        $recordDisplay = $record;
        if ($outputFormat=='html') {
            $crl = (array_key_exists($record, $this->record_labels)) ? $this->record_labels[$record] : ''; // $crl = \Records::getCustomRecordLabelsSecondaryFieldAllRecords($record, false, 'all');
            $recordDisplay = "<a class='mr-1' target='_blank' href='".APP_PATH_WEBROOT."DataEntry/record_home.php?pid=$Proj->project_id&id=$record'>$record</a>";
            $recordDisplay = trim("$recordDisplay $crl");
        } 
        return $recordDisplay;
    }

    protected function makeEventDisplay($value) { return $value; }
    protected function makeRptFormDisplay($value) { return $value; }
    protected function makeInstanceDisplay($value) { return $value; }

    protected function makeDAGDisplay($value, $outputFormat) { 
        if (trim($value)=='') return '';
        $daglbl = $this->dag_names[$value];
        if ($outputFormat == 'html') {
            switch ($this->report_attr['report_display_data']) {
                case 'RAW': $rtn = $value; break;
                case 'LABEL': //$rtn = $daglbl; break;
                default: $rtn = $daglbl;//.' <span class="text-muted">('.$value.')</span>';
            }
        } else if ($outputFormat=='csvlabels') {
            $rtn = $daglbl;
        } else {
            $rtn = $value;
        }
        return $rtn;
    }

    protected function makeChoiceDisplay($val, $fieldName, $format, $decimalCharacter='', $delimiter='') {
        global $Proj, $missingDataCodes;
        if (!is_array($val) && trim($val)=='') { return ''; }
        if ($Proj->metadata[$fieldName]['element_type']==='sql') {
            $choices = \parseEnum(\getSqlFieldEnum($Proj->metadata[$fieldName]['element_enum']));
        } else {
            $choices = \parseEnum($Proj->metadata[$fieldName]['element_enum']);
        }

        if (
                // combined checkbox: concat all *selected* values/labels into single value
                $Proj->metadata[$fieldName]['element_type']==='checkbox' &&
                $this->report_attr['combine_checkbox_values']
            ) {
            if (!is_null($val) && !is_array($val)) {
                $selected=explode(',', $val);
                $val = array();
                foreach ($selected as $s) {
                    $val[$s] = '1';
                }
            }
            foreach ($val as $valkey => $cbval) {
                if ($cbval==='1') {
                    switch ($format){
                        case 'html': 
                            $val[$valkey] = $this->makeChoiceDisplayHtml($valkey, $choices);
                            break;
                        case 'csv':
                        case 'csvraw':
                            $val[$valkey] = $valkey;
                            break;
                        case 'csvlabels':
                            $val[$valkey] = $choices[$valkey];
                            break;
                        default:
                            break;
                    }
                } else {
                    unset($val[$valkey]); // don't keep non-selected options
                }
            }
            switch ($format){
                case 'html': 
                    $outValue = implode('<br>', $val); // return single value: multiple checkbox selections one per line when combined
                    break;
                case 'csv':
                case 'csvraw':
                case 'csvlabels':
                    $outValue = implode($delimiter, $val); // return single value: multiple checkbox selections comma-separated
                    break;
                default:
                    break;
            }
            

        } else if (
                // non-combined checkbox: return array 
                $Proj->metadata[$fieldName]['element_type']==='checkbox' &&
                !$this->report_attr['combine_checkbox_values']
            ) {
            $outValue = array();
            switch ($format){
                case 'html': 
                    $val0 = 'Unchecked (0)'; // TODO translation?
                    $val1 = 'Checked (1)';
                    break;
                case 'csvlabels':
                    $val0 = 'Unchecked'; // TODO translation?
                    $val1 = 'Checked';
                    break;
                case 'csv':
                case 'csvraw':
                default:
                    $val0 = '0';
                    $val1 = '1';
                    break;
            }

            foreach ($val as $valkey => $cbval) {
                switch ($cbval) {
                    case '1': $outValue[$valkey] = $val1; break;
                    case '0': $outValue[$valkey] = $val0; break;
                    default: $outValue[$valkey] = ''; break;
                }
            }
            
        } else {
            // single-select field
            switch ($format) {
                case 'html':
                    $outValue = $this->makeChoiceDisplayHtml($val, $choices);
                    break;
                case 'csvlabels':
                    $outValue = (array_key_exists($val, $choices)) ? $choices[$val] : $val; // key won't exist for concat instances cols
                    break;
                default:
                    $outValue = $val;
                    break;
            }
        }
        return $outValue; // array for checkboxes
    }

    protected function makeChoiceDisplayHtml($val, $choices) {
        if (array_key_exists($val, $choices)) {
            $rtn = '';
            switch ($this->report_attr['report_display_data']) {
                case 'LABEL': $rtn .= $this->module->escape($choices[$val]); break;
                case 'RAW': $rtn .= $val; break;
                default: $rtn .= $this->module->escape($choices[$val]).' <span class="text-muted">('.$val.')</span>'; // BOTH
            }
            return $rtn;
        }
        return $val;
    }

    protected function makeTextDisplay($val, $fieldName, $valType, $outputFormat, $decimalCharacter) {
        if (trim($val)=='') { return ''; }
        switch ($valType) {
            case 'date_mdy':
            case 'date_dmy':
            case 'datetime_mdy':
            case 'datetime_dmy':
            case 'datetime_seconds_mdy':
            case 'datetime_seconds_dmy':
                $outVal = \DateTimeRC::datetimeConvert($val, 'ymd', substr($valType, -3)); // reformat raw ymd date/datetime value to mdy or dmy, if appropriate
                break;
            case 'email':
                $outVal = ($outputFormat=='html') ? "<a href='mailto:$val'>$val</a>" : $val;
                break;
            default:
                if (strpos($valType, 'number')===0) {
                    $outVal = str_replace('.',$decimalCharacter,$val);
                }
                $outVal = $val;
                break;
        }
        return \REDCap::filterHtml($outVal);
    }

    protected function makeFileDisplay($val, $fieldName, $format) {
        switch ($format) {
            case 'html':
                //        $downloadDocUrl = APP_PATH_WEBROOT.'DataEntry/file_download.php?pid='.PROJECT_ID."&s=&record=$record&event_id=$event_id&instance=$instance&field_name=$fieldName&id=$val&doc_id_hash=".Files::docIdHash($val);
                $fileDlBtn = $val;// TODO get record/event/instance from file id "<button class='btn btn-defaultrc btn-xs' style='font-size:8pt;' onclick=\"window.open('$downloadDocUrl','_blank');return false;\">{$this->lang['design_121']}</button>";
                $val = str_replace('removed=','onclick=',\REDCap::filterHtml($fileDlBtn));
                break;
            default: break;
        }
        return $val;
    }

    protected function makeOntologyDisplay($val, $service, $category) {
        $sql = "select label from redcap_web_service_cache where project_id=? and service=? and category=? and `value`=?";
        $q = $this->module->query($sql, [PROJECT_ID, $service, $category, $val]);
        $r = db_fetch_assoc($q);
        $cachedLabel = $r["label"];
        $ontDisplay = (is_null($cachedLabel) || $cachedLabel==='')
                ? $val
                : $cachedLabel.' <span class="text-muted">('.$val.')</span>';
        return \REDCap::filterHtml($ontDisplay);
    }

    protected function truncateLabel($label, $maxlen=50) {
        $maxlen = ($maxlen<21) ? 21 : $maxlen;
        return (\strlen($label) > $maxlen) ? \substr($label, 0, $maxlen-20).'...'.\substr($label, -17, 17) : $label;
    }
}