<?php
error_reporting(E_ERROR);
ini_set('display_errors',1);

session_start();
require_once "scripts/common.php";
$user = get_user();
$home = get_home();

$num_commits_behind = $_SESSION['behind'] ?? '0';
// This is only a status refresh. A slow or unavailable network must not leave
// the System Controls page waiting on Git's much longer connection timeout.
$fetch = shell_exec("sudo -n -u".$user." /usr/bin/timeout --kill-after=2s 15s git -C ".$home."/BirdNET-Pi fetch 2>&1");
$str = trim(shell_exec("sudo -u".$user." git -C ".$home."/BirdNET-Pi status"));
if (preg_match("/behind '.*?' by (\d+) commit(s?)\b/", $str, $matches)) {
  $num_commits_behind = $matches[1];
}
if (preg_match('/\b(\d+)\b and \b(\d+)\b different commits each/', $str, $matches)) {
    $num1 = (int) $matches[1];
    $num2 = (int) $matches[2];
    $num_commits_behind = $num1 + $num2;
}
if (stripos($str, "Your branch is up to date") !== false) {
  $num_commits_behind = '0';
}
$_SESSION['behind'] = $num_commits_behind;
$_SESSION['behind_time'] = time();

$restore = "cat $home/BirdSongs/restore.log";
$max_upload_size = floor(disk_free_space("$home/BirdNET-Pi/") / 1.001);
$db_size = file_exists("$home/BirdNET-Pi/scripts/birds.db") ? filesize("$home/BirdNET-Pi/scripts/birds.db") : 0;
$free_space = disk_free_space("$home/BirdNET-Pi/");

?><html>
<meta name="viewport" content="width=device-width, initial-scale=1">
<br>
<br>
<script>
var seconds = 0;
var systemCommandPending = false;
var systemUpdateTimer = null;
var systemUpdateMarkup = null;
var systemDisabledButtons = [];
function submitSystemButton(button) {
  const form = button.form || button.closest('form');
  if (!form) throw new Error('Action form is unavailable.');
  if (window.BirdNETUI && typeof BirdNETUI.submitButton === 'function') {
    return BirdNETUI.submitButton(form, button);
  }
  // Also work when ui-helpers.js is unavailable or an older copy is cached.
  let hidden;
  try {
    if (button.name) {
      hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = button.name;
      hidden.value = button.value;
      form.appendChild(hidden);
    }
    HTMLFormElement.prototype.submit.call(form);
  } finally {
    if (hidden) hidden.remove();
  }
}
function resetSystemCommand() {
  if (systemUpdateTimer !== null) clearInterval(systemUpdateTimer);
  systemUpdateTimer = null;
  if (systemUpdateMarkup !== null) document.getElementById('updatebtn').innerHTML = systemUpdateMarkup;
  systemUpdateMarkup = null;
  systemDisabledButtons.forEach(function(saved) { saved.button.disabled = saved.disabled; });
  systemDisabledButtons = [];
  systemCommandPending = false;
}
function failedSystemCommand() {
  resetSystemCommand();
  document.getElementById('systemCommandError').hidden = false;
}
function confirmSystemCommand(event, title, message, confirmText, danger, beforeSubmit) {
  event.preventDefault();
  if (systemCommandPending) return false;
  systemCommandPending = true;
  document.getElementById('systemCommandError').hidden = true;
  const button = event.currentTarget;
  const run = function(ok) {
    if (!ok) { resetSystemCommand(); return; }
    try {
      const form = button.form || button.closest('form');
      if (!form) throw new Error('Action form is unavailable.');
      systemDisabledButtons = Array.from(form.querySelectorAll('button')).map(function(b) {
        const saved = {button:b, disabled:b.disabled}; b.disabled = true; return saved;
      });
      if (typeof beforeSubmit === 'function') beforeSubmit();
      submitSystemButton(button);
    } catch (e) { failedSystemCommand(); }
  };
  try {
    if (window.BirdNETUI && typeof BirdNETUI.confirmAction === 'function') {
      BirdNETUI.confirmAction({title: title, message: message, confirmText: confirmText, danger: danger}).then(run).catch(failedSystemCommand);
    } else run(confirm(message));
  } catch (e) { failedSystemCommand(); }
  return false;
}
function update(event) {
  return confirmSystemCommand(event, 'Update BirdNET-Pi', 'This will pull updates and restart services. The web UI may be unavailable while the update runs.', 'Update', false, function() {
    const button = document.getElementById('updatebtn');
    systemUpdateMarkup = button.innerHTML;
    let elapsed = 0;
    const tick = function() {
      // This measures waiting for a response, not server-side update progress.
      button.innerHTML = "Update requested: <pre id='timer' class='bash'>" + new Date(elapsed * 1000).toISOString().substring(14, 19) + "</pre>";
      elapsed += 1;
    };
    tick();
    systemUpdateTimer = setInterval(tick, 1000);
  });
}
window.addEventListener('pageshow', function(event) { if (event.persisted) resetSystemCommand(); });
</script>
<div class="systemcontrols">
<div class="ui-message ui-message-info" style="max-width:720px;margin:0 auto 16px;">
  <strong>Maintenance actions</strong>
  <span>Backup before restore or clear-data operations. Current database: <?php echo round($db_size / 1024 / 1024, 1); ?> MB. Free disk space: <?php echo round($free_space / 1024 / 1024 / 1024, 1); ?> GB.</span>
