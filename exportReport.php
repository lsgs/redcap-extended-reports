<?php
/**
 * exportReport($extendedAttributes)
 * 
 * OK - this is complicated!
 * 
 * Rather than having to basically recreate (and maintain!) a copy of DataExport/data_export_ajax.php ...
 * 
 * > we have intercepted the client's post to redcap_vx.y.z/DataExport/data_export_ajax.php 
 * > we know the report requested has "extended properties" (is custom sql, needs reshaping etc.)
 * 
 * NOW
 * > do a behind-the scenes post to the same script to get the report to run unmodified
 *   - for an sql report this just peoduces an empty result
 *   - for an ordinary report we will get the unmodified csv file
 *   - need to pass through a param in $_GET so as to not end up here again in an infinite loop!
 * > capture the title and content html and determine the file doc id
 * > SQL report:
 *   - run the SQL report and get the CSV output - a new file
 * > Extended Report
 *   - read the data from the regular file
 *   - apply selected extended processing and generate the CSV output - a new file
 * > replace the file doc id in the html content with the new file's id
 * > return title and content to client
 */
global $Proj;

$url = APP_PATH_WEBROOT_FULL."redcap_v".REDCAP_VERSION."/DataExport/data_export_ajax.php?pid=".$Proj->project_id."&xml_metadata_options=&extended_report_hook_bypass=1";
$params = $_POST;
$timeout = null;
$content_type = 'application/x-www-form-urlencoded';
$basic_auth_user_pass = '';
 
$cookieString = '';
foreach ($_COOKIE as $key => $value) {
    $cookieString.="$key=$value; ";
}

$params['redcap_csrf_token'] = $this->getCSRFToken();
$param_string = (is_array($params)) ? http_build_query($params, '', '&') : $params;

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
$pattern = (REDCap::versionCompare(REDCAP_VERSION, '5.0.0', '>='))
    ? "\?pid={$Proj->project_id}&amp;route=FileRepositoryController:download&amp;id=(\d+)\">"
    : "FileRepository/file_download.php\?pid={$Proj->project_id}&amp;id=(\d+)\">";
$matches = array();
$match = preg_match("/$pattern/", $dialog_content, $matches);
$doc_id = (count($matches)>0) ? $matches[1] : false;

if ($doc_id) {
    /******************************************************************************
     * Generate a replacement file with sql results or data reshaped etc.
     ******************************************************************************/

    // Save defaults for CSV delimiter and decimal character
    $csvDelimiter = (isset($_POST['csvDelimiter']) && DataExport::isValidCsvDelimiter($_POST['csvDelimiter'])) ? $_POST['csvDelimiter'] : ",";
    UIState::saveUIStateValue('', 'export_dialog', 'csvDelimiter', $csvDelimiter);
    if ($csvDelimiter == 'tab') $csvDelimiter = "\t";
    $decimalCharacter = isset($_POST['decimalCharacter']) ? $_POST['decimalCharacter'] : '';
    UIState::saveUIStateValue('', 'export_dialog', 'decimalCharacter', $decimalCharacter);

    list($data_content, $num_records_returned) = $this->doExtendedReport($extendedAttributes, $_POST['export_format'], $doc_id, $csvDelimiter, $decimalCharacter);

    $sql = "select docs_name from redcap_docs where docs_id = ?";
    $q = $this->query($sql, [$doc_id]);
    $csv_filename = $q->fetch_assoc($q)['docs_name'];

    $data_edoc_id = DataExport::storeExportFile($csv_filename, trim($data_content), true, false);

    // replace original doc id for download/sendit links with new doc id
    $dialog_content = str_replace("&amp;id=$doc_id","&amp;id=$data_edoc_id",$dialog_content);
    $dialog_content = str_replace("displaySendItExportFile($doc_id);","displaySendItExportFile($data_edoc_id);",$dialog_content);
    REDCap::logEvent('External Module: Extended Reports',"Extended properties applied to report output doc_id=$doc_id: new doc_id=$data_edoc_id");
} else {
    $data_edoc_id = false;
}
if ($data_edoc_id === false) $dialog_content = "<p style='color:red'>An error occurred in processing the extended properties of this report. The file for download is unmodifed.</p>".$dialog_content;


print \json_encode_rc(array('title'=>$dialog_title, 'content'=>$dialog_content));