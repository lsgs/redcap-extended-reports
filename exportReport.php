<?php
/**
 * exportReport($extendedAttributes)
 * Basically a copy of redcap_v11.4.4/DataExport/data_export_ajax.php but 
 * 1. For CSV exports only: stats and ODM exports not handled by the module
 * 2. replacing $saveSuccess = 	DataExport::doReport( ... )
 *    with list($data_edoc_id, $num_records_returned) = $this->doExtendedReport($extendedAttributes, $_POST['export_format']);
 * 
 * Return HTML content for CSV file export dialog
 */
global $lang,$Proj,$project_id;

// Save defaults for CSV delimiter and decimal character
$csvDelimiter = (isset($_POST['csvDelimiter']) && DataExport::isValidCsvDelimiter($_POST['csvDelimiter'])) ? $_POST['csvDelimiter'] : ",";
UIState::saveUIStateValue('', 'export_dialog', 'csvDelimiter', $csvDelimiter);
if ($csvDelimiter == 'tab') $csvDelimiter = "\t";
$decimalCharacter = isset($_POST['decimalCharacter']) ? $_POST['decimalCharacter'] : '';
UIState::saveUIStateValue('', 'export_dialog', 'decimalCharacter', $decimalCharacter);

list($data_content, $num_records_returned) = $this->doExtendedReport($extendedAttributes, $_POST['export_format']);

// Store the data file
// File names for archived file
$today_hm = date("Y-m-d_Hi");
$projTitleShort = substr(str_replace(" ", "", ucwords(preg_replace("/[^a-zA-Z0-9 ]/", "", html_entity_decode($Proj->project['app_title'], ENT_QUOTES)))), 0, 20);
$filenamePart = ($format == 'csvlabels') ? "DATA_LABELS" : "DATA";
$csv_filename = $projTitleShort.'_'.$filenamePart.'_'.$today_hm.".csv";

$data_edoc_id = DataExport::storeExportFile($csv_filename, trim($data_content), true, false);
if ($data_edoc_id === false) return false;

if (!is_numeric($data_edoc_id)) return $lang['global_01']; // ERROR
$syntax_edoc_id = null; // not implemented for sql reports

$docs_header = $lang['data_export_tool_172'] . " "
                . ($outputFormat == 'csvraw' ? $lang['report_builder_49'] : $lang['report_builder_50']);
$docs_logo = "excelicon.gif";
$instr = "{$lang['data_export_tool_118']}<br><br><i>{$lang['global_02']}{$lang['colon']} {$lang['data_export_tool_17']}</i>";

// SEND-IT LINKS: If Send-It is not enabled for Data Export and File Repository, then hide the link to utilize Send-It
$senditLinks = "";
if ($sendit_enabled == '1' || $sendit_enabled == '3')
{
	$senditLinks = 	RCView::div(array('style'=>''),
						RCView::img(array('src'=>'mail_small.png', 'style'=>'vertical-align:middle;')) .
						RCView::a(array('href'=>'javascript:;', 'style'=>'vertical-align:middle;line-height:10px;color:#666;font-size:10px;text-decoration:underline;',
							'onclick'=>"displaySendItExportFile($data_edoc_id);"), $lang['docs_53']
						)
					) .
					RCView::div(array('id'=>"sendit_$data_edoc_id", 'style'=>'display:none;padding:4px 0 4px 6px;'),
						// Syntax file
						($syntax_edoc_id == null ? '' :
							RCView::div(array(),
								" &bull; " .
								RCView::a(array('href'=>'javascript:;', 'style'=>'font-size:10px;', 'onclick'=>"popupSendIt($syntax_edoc_id,2);"),
									$lang['docs_55']
								)
							)
						) .
						// Data file
						RCView::div(array(),
							" &bull; " .
							RCView::a(array('href'=>'javascript:;', 'style'=>'font-size:10px;', 'onclick'=>"popupSendIt($data_edoc_id,2);"),
								(($syntax_edoc_id != null || $outputFormat == 'odm') ? $lang['docs_54'] : ($outputFormat == 'csvraw' ? $lang['data_export_tool_119'] : $lang['data_export_tool_120']))
							)
						)
					);
}

