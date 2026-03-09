<?php

/* Prevent XSS input */
$_GET   = filter_input_array(INPUT_GET, FILTER_SANITIZE_STRING);
$_POST  = filter_input_array(INPUT_POST, FILTER_SANITIZE_STRING);

error_reporting(E_ALL);
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
require_once 'scripts/common.php';
$config = get_config();

if(isset($_GET['date'])){
$theDate = $_GET['date'];
} else {
$theDate = date('Y-m-d');
}
$chart = "Combo-$theDate.png";
$chart2 = "Combo2-$theDate.png";

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

$statement1 = $db->prepare("SELECT COUNT(*) FROM detections WHERE Date == \"$theDate\"");
ensure_db_ok($statement1);
$result1 = $statement1->execute();
$totalcount = $result1->fetchArray(SQLITE3_ASSOC);

if(isset($_GET['blocation']) ) {

	header("Content-type: text/csv");
	header("Content-Disposition: attachment; filename=result_file.csv");
	header("Pragma: no-cache");
	header("Expires: 0");

	$list = array ();

	//$hrsinday = intval(($sunset-$sunrise)/60/60);
	$hrsinday = 24;
	for($i=0;$i<$hrsinday;$i++) {
		$starttime = strtotime("12 AM") + (3600*$i);

		$statement1 = $db->prepare("SELECT DISTINCT(Com_Name), COUNT(*) FROM detections WHERE Date == \"$theDate\" AND Time > '".date("H:i", $starttime)."' AND Time < '".date("H:i",$starttime + 3600)."' AND Confidence > 0.75 GROUP By Com_Name ORDER BY COUNT(*) DESC");
		ensure_db_ok($statement1);
		$result1 = $statement1->execute();

		$detections = [];
		while($detection=$result1->fetchArray(SQLITE3_ASSOC))
		{
			$detections[$detection["Com_Name"]] = $detection["COUNT(*)"];
		}
		foreach($detections as $com_name=>$scount)
		{
			array_push($list, array($com_name,'','','1','',$_GET['blocation'],$config["LATITUDE"],$config["LONGITUDE"],date("m/d/Y", strtotime($theDate)), date("H:i", $starttime), $_GET['state'], $_GET['country'], $_GET['protocol'], $_GET['num_observers'], '60', 'Y', $_GET['dist_traveled'],'',$_GET['notes'] ) );
		}
	}

	$output = fopen("php://output", "w");
    foreach ($list as $row) {
        fputcsv($output, $row);
    }
    fclose($output);

	die();
}
if (get_included_files()[0] === __FILE__) {
	echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body>';
	}
