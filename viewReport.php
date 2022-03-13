<?php
/**
 * viewReport($extendedAttributes)
 * Basically a copy of redcap_v10.7.1/DataExport/report_ajax.php but 
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
global $lang, $user_rights, $enable_plotting;

$script_time_start = microtime(true);

// Build dynamic filter options (if any)
$dynamic_filters = DataExport::displayReportDynamicFilterOptions($_POST['report_id']);
// Obtain any dynamic filters selected from query string params
list ($liveFilterLogic, $liveFilterGroupId, $liveFilterEventId) = DataExport::buildReportDynamicFilterLogic($_POST['report_id']);
/*// Total number of records queried
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
list($report_table, $num_results_returned) = $this->doExtendedReport($extendedAttributes, 'html');

if ($extendedAttributes['rpt-is-sql']) {
    $enable_plotting = false; // no Stats & Charts option for sql report
    ?>
    <style type="text/css">
        #exportFormatDialog div:nth-child(1) { display: none; }
        #exportFormatForm table td:nth-child(2) { display: none; } /* hide de-identification options */
        #export_choices_table tr:nth-child(n+2) { display: none; } /* hide export options except CSV Raw */
        #export_dialog_data_format_options > div:first-of-type { display: none } /* export 0 for grey status */
        #export_dialog_data_format_options > div:nth-of-type(3) { display: none } /* force decimal character */
    </style>
    <?php
}

// Report B only: If repeating instruments exist, and we're filtering using a repeating instrument, then the row counts can get skewed and be incorrect. This fixes it.
//if ($_POST['report_id'] == 'SELECTED' && (!isset($_GET['events']) || empty($_GET['events']))) {
//    $totalRecordsQueried = $num_results_returned;
//}
// Get report description
$report = DataExport::getReports($_POST['report_id']);
// Check report edit permission
$report_edit_access = SUPER_USER;
if (!SUPER_USER && is_numeric($_POST['report_id'])) {
    $reports_edit_access = DataExport::getReportsEditAccess(USERID, $user_rights['role_id'], $user_rights['group_id'], $_POST['report_id']);
    $report_edit_access = in_array($_POST['report_id'], $reports_edit_access);
}
$script_time_total = round(microtime(true) - $script_time_start, 1);

// Display report and title and other text
print  	"<div id='report_div' style='margin:10px 0 20px;'>" .
            RCView::div(array('style'=>''),
                RCView::div(array('class'=>'d-print-none', 'style'=>'float:left;width:350px;padding-bottom:5px;'),
                    RCView::div(array('style'=>'font-weight:bold;'),
                        $lang['custom_reports_02'] .
                        RCView::span(array('style'=>'margin-left:5px;color:#800000;font-size:15px;'),
                            User::number_format_user($num_results_returned)
                        )
                    ) .
                    /*RCView::div(array(),
                        $lang['custom_reports_03'] .
                        RCView::span(array('id'=>'records_queried_count', 'style'=>'margin-left:5px;'), 
                            User::number_format_user($totalRecordsQueried)
                        ) .
                        (!$longitudinal ? "" :
                            RCView::div(array('class'=>'fs11 mt-1', 'style'=>'color:#999;font-family:tahoma,arial;'),
                                $lang['custom_reports_09']
                            )
                        )
                    ) .*/
                    RCView::div(array('class'=>'fs11 mt-1', 'style'=>'color:#6f6f6f;'),
                        $lang['custom_reports_19']." $script_time_total ".$lang['control_center_4469']
                    )
                ) .
                RCView::div(array('class'=>'d-print-none', 'style'=>'float:left;'),
                    // Buttons: Stats, Export, Print, Edit
                    RCView::div(array(),
                        // Stats & Charts button
                        (!$user_rights['graphical'] || !$enable_plotting ? '' :
                            RCView::button(array('class'=>'report_btn jqbuttonmed', 'onclick'=>"window.location.href = '".APP_PATH_WEBROOT."DataExport/index.php?pid=".PROJECT_ID."&report_id={$_POST['report_id']}&stats_charts=1'+getInstrumentsListFromURL()+getLiveFilterUrl();", 'style'=>'color:#800000;font-size:12px;'),
                                '<i class="fas fa-chart-bar"></i> ' .$lang['report_builder_78']
                            )
                        ) .
                        RCView::SP .
                        // Export Data button
                        ($user_rights['data_export_tool'] == '0' ? '' :
                            RCView::button(array('class'=>'report_btn jqbuttonmed', 'onclick'=>"showExportFormatDialog('{$_POST['report_id']}');", 'style'=>'color:#000066;font-size:12px;'),
                                '<i class="fas fa-file-download"></i> ' .$lang['report_builder_48']
                            )
                        ) .
                        RCView::SP .
                        // Print link
                        RCView::button(array('class'=>'report_btn jqbuttonmed', 'onclick'=>"$('div.dataTables_scrollBody, div.dataTables_scrollHead').css('overflow','visible');$('.DTFC_Cloned').hide();setProjectFooterPosition();window.print();", 'style'=>'font-size:12px;'),
                            RCView::img(array('src'=>'printer.png', 'style'=>'vertical-align:middle;')) .
                            RCView::span(array('style'=>'vertical-align:middle;'),
                                $lang['custom_reports_13']
                            )
                        ) .
                        RCView::SP .
                        (($_POST['report_id'] == 'ALL' || $_POST['report_id'] == 'SELECTED' || !$user_rights['reports'] || (is_numeric($_POST['report_id']) && !$report_edit_access)) ? '' :
                            // Edit report link
                            RCView::button(array('class'=>'report_btn jqbuttonmed', 'onclick'=>"window.location.href = '".APP_PATH_WEBROOT."DataExport/index.php?pid=".PROJECT_ID."&report_id={$_POST['report_id']}&addedit=1';", 'style'=>'font-size:12px;'),
                                '<i class="fas fa-pencil-alt fs10"></i> ' .$lang['custom_reports_14']
                            )
                        )
                    ) .
                    // Dynamic filters (if any)
                    $dynamic_filters
                ) .
                RCView::div(array('class'=>'clear'), '')
            ) .
            // Report title
            RCView::div(array('id'=>'this_report_title', 'style'=>'margin:10px 0 '.($report['description'] == '' ? '8' : '0').'px;padding:5px 3px;color:#800000;font-size:18px;font-weight:bold;'),
                // Title
                $report['title']
            ) .
            // Report description (if has one)
            ($report['description'] == '' ? '' : 
                RCView::div(array('id'=>'this_report_description', 'style'=>'padding:5px 3px;line-height:15px;'),
                    filter_tags($report['description'])
                )
            ) .
            // Report table
            $report_table .
        "</div>";