## NOTICES FOR CITATIONS (GRANT AND/OR SHARED LIBRARY) AND DATE-SHIFT NOTICE
//Do not display grant statement unless $grant_cite has been set for this project.
$citationText = "";
if ($grant_cite != "") {
	$citationText .= "{$lang['data_export_tool_77']} $site_org_type {$lang['data_export_tool_78']} <b>($grant_cite)</b>
					   {$lang['data_export_tool_79']}
					   <div style='padding:8px 0 0;'>{$lang['data_export_tool_80']}";
} else {
	$citationText .= "<div>" . $lang['data_export_tool_81'];
}
$citationText .= " " . $lang['data_export_tool_82'] . " <a href='https://redcap.vanderbilt.edu/consortium/cite.php' target='_blank' style='text-decoration:underline;'>{$lang['data_export_tool_83']}</a>){$lang['period']}</div>";
// If instruments have been downloaded from the Shared Library, provide citatation
/*if ($Proj->formsFromLibrary()) {
	$citationText .= "<div style='padding:8px 0 0;'>
						{$lang['data_export_tool_144']}
						<a href='javascript:;' style='text-decoration:underline;' onclick=\"simpleDialog(null,null,'rsl_cite',550);\">{$lang['data_export_tool_145']}</a>
					  </div>";
}*/
if ($citationText != '') {
	$citationText = RCView::fieldset(array('style'=>'margin-top:10px;padding-left:8px;background-color:#FFFFD3;border:1px solid #FFC869;color:#800000;'),
						RCView::legend(array('style'=>'font-weight:bold;'),
							$lang['data_export_tool_147']
						) .
						RCView::div(array('style'=>'padding:5px 8px 8px 2px;'),
							$citationText
						)
					);
}
// If dates were date-shifted, give note of that.
$dateShiftText = "";
if ($dateShiftDates) {
	$dateShiftText = RCView::fieldset(array('class'=>'red', 'style'=>'margin-top:10px;padding:0 0 0 8px;max-width:1000px;'),
						RCView::legend(array('style'=>'font-weight:bold;'),
							$lang['global_03']
						) .
						RCView::div(array('style'=>'padding:5px 8px 8px 2px;'),
							"{$lang['data_export_tool_85']} $date_shift_max {$lang['data_export_tool_86']}"
						)
					);
}

// RESPONSE
$dialog_title = 	RCView::img(array('src'=>'tick.png', 'style'=>'vertical-align:middle')) .
					RCView::span(array('style'=>'color:green;vertical-align:middle;font-size:15px;'), $lang['data_export_tool_05']);
$dialog_content = 	RCView::div(array('style'=>'margin-bottom:20px;'),
						$lang['data_export_tool_183'] .
						$citationText .
						$dateShiftText
					) .
					RCView::div(array('style'=>'background-color:#F0F0F0;border:1px solid #888;padding:10px 5px;margin-bottom:10px;'),
						RCView::table(array('style'=>'border-collapse:collapse;width:100%;table-layout:fixed;'),
							RCView::tr(array(),
								RCView::td(array('rowspan'=>'3', 'valign'=>'top', 'style'=>'padding-left:10px;width:70px;'),
									RCView::img(array('src'=>$docs_logo, 'title'=>$docs_header))
								) .
								RCView::td(array('rowspan'=>'3', 'valign'=>'top', 'style'=>'line-height:14px;border-right:1px solid #ccc;font-family:Verdana;font-size:11px;padding-right:20px;'),
									RCView::div(array('style'=>'font-size:14px;font-weight:bold;margin-bottom:10px;'), $docs_header) .
									$instr
								) .
								RCView::td(array('valign'=>'top', 'class'=>'nowrap', 'style'=>'color:#666;font-size:11px;padding:0 5px 0 10px;width:145px;'),
									$lang['data_export_tool_184']
								)
							) .
							// Download icons
							RCView::tr(array(),
								RCView::td(array('valign'=>'top', 'class'=>'nowrap', 'style'=>'padding:10px 0 0 20px;'),
									// Syntax file download icon
									($syntax_edoc_id == null ? '' :
										RCView::a(array('href'=>APP_PATH_WEBROOT."FileRepository/file_download.php?pid=$project_id&id=$syntax_edoc_id"),
											trim(DataExport::getDownloadIcon($outputFormat))
										)
									) .
									RCView::SP . RCView::SP . RCView::SP .
									// Data CSV file download icon
									RCView::a(array('href'=>APP_PATH_WEBROOT."FileRepository/file_download.php?pid=$project_id&id=$data_edoc_id" .
										// For R and Stata, add "exporttype" flag to remove BOM from UTF-8 encoded files because the BOM can cause data import issues into R and Stata
										($outputFormat == 'r' ? '&exporttype=R' : ($outputFormat == 'stata' ? '&exporttype=STATA' : ''))),
										trim(DataExport::getDownloadIcon(($syntax_edoc_id == null ? $outputFormat : ''), $dateShiftDates, $includeOdmMetadata))
									) .
									// Pathway mapper file (for SAS and SPSS only)
									$pathway_mapper
								)
							) .
							// Send-It links
							RCView::tr(array(),
								RCView::td(array('valign'=>'bottom', 'style'=>'padding-left:20px;'), $senditLinks)
							)
						)
					);
print json_encode_rc(array('title'=>$dialog_title, 'content'=>$dialog_content));