?>
<style>
#attribution-dialog {
  border: none;
  border-radius: 16px;
  padding: 0;
  box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);
  max-width: 480px;
  width: 90%;
  overflow: hidden;
  background: var(--bg-card, #fff);
}
#attribution-dialog::backdrop {
  background: rgba(0,0,0,0.4);
  backdrop-filter: blur(4px);
}
.ebird-dialog-header {
  background: linear-gradient(135deg, var(--accent, #6366f1) 0%, #818cf8 100%);
  color: white;
  padding: 20px 24px;
  font-size: 1.2em;
  font-weight: 700;
  margin: 0;
}
.ebird-dialog-body {
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.ebird-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.ebird-field label {
  font-size: 0.85em;
  font-weight: 600;
  color: var(--text-secondary, #64748b);
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.ebird-field input,
.ebird-field select {
  padding: 10px 14px;
  border-radius: 10px;
  border: 1px solid var(--border, #e2e8f0);
  background: var(--bg-input, #fff);
  color: var(--text-primary, #1e293b);
  font-size: 0.95em;
  font-family: inherit;
  outline: none;
  transition: border-color 0.2s, box-shadow 0.2s;
}
.ebird-field input:focus,
.ebird-field select:focus {
  border-color: var(--accent, #6366f1);
  box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
}
.ebird-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
.ebird-actions {
  display: flex;
  gap: 10px;
  margin-top: 8px;
}
.ebird-btn-submit {
  flex: 1;
  padding: 12px;
  border: none !important;
  border-radius: 10px;
  background: #6366f1 !important;
  color: white !important;
  font-size: 1em;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.2s ease;
}
.ebird-btn-submit:hover { background: #4f46e5 !important; color: white !important; transform: translateY(-1px); }
.ebird-btn-cancel {
  padding: 12px 20px;
  border: 1px solid var(--border, #e2e8f0);
  border-radius: 10px;
  background: transparent;
  color: var(--text-secondary, #64748b);
  font-size: 1em;
  font-weight: 500;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.2s ease;
}
.ebird-btn-cancel:hover { background: var(--bg-hover, #f8fafc); }
.ebird-success {
  padding: 24px;
  text-align: center;
}
.ebird-success h3 {
  color: #166534;
  font-size: 1.4em;
  margin: 0 0 12px 0;
}
.ebird-success p {
  color: var(--text-secondary, #64748b);
  line-height: 1.6;
  margin: 0 0 16px 0;
}
.ebird-success .note {
  font-size: 0.85em;
  padding: 12px;
  background: #fef9c3;
  border-radius: 10px;
  color: #854d0e;
  margin-bottom: 16px;
}
.ebird-export-trigger {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 12px 24px;
  border: none !important;
  border-radius: 12px;
  background: #6366f1 !important;
  color: white !important;
  font-size: 1em;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  transition: all 0.2s ease;
  box-shadow: 0 4px 6px -1px rgba(99,102,241,0.3);
}
.ebird-export-trigger:hover { background: #4f46e5 !important; color: white !important; transform: translateY(-2px); box-shadow: 0 8px 15px -3px rgba(99,102,241,0.4); }
</style>

<script src="static/dialog-polyfill.js"></script>
<div class="history centered">

<dialog id="attribution-dialog">
  <p style="display:none" id="filename"></p>
  <div class="ebird-dialog-header">📋 eBird Checklist Export</div>
  <div class="ebird-dialog-body">
    <div class="ebird-row">
      <div class="ebird-field">
        <label>Export Date</label>
        <input type="date" id="export_date" value="<?php echo $theDate; ?>">
      </div>
      <div class="ebird-field">
        <label>Location Name</label>
        <input placeholder="e.g. My backyard" id="blocation">
      </div>
    </div>
    <div class="ebird-row">
      <div class="ebird-field">
        <label>State Code</label>
        <input maxlength="3" placeholder="e.g. CA" id="state">
      </div>
      <div class="ebird-field">
        <label>Country Code</label>
        <input maxlength="2" placeholder="e.g. US" id="country">
      </div>
    </div>
    <div class="ebird-row">
      <div class="ebird-field">
        <label>Protocol</label>
        <select id="protocol">
          <option value="casual">Casual</option>
          <option value="stationary">Stationary</option>
          <option value="traveling">Traveling</option>
          <option value="area">Area</option>
        </select>
      </div>
      <div class="ebird-field">
        <label>Observers</label>
        <input type="number" placeholder="1" id="num_observers">
      </div>
    </div>
    <div class="ebird-field">
      <label>Distance Traveled (miles)</label>
      <input type="number" placeholder="0" id="dist_traveled">
    </div>
    <div class="ebird-field">
      <label>Notes</label>
      <input placeholder="Optional notes..." id="notes">
    </div>
    <div class="ebird-actions">
      <button class="ebird-btn-cancel" onclick="closeDialog()">Cancel</button>
      <button class="ebird-btn-submit" onclick="submitID()">Export Checklist</button>
    </div>
  </div>
</dialog>
<script>
var dialog = document.querySelector('dialog');
dialogPolyfill.registerDialog(dialog);

function showDialog() {
  document.getElementById('attribution-dialog').showModal();
}

function closeDialog() {
  document.getElementById('attribution-dialog').close();
}

function submitID() {
  blocation = document.getElementById("blocation").value;
  state = document.getElementById("state").value;
  country = document.getElementById("country").value;
  protocol = document.getElementById("protocol").value;
  num_observers = document.getElementById("num_observers").value;
  dist_traveled = document.getElementById("dist_traveled").value;
  notes = document.getElementById("notes").value;
  export_date = document.getElementById("export_date").value;

  window.open("history.php?blocation="+blocation+"&state="+state+"&country="+country+"&protocol="+protocol+"&num_observers="+num_observers+"&dist_traveled="+dist_traveled+"&notes="+notes+"&date="+export_date);

  document.getElementById('attribution-dialog').innerHTML = "<div class='ebird-dialog-header'>✅ Export Complete</div><div class='ebird-success'><h3>Success!</h3><p>Your checklist will start downloading momentarily.</p><p>Refer to <a target='_blank' href='https://ebird.org/content/eBirdCommon/docs/ebird_import_data_process.pdf'>this guide</a> for information on how to import it in eBird.<br>The checklist file format is: 'eBird Record Format (Extended)'.</p><div class='note'>Only detections with confidence &gt; 75% were included. Entries are limited to 1 per hour per species to comply with eBird guidelines. Always verify your checklist before submitting.</div><button class='ebird-btn-submit' onclick='closeDialog()'>Close</button></div>";

}

</script>  

<div style="text-align: center; margin: 20px auto; max-width: 600px;">
    <div class="ebird-info-box" style="background: var(--bg-secondary, #f1f5f9); padding: 16px; border-radius: 12px; font-size: 0.9em; color: var(--text-secondary); margin-bottom: 24px; line-height: 1.5; text-align: left; box-shadow: var(--shadow-sm);">
      <strong style="color: var(--text-heading); font-size: 1.1em;">What gets exported?</strong><br>
      A CSV file containing all detections for the selected date with <strong>&gt;75% confidence</strong>. To comply with eBird guidelines, data is aggregated to a maximum of <strong>1 entry per species per hour</strong>.<br><br>
      <strong style="color: var(--text-heading); font-size: 1.1em;">Next Steps:</strong><br>
      After downloading, go to <a href="https://ebird.org/import" target="_blank" style="color: var(--accent); font-weight: bold; text-decoration: underline;">eBird's Data Import page</a> and select "eBird Record Format (Extended)".
    </div>
    <button type="button" class="ebird-export-trigger" onclick="showDialog()">📥 Export as CSV for eBird</button>
</div>

<?php
echo "</div>";
if (get_included_files()[0] === __FILE__) {
	echo '</html>
</body>';
}