</div>
<div id="systemCommandError" role="alert" hidden>
  <div class="ui-message ui-message-error">Could not submit this action. Refresh the page and try again.</div>
</div>
<form action="views.php" method="GET">
  <div>
    <button type="submit" name="submit" value="sudo reboot" onclick="return confirmSystemCommand(event, 'Reboot BirdNET-Pi', 'This will restart the Raspberry Pi and temporarily stop detection.', 'Reboot', true)">Reboot</button>
  </div>
  <div>
    <button type="submit" name="submit" id="updatebtn" value="update_birdnet.sh" onclick="return update(event);">Update <?php if(isset($_SESSION['behind']) && $_SESSION['behind'] != "0" && $_SESSION['behind'] != "with"){?><div class="updatenumber"><?php echo $_SESSION['behind']; ?></div><?php } ?></button>
  </div>
  <div>
    <button type="submit" name="submit" value="sudo shutdown now" onclick="return confirmSystemCommand(event, 'Shutdown BirdNET-Pi', 'This will power down the Raspberry Pi. You will need physical access or power cycling to start it again.', 'Shutdown', true)">Shutdown</button>
  </div>
  <div>
    <button type="submit" name="submit" value="sudo clear_all_data.sh" onclick="return confirmSystemCommand(event, 'Clear all data', 'This permanently deletes detection data and cannot be undone. Create a backup first if you may need this data later.', 'Clear all data', true)">Clear ALL data</button>
  </div>
</form>
<div id="container">
  <button id="pickfile" type="button" href="javascript:;">Restore data</button>
</div>
<div><a href="scripts/backup.php" download onclick="return window.BirdNETUI ? BirdNETUI.confirmLink(event, {title:'Download backup', message:'This may take a while for large databases. Keep the browser open until the download starts.', confirmText:'Download backup'}) : confirm('Download backup? Note that this could take a long time.')"><button>Backup data</button></a></div>
<?php
  $cmd="cd ".$home."/BirdNET-Pi && sudo -u ".$user." git rev-list --max-count=1 HEAD";
  $curr_hash = shell_exec($cmd);
?>
  <p style="font-size:11px;text-align:center"></br></br>Running version: </p>
  <a href="https://github.com/zach7036/BirdNET-Pi-Enhanced-Version/commit/<?php echo $curr_hash; ?>" target="_blank">
    <p style="font-size:11px;text-align:center;box-sizing: border-box"><?php echo $curr_hash; ?></p>
  </a>
  <pre id="console" style="text-align:center"></pre>
</div>
<script type="text/javascript">
// based on Custom example logic

var uploader = new plupload.Uploader({
    runtimes : 'html5',
    browse_button : 'pickfile', // you can pass an id...
    container: document.getElementById('container'), // ... or DOM Element itself
    url : 'scripts/restore.php',
    chunk_size: '2mb',
    multi_selection: false,

    filters : {
        max_file_size : '<?php echo "$max_upload_size"; ?>',
        mime_types: [
            {title : "Tar files", extensions : "tar"}
        ]
    },

    init: {
        FilesAdded: function(up, files) {
            const startRestore = function() { uploader.start(); };
            if (window.BirdNETUI) {
                BirdNETUI.confirmAction({
                    title: 'Restore data',
                    message: 'This will restore data from the selected backup file and may overwrite current data. Make sure you selected the correct backup.',
                    confirmText: 'Restore',
                    danger: true
                }).then(function(ok) {
                    if (ok) startRestore();
                    else uploader.removeFile(files[0]);
                });
            } else if (confirm('Restore data from this backup?')) {
                startRestore();
            } else {
                uploader.removeFile(files[0]);
            }
        },

        UploadProgress: function(up, file) {
            if (file.percent !== 100) {
                document.getElementById('pickfile').innerHTML = "<span>Uploading: <pre id='timer' class='bash'>" + String(file.percent).padStart(2, '0') + "%</pre></span>";
            } else {
                setInterval(function(){ seconds += 1; document.getElementById('pickfile').innerHTML = "Restoring: <pre id='timer' class='bash'>"+new Date(seconds * 1000).toISOString().substring(14, 19)+"</pre>"; }, 1000);
            }
        },

        FileUploaded: function(up, file, info) {
            // Called when file has finished uploading
            console.log('[FileUploaded] File:', file, "Info:", info);
            const xhttp = new XMLHttpRequest();
            xhttp.onload = function() {
                if(this.responseText.length > 0) {
                    document.body.innerHTML=this.responseText;
                }
            };
            xhttp.open("GET", "views.php?submit=<?php echo "$restore"; ?>");
            xhttp.send();
        },

        Error: function(up, err) {
            document.getElementById('console').appendChild(document.createTextNode("\nError #" + err.code + ": " + err.message));
        }
    }
});

uploader.init();
</script>
