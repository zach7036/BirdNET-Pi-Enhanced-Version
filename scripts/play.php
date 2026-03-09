<?php

/* Prevent XSS input */
$_GET   = filter_input_array(INPUT_GET, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$_POST  = filter_input_array(INPUT_POST, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

error_reporting(E_ERROR);
ini_set('display_errors',1);
require_once 'scripts/common.php';
$home = get_home();
$config = get_config();
$user = get_user();

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

if(isset($_GET['deletefile'])) {
  ensure_authenticated('You must be authenticated to delete files.');
  if (preg_match('~^.*(\.\.\/).+$~', $_GET['deletefile'])) {
    echo "Error";
    die();
  }
  $db_writable = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READWRITE);
  $db->busyTimeout(1000);
  $statement1 = $db_writable->prepare('DELETE FROM detections WHERE File_Name = :file_name LIMIT 1');
  ensure_db_ok($statement1);
  $statement1->bindValue(':file_name', explode("/", $_GET['deletefile'])[2]);
  $file_pointer = $home."/BirdSongs/Extracted/By_Date/".$_GET['deletefile'];
  if (!exec("sudo rm ".escapeshellarg($file_pointer)." 2>&1 && sudo rm ".escapeshellarg($file_pointer.".png")." 2>&1", $output)) {
    echo "OK";
  } else {
    echo "Error - file deletion failed : " . implode(", ", $output) . "<br>";
  }
  $result1 = $statement1->execute();
  if ($result1 === false || $db_writable->changes() === 0) {
    echo "Error - database line deletion failed : " . $db_writable->lastErrorMsg();
  }
  $db_writable->close();
  die();
}

if(isset($_GET['excludefile'])) {
  ensure_authenticated('You must be authenticated to change the protection of files.');
  if(!file_exists($home."/BirdNET-Pi/scripts/disk_check_exclude.txt")) {
    file_put_contents($home."/BirdNET-Pi/scripts/disk_check_exclude.txt", "##start\n##end\n");
  }
  if(isset($_GET['exclude_add'])) {
    $myfile = fopen($home."/BirdNET-Pi/scripts/disk_check_exclude.txt", "a") or die("Unable to open file!");
    $txt = $_GET['excludefile'];
    fwrite($myfile, $txt."\n");
    fwrite($myfile, $txt.".png\n");
    fclose($myfile);
    echo "OK";
    die();
  } else {
    $lines  = file($home."/BirdNET-Pi/scripts/disk_check_exclude.txt");
    $search = $_GET['excludefile'];

    $result = '';
    foreach($lines as $line) {
      if(stripos($line, $search) === false && stripos($line, $search.".png") === false) {
        $result .= $line;
      }
    }
    file_put_contents($home."/BirdNET-Pi/scripts/disk_check_exclude.txt", $result);
    echo "OK";
    die();
  }
}

if(isset($_GET['getlabels'])) {
    $labels = file('./scripts/labels.txt', FILE_IGNORE_NEW_LINES);
    echo json_encode($labels);
    die();
}

if(isset($_GET['changefile']) && isset($_GET['newname'])) {
  ensure_authenticated('You must be authenticated to delete files.');
  if (preg_match('~^.*(\.\.\/).+$~', $_GET['changefile'])) {
    echo "Error";
    die();
  }
  $oldname = basename(urldecode($_GET['changefile']));
  $newname = urldecode($_GET['newname']);
  if (!exec("sudo -u ".$user." ".$home."/BirdNET-Pi/scripts/birdnet_changeidentification.sh \"$oldname\" \"$newname\" log_errors 2>&1", $output)) {
    echo "OK";
  } else {
    echo "Error : " . implode(", ", $output) . "<br>";
  }
  die();
}

$shifted_path = $home."/BirdSongs/Extracted/By_Date/shifted/";

if(isset($_GET['shiftfile'])) {
  ensure_authenticated('You cannot shift files for this installation');

    $filename = $_GET['shiftfile'];
    $pp = pathinfo($filename);
    $dir = $pp['dirname'];
    $fn  = $pp['filename'];
    $ext = $pp['extension'];
    $pi = $home."/BirdSongs/Extracted/By_Date/";

    if(isset($_GET['doshift'])) {
  $freqshift_tool = $config['FREQSHIFT_TOOL'];

  if ($freqshift_tool == "ffmpeg") {
    $cmd = "sudo /usr/bin/nohup /usr/bin/ffmpeg -y -i ".escapeshellarg($pi.$filename)." -af \"rubberband=pitch=".$config['FREQSHIFT_LO']."/".$config['FREQSHIFT_HI']."\" ".escapeshellarg($shifted_path.$filename)."";
    shell_exec("sudo mkdir -p ".$shifted_path.$dir." && ".$cmd);

  } else if ($freqshift_tool == "sox") {
    //linux.die.net/man/1/sox
    $soxopt = "-q";
    $soxpitch = $config['FREQSHIFT_PITCH'];
    $cmd = "sudo /usr/bin/nohup /usr/bin/sox ".escapeshellarg($pi.$filename)." ".escapeshellarg($shifted_path.$filename)." pitch ".$soxopt." ".$soxpitch;
   shell_exec("sudo mkdir -p ".$shifted_path.$dir." && ".$cmd);
  }
    } else {
     $cmd = "sudo rm -f " . escapeshellarg($shifted_path.$filename);
     shell_exec($cmd);
    }

    echo "OK";
    die();
}

if(isset($_GET['bydate'])){
  $statement = $db->prepare('SELECT DISTINCT(Date) FROM detections GROUP BY Date ORDER BY Date DESC');
  ensure_db_ok($statement);
  $result = $statement->execute();
  $view = "bydate";

  #Specific Date
} elseif(isset($_GET['date'])) {
  $date = $_GET['date'];
  session_start();
  $_SESSION['date'] = $date;
  $result = fetch_species_array($_GET['sort'], $date);
  $view = "date";

  #By Species
} elseif(isset($_GET['byspecies'])) {
  $result = fetch_species_array($_GET['sort']);
  $view = "byspecies";

  #Specific Species
} elseif(isset($_GET['species'])) {
  $species = htmlspecialchars_decode($_GET['species'], ENT_QUOTES);
  session_start();
  $_SESSION['species'] = $species;
  $result2 = fetch_all_detections($species, $_GET['sort'], $_SESSION['date']);
  $view = "species";
} else {
  unset($_SESSION['species']);
  unset($_SESSION['date']);
  $view = "choose";
}

if (get_included_files()[0] === __FILE__) {
  echo '<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
  </head>';
}

?>
<script src="static/custom-audio-player.js"></script>
<script>

function deleteDetection(filename,copylink=false) {
  if (confirm("Are you sure you want to delete this detection from the database?") == true) {
    const xhttp = new XMLHttpRequest();
    xhttp.onload = function() {
      if(this.responseText == "OK"){
        if(copylink == true) {
          window.top.close();
        } else {
          location.reload();
        }
      } else {
        alert(this.responseText);
      }
    }
    xhttp.open("GET", "play.php?deletefile="+filename, true);
    xhttp.send();
  }
}

function toggleLock(filename, type, elem) {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText == "OK"){
      if(type == "add") {
        elem.setAttribute("src","images/lock.svg");
        elem.setAttribute("title", "This file is excluded from being purged.");
        elem.setAttribute("onclick", elem.getAttribute("onclick").replace("add","del"));
      } else {
        elem.setAttribute("src","images/unlock.svg");
        elem.setAttribute("title", "This file will be deleted when disk space needs to be freed.");
        elem.setAttribute("onclick", elem.getAttribute("onclick").replace("del","add"));
      }
    }
  }
  if(type == "add") {
    xhttp.open("GET", "play.php?excludefile="+filename+"&exclude_add=true", true);
  } else {
    xhttp.open("GET", "play.php?excludefile="+filename+"&exclude_del=true", true);  
  }
  xhttp.send();
  elem.setAttribute("src","images/spinner.gif");
}

function toggleShiftFreq(filename, shiftAction, elem) {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText == "OK"){
      if(shiftAction == "shift") {
        elem.setAttribute("src","images/unshift.svg");
        elem.setAttribute("title", "This file has been shifted down in frequency.");
        elem.setAttribute("onclick", elem.getAttribute("onclick").replace("shift","unshift"));
	console.log("shifted freqs of " + filename);
        const audioDiv = elem.parentNode.querySelector(".custom-audio-player");
        if (audioDiv) {
          audioDiv.setAttribute("data-audio-src", audioDiv.getAttribute("data-audio-src").replace("/By_Date/", "/By_Date/shifted/"));
        } else {
          const atag = elem.parentNode.querySelector("a");
          if (atag) {
            atag.setAttribute("href", atag.getAttribute("href").replace("/By_Date/", "/By_Date/shifted/"));
          }
        }
      } else {
        elem.setAttribute("src","images/shift.svg");
        elem.setAttribute("title", "This file is not shifted in frequency.");
        elem.setAttribute("onclick", elem.getAttribute("onclick").replace("unshift","shift"));
        console.log("unshifted freqs of " + filename);
        const audioDiv = elem.parentNode.querySelector(".custom-audio-player");
        if (audioDiv) {
          audioDiv.setAttribute("data-audio-src", audioDiv.getAttribute("data-audio-src").replace("/By_Date/shifted/", "/By_Date/"));
        } else {
          const atag = elem.parentNode.querySelector("a");
          if (atag) {
            atag.setAttribute("href", atag.getAttribute("href").replace("/By_Date/shifted/", "/By_Date/"));
          }
        }
      }
    }
  }
  if(shiftAction == "shift") {
    console.log("shifting freqs of " + filename);
    xhttp.open("GET", "play.php?shiftfile="+filename+"&doshift=true", true);
  } else {
    console.log("unshifting freqs of " + filename);
    xhttp.open("GET", "play.php?shiftfile="+filename, true);  
  }
  xhttp.send();
  elem.setAttribute("src","images/spinner.gif");
}

function changeDetection(filename,copylink=false) {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    const labels = JSON.parse(this.responseText);
    let dropdown = '<input type="text" id="filterInput" placeholder="Type to filter..."> <button id="cancelButton">Cancel</button> <br><select id="labelDropdown" class="testbtn" size="5" style="display: block; margin: 0 auto;"></select>';

	// Check if the modal already exists
    let modal = document.getElementById('myModal');
    if (!modal) {
      // Create a modal box
      modal = document.createElement('div');
      modal.setAttribute('id', 'myModal');
      modal.setAttribute('class', 'modal');

      // Create a content box
      let content = document.createElement('div');
      content.setAttribute('class', 'modal-content');

      // Add a title to the modal box
      let title = document.createElement('h2');
      title.textContent = 'Please select the correct species here:';
      content.appendChild(title);

      // Add the dropdown to the content
      let selectElement = document.createElement('div');
      selectElement.innerHTML = dropdown;
      content.appendChild(selectElement);

      // Append the content to the modal
      modal.appendChild(content);

      // Append the modal to the body
      document.body.appendChild(modal);
    }

    // Display the modal
    modal.style.display = "block";

    // Populate the dropdown list
    let dropdownList = document.getElementById('labelDropdown');
    labels.forEach(label => {
      let option = document.createElement('option');
      option.value = label;
      option.text = label;
      dropdownList.appendChild(option);
    });

    // Add an event listener to the modal box to hide it when clicked outside
    document.addEventListener('click', function(event) {
      if (event.target == modal) {
        modal.style.display = "none";
        dropdownList.selectedIndex = -1; // Reset the dropdown selection
      }
    });

    // Add an event listener to the input box to filter the dropdown list
    document.getElementById('filterInput').addEventListener('keyup', function() {
      let filter = this.value.toUpperCase();
      let options = dropdownList.options;
      // Clear the dropdown list
      while (dropdownList.firstChild) {
        dropdownList.removeChild(dropdownList.firstChild);
      }
      // Populate the dropdown list with the filtered labels
      labels.forEach(label => {
        if (label.toUpperCase().indexOf(filter) > -1) {
          let option = document.createElement('option');
          option.value = label;
          option.text = label;
          dropdownList.appendChild(option);
        }
      });
    });

    // Add an event listener to the cancel button to hide the modal box
    document.getElementById('cancelButton').addEventListener('click', function() {
      modal.style.display = "none";
      dropdownList.selectedIndex = -1; // Reset the dropdown selection
    });

    dropdownList.addEventListener('change', function() {
      const newname = this.value;
      // Check if the default option is selected
      if (newname === '') {
        return; // Exit the function early
      }
      if (confirm("Are you sure you want to change the specie identified in this detection to " + newname + "?") == true) {
        const xhttp2 = new XMLHttpRequest();
        xhttp2.onload = function() {
          if(this.responseText == "OK"){
            if(copylink == true) {
              alert("Successfully converted");
              window.top.close();
            } else {
              alert("Successfully converted");
              location.reload();
            }
          } else {
            alert(this.responseText);
          }
        }
        xhttp2.open("GET", "play.php?changefile="+filename+"&newname="+newname, true);
        xhttp2.send();
      }
      // Hide the modal box and reset the dropdown selection
      modal.style.display = "none";
      this.selectedIndex = -1;
    });
  }
  xhttp.open("GET", "play.php?getlabels=true", true);
  xhttp.send();
}

</script>

<?php
#If no specific species
if(!isset($_GET['species']) && !isset($_GET['filename'])){
?>
<div class="play">
<?php if($view == "byspecies" || $view == "date" || $view == "bydate") { ?>
<style>
   .scrolling-species-view {
       padding: 10px;
       max-width: 1200px;
       margin: 0 auto;
   }
   .sticky-sort-bar {
       position: sticky;
       top: 0;
       background: var(--bg-primary);
       padding: 15px 0 20px 0;
       z-index: 100;
       margin-bottom: 20px;
       border-bottom: 1px solid var(--border-light);
       box-shadow: 0 4px 15px -10px rgba(0,0,0,0.1);
   }
   .sort-options-container {
       display: flex;
       justify-content: center;
       gap: 12px;
       flex-wrap: wrap;
   }
   .sort-btn {
       display: inline-flex;
       align-items: center;
       gap: 8px;
       padding: 8px 16px;
       border-radius: 20px;
       background: var(--bg-card);
       border: 1px solid var(--border);
       color: var(--text-secondary);
       font-size: 0.9em;
       font-weight: 600;
       cursor: pointer;
       transition: all 0.2s ease;
       text-decoration: none;
       box-shadow: var(--shadow-sm);
   }
   .sort-btn img {
       width: 16px;
       height: 16px;
       opacity: 0.7;
       transition: all 0.2s ease;
   }
   .sort-btn.active {
       background: var(--accent);
       border-color: var(--accent);
       color: white;
       box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
   }
   .sort-btn.active img {
       opacity: 1;
       filter: brightness(0) invert(1);
   }
   .sort-btn:hover:not(.active) {
       background: var(--bg-hover);
       border-color: var(--border-light);
       color: var(--text-primary);
       transform: translateY(-2px);
   }
   
   .species-grid {
       display: grid;
       grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
       gap: 15px;
       padding-bottom: 40px;
   }
   .species-card {
       background: var(--bg-card);
       border: 1px solid var(--border);
       border-radius: 12px;
       padding: 15px 20px;
       display: flex;
       justify-content: space-between;
       align-items: center;
       text-decoration: none;
       color: var(--text-heading);
       transition: all 0.2s ease;
       box-shadow: var(--shadow-sm);
       cursor: pointer;
   }
   .species-card:hover {
       transform: translateY(-3px);
       box-shadow: var(--shadow-md);
       border-color: var(--accent);
   }
   .species-card-name {
       font-weight: 700;
       font-size: 1.05em;
   }
   .species-card-metric {
       font-size: 0.8em;
       font-weight: 800;
       padding: 4px 8px;
       border-radius: 8px;
       background: var(--accent-subtle);
       color: var(--accent);
   }
   .date-grid {
       display: grid;
       grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
       gap: 15px;
       padding: 20px 10px 40px 10px;
       max-width: 1200px;
       margin: 0 auto;
   }
   .date-card {
       background: var(--bg-card);
       border: 1px solid var(--border);
       border-radius: 12px;
       padding: 15px 20px;
       display: flex;
       align-items: center;
       gap: 12px;
       text-decoration: none;
       color: var(--text-heading);
       transition: all 0.2s ease;
       box-shadow: var(--shadow-sm);
       cursor: pointer;
       font-weight: 600;
       font-size: 1.1em;
   }
   .date-card:hover {
       transform: translateY(-3px);
       box-shadow: var(--shadow-md);
       border-color: var(--accent);
   }
   .date-card.today {
       background: var(--accent-subtle);
       border-color: var(--accent);
       color: var(--accent);
   }
   .date-card.today:hover {
       background: var(--accent);
       color: white;
   }
   .date-icon {
       font-size: 1.2em;
       opacity: 0.8;
   }
</style>
<?php } ?>

<?php if($view == "byspecies" || $view == "date") { ?>
<div class="scrolling-species-view">
   <div class="sticky-sort-bar">
      <form action="views.php" method="GET" class="sort-options-container">
         <input type="hidden" name="view" value="Recordings">
         <input type="hidden" name="<?php echo $view; ?>" value="<?php echo $_GET['date']; ?>">
         
         <button class="sort-btn <?php echo (!isset($_GET['sort']) || $_GET['sort'] == 'alphabetical') ? 'active' : ''; ?>" type="submit" name="sort" value="alphabetical">
            <img src="images/sort_abc.svg" alt="A-Z"> A-Z
         </button>
         
         <button class="sort-btn <?php echo (isset($_GET['sort']) && $_GET['sort'] == 'occurrences') ? 'active' : ''; ?>" type="submit" name="sort" value="occurrences">
            <img src="images/sort_occ.svg" alt="Count"> Frequency
         </button>
         
         <button class="sort-btn <?php echo (isset($_GET['sort']) && $_GET['sort'] == 'confidence') ? 'active' : ''; ?>" type="submit" name="sort" value="confidence">
            <img src="images/sort_conf.svg" alt="Confidence"> Confidence
         </button>
         
         <button class="sort-btn <?php echo (isset($_GET['sort']) && $_GET['sort'] == 'date') ? 'active' : ''; ?>" type="submit" name="sort" value="date">
            <img src="images/sort_date.svg" alt="Date"> Most Recent
         </button>
      </form>
   </div>
<br>
<?php } ?>
<?php if ($view != "choose") { ?>
<form action="views.php" method="GET">
<input type="hidden" name="view" value="Recordings">
<?php } ?>
<?php
  #By Date
  if($view == "bydate") {
    
    echo "<div class='date-grid'>";
    while($results=$result->fetchArray(SQLITE3_ASSOC)){
      $date = $results['Date'];
      if(realpath($home."/BirdSongs/Extracted/By_Date/".$date) !== false){
        $is_today = ($date == date('Y-m-d'));
        $display_text = $is_today ? "Today" : $date;
        $card_class = $is_today ? "date-card today" : "date-card";
        
        echo "<a href=\"views.php?view=Recordings&date={$date}\" class=\"{$card_class}\">";
        echo "<span class=\"date-icon\">📅</span>";
        echo "<span>{$display_text}</span>";
        echo "</a>";
      }
    }
    echo "</div>";
    
    // Resume arbitrary tag structure to prevent breaking the final if condition
    if ($view != "choose") { echo "</form>"; }
    

          #By Species
  } elseif($view == "byspecies") {
    $birds = array();
    $values = array();
    while($results=$result->fetchArray(SQLITE3_ASSOC))
    {
      $birds[] = $results['Sci_Name'];
      $values[] = get_label($results, $_GET['sort']);
    }

    if ($view != "choose") { echo "<table>"; }
    
    echo "<div class='species-grid'>";
    for ($index = 0; $index < count($birds); $index++) {
        // Build GET parameters retaining current page state
        $query_args = array(
            'view' => 'Recordings',
            'species' => $birds[$index]
        );
        if(isset($_GET['sort'])) { $query_args['sort'] = $_GET['sort']; }
        $destination = "views.php?" . http_build_query($query_args);

        // Separate the species name and the numeric value/label
        $split_val = explode("<br>", $values[$index]);
        $main_name = strip_tags($split_val[0] ?: $values[$index]);
        $metric = strip_tags($split_val[1] ?: '');
        
        ?>
        <a href="<?php echo htmlspecialchars($destination); ?>" class="species-card">
            <span class="species-card-name"><?php echo htmlspecialchars($main_name); ?></span>
            <?php if (!empty($metric)): ?>
            <span class="species-card-metric"><?php echo htmlspecialchars($metric); ?></span>
            <?php endif; ?>
        </a>
        <?php
    }
    echo "</div>";
    
    // Resume arbitrary tag structure to prevent breaking the final if condition
    if ($view != "choose") { echo "<table>"; }
    
  } elseif($view == "date") {
    $birds = array();
    $values = array();
    while($results=$result->fetchArray(SQLITE3_ASSOC))
    {
      $dir_name = str_replace("'", '', $results['Com_Name']);
      if(realpath($home."/BirdSongs/Extracted/By_Date/".$date."/".str_replace(" ", "_", $dir_name)) !== false){
        $birds[] = $results['Sci_Name'];
        $values[] = get_label($results, $_GET['sort'], $_GET['date']);
      }
    }

    echo "<div class='species-grid'>";
    for ($index = 0; $index < count($birds); $index++) {
        // Build GET parameters retaining current page state
        $query_args = array(
            'view' => 'Recordings',
            'species' => $birds[$index]
        );
        if(isset($_GET['sort'])) { $query_args['sort'] = $_GET['sort']; }
        $destination = "views.php?" . http_build_query($query_args);

        // Separate the species name and the numeric value/label
        $split_val = explode("<br>", $values[$index]);
        $main_name = strip_tags($split_val[0] ?: $values[$index]);
        $metric = strip_tags($split_val[1] ?: '');
        
        ?>
        <a href="<?php echo htmlspecialchars($destination); ?>" class="species-card">
            <span class="species-card-name"><?php echo htmlspecialchars($main_name); ?></span>
            <?php if (!empty($metric)): ?>
            <span class="species-card-metric"><?php echo htmlspecialchars($metric); ?></span>
            <?php endif; ?>
        </a>
        <?php
    }
    echo "</div>";
    
    // Resume arbitrary tag structure to prevent breaking the final if condition
    if ($view != "choose") { echo "<table>"; }

    #Choose Dashboard
  } else {
    ?>

    <style>
        .recordings-dashboard {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
            color: var(--text-primary);
        }
        .recordings-header {
            text-align: left;
            margin-bottom: 30px;
            background: var(--bg-card);
            padding: 25px 35px;
            border-radius: 20px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .recordings-header h1 {
            margin: 0;
            font-size: 2em;
            background: linear-gradient(135deg, var(--accent) 0%, #6366f1 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .recordings-header p {
            margin: 5px 0 0;
            color: var(--text-secondary);
            font-size: 1.05em;
        }
        
        .nav-cards-grid {
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 40px;
        }
        .nav-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 15px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            text-decoration: none;
            color: var(--text-heading);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: var(--shadow-sm);
            cursor: pointer;
            border: none;
            flex: 1 1 0;
            min-width: 0;
            word-wrap: break-word;
        }
        .nav-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
            border-color: var(--accent);
            background: var(--bg-primary);
        }
        .nav-card-icon {
            font-size: 2em;
            margin-bottom: 10px;
            background: var(--accent-subtle);
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 15px;
            float: none;
        }
        .nav-card-title {
            font-size: 1.1em;
            font-weight: 800;
            margin-bottom: 5px;
        }
        .nav-card-desc {
            font-size: 0.8em;
            color: var(--text-muted);
            line-height: 1.3;
        }

        .recent-section-title {
            font-size: 1.3em;
            font-weight: 700;
            color: var(--text-heading);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--border-light);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .recent-table-wrap table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            margin-top: -8px;
        }
        .recent-table-wrap td {
            background: var(--bg-card) !important;
            padding: 15px !important;
            border: 1px solid var(--border-light) !important;
            border-radius: 12px;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
            position: relative;
        }
        .recent-table-wrap td:hover {
            border-color: var(--accent) !important;
            transform: scale(1.01);
            box-shadow: var(--shadow-md);
        }
        .copyimage {
            opacity: 0.6;
            transition: all 0.2s ease;
        }
        .copyimage:hover {
            opacity: 1;
            transform: scale(1.1);
        }
    </style>

    <div class="recordings-dashboard">
        <header class="recordings-header">
            <div style="font-size: 2.5em; background: var(--accent-subtle); padding: 12px; border-radius: 16px;">🎵</div>
            <div>
                <h1>Recordings Library</h1>
                <p>Browse, manage, and listen to all audio detections captured by your station.</p>
            </div>
        </header>

        <div class="nav-cards-grid">
            
            <a href="views.php?view=Recordings&byspecies=byspecies" class="nav-card">
                <div class="nav-card-icon">🦆</div>
                <div class="nav-card-title">Browse by Species</div>
                <div class="nav-card-desc">View an alphabetical grid of all identified birds to find specific recordings.</div>
            </a>
            
            <a href="views.php?view=Recordings&bydate=bydate" class="nav-card">
                <div class="nav-card-icon">📅</div>
                <div class="nav-card-title">Browse by Date</div>
                <div class="nav-card-desc">Scroll through an archive of detections organized historically by day.</div>
            </a>
            
            <a href="views.php?view=Species+Stats" target="_parent" class="nav-card">
                <div class="nav-card-icon">⭐</div>
                <div class="nav-card-title">Best Recordings</div>
                <div class="nav-card-desc">Listen to the absolute highest-confidence captures for each species.</div>
            </a>
        </div>

    </div>
    <?php
  }
  
  if ($view != "choose") {
    echo "</table></form>";
  }
}

#Specific Species
if(isset($_GET['species'])){ ?>
<div style="width: auto;
   text-align: center">
   <form action="views.php" method="GET">
      <input type="hidden" name="view" value="Recordings">
      <input type="hidden" name="species" value="<?php echo $_GET['species']; ?>">
      <input type="hidden" name="sort" value="<?php echo $_GET['sort']; ?>">
      <button <?php if(!isset($_GET['sort']) || $_GET['sort'] == "" || $_GET['sort'] == "date"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="date">
         <img width=35px src="images/sort_date.svg" title="Sort by date" alt="Sort by date">
      </button>
      <button <?php if(isset($_GET['sort']) && $_GET['sort'] == "confidence"){ echo "class='sortbutton active'";} else { echo "class='sortbutton'"; }?> type="submit" name="sort" value="confidence">
         <img src="images/sort_occ.svg" title="Sort by confidence" alt="Sort by confidence">
      </button><br>
      <label style="cursor: pointer; margin-top: 10px; margin-bottom: 10px;font-weight: normal; display: inline-flex; align-items: center; justify-content: center;">
        <input type="checkbox" name="only_excluded" <?= isset($_GET['only_excluded']) ? 'checked' : '' ?> onchange="submit()" style="display:none;">
        <span style="width: 40px; height: 20px; background: <?= isset($_GET['only_excluded']) ? '#555555' : 'rgba(85, 85, 85, 0.3)' ?>; border: 1px solid #777777; border-radius: 20px; display: inline-block; position: relative; margin-right: 8px; transition: background 0.4s, border 0.4s; box-sizing: border-box;">
        <span style="width: 16px; height: 16px; background: white; border-radius: 50%; position: absolute; top: 1.5px; left: 2px; transition: 0.4s; display: flex; align-items: center; justify-content: center; font-size: 14px; color: black; <?= isset($_GET['only_excluded']) ? 'transform: translateX(20px);' : '' ?>">
        <?= isset($_GET['only_excluded']) ? '✓' : '' ?>
      </span></span>Only Show Purge Excluded</label>
   </form>
</div>
<?php
  // add disk_check_exclude.txt lines into an array for grepping
  $fp = @fopen($home."/BirdNET-Pi/scripts/disk_check_exclude.txt", 'r'); 
if ($fp) {
  $disk_check_exclude_arr = explode("\n", fread($fp, filesize($home."/BirdNET-Pi/scripts/disk_check_exclude.txt")));
} else {
  $disk_check_exclude_arr = [];
}

$name = htmlspecialchars_decode($_GET['species'], ENT_QUOTES);
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 40;

$result2 = fetch_all_detections($name, $_GET['sort'], $_SESSION['date']);
$results=$result2->fetchArray(SQLITE3_ASSOC);
$com_name = $results['Com_Name'];
$result2->reset(); // reset the pointer to the beginning of the result set
$sciname = $name;
$info_url = get_info_url($sciname);
$url = $info_url['URL'];
echo "<table>
  <tr><th>$com_name<br><span style=\"font-weight:normal;\">
  <i>$sciname</i></span><br>
    <a href=\"$url\" target=\"_blank\"><img title=\"$url_title\" src=\"images/info.png\" width=\"20\"></a>
    <a href=\"https://wikipedia.org/wiki/$sciname\" target=\"_blank\"><img title=\"Wikipedia\" src=\"images/wiki.png\" width=\"20\"></a>
  </th></tr>";
  $iter=0;
  while($results=$result2->fetchArray(SQLITE3_ASSOC))
  {
    $comname = preg_replace('/ /', '_', $results['Com_Name']);
    $comname = preg_replace('/\'/', '', $comname);
    $date = $results['Date'];
    $filename = "/By_Date/".$date."/".$comname."/".$results['File_Name'];
    $filename_shifted = "/By_Date/shifted/".$date."/".$comname."/".$results['File_Name'];
    $filename_png = $filename . ".png";
    $sciname = preg_replace('/ /', '_', $results['Sci_Name']);
    $sci_name = $results['Sci_Name'];
    $time = $results['Time'];
    $values = round((float)round($results['Confidence'],2) * 100 ) . '%';
    $filename_formatted = $date."/".$comname."/".$results['File_Name'];

    // file was deleted by disk check, no need to show the detection in recordings
    if(!file_exists($home."/BirdSongs/Extracted/".$filename)) {
      continue;
    }
    if(!in_array($filename_formatted, $disk_check_exclude_arr) && isset($_GET['only_excluded'])) {
      continue;
    }
    $iter++;
    if($iter > $limit) {
      $iter_additional=true;
      break;
    }

    if($iter < 100){
      $imageelem = "<div class='custom-audio-player' data-audio-src=\"$filename\" data-image-src=\"$filename_png\"></div>";
    } else {
      $imageelem = "<a href=\"$filename\"><img src=\"$filename_png\"></a>";
    }

      if(!in_array($filename_formatted, $disk_check_exclude_arr)) {
        $imageicon = "images/unlock.svg";
        $title = "This file will be deleted when disk space needs to be freed (>95% usage).";
        $type = "add";
      } else {
        $imageicon = "images/lock.svg";
        $title = "This file is excluded from being purged.";
        $type = "del";
      }

      if(file_exists($shifted_path.$filename_formatted)) {
        $shiftImageIcon = "images/unshift.svg";
        $shiftTitle = "This file has been shifted down in frequency."; 
        $shiftAction = "unshift";
  $filename = $filename_shifted;
      } else {
        $shiftImageIcon = "images/shift.svg";
        $shiftTitle = "This file is not shifted in frequency.";
        $shiftAction = "shift";
      }

      echo "<tr>
  <td class=\"relative\"> 

<img style='cursor:pointer;right:120px' src='images/delete.svg' onclick='deleteDetection(\"".$filename_formatted."\")' class=\"copyimage\" width=25 title='Delete Detection'> 
<img style='cursor:pointer;right:85px' src='images/bird.svg' onclick='changeDetection(\"".$filename_formatted."\")' class=\"copyimage\" width=25 title='Change Detection'> 
<img style='cursor:pointer;right:45px' onclick='toggleLock(\"".$filename_formatted."\",\"".$type."\", this)' class=\"copyimage\" width=25 title=\"".$title."\" src=\"".$imageicon."\"> 
<img style='cursor:pointer' onclick='toggleShiftFreq(\"".$filename_formatted."\",\"".$shiftAction."\", this)' class=\"copyimage\" width=25 title=\"".$shiftTitle."\" src=\"".$shiftImageIcon."\"> $date $time<br>$values<br>

        ".$imageelem."
        </td>
        </tr>";

  }if($iter == 0){ echo "<tr><td><b>No recordings were found.</b><br><br><span style='font-size:medium'>They may have been deleted to make space for new recordings. You can prevent this from happening in the future by clicking the <img src='images/unlock.svg' style='width:20px'> icon in the top right of a recording.<br>You can also modify this behavior globally under \"Full Disk Behavior\" <a href='views.php?view=Advanced'>here.</a></span></td></tr>";}echo "</table>";}

  if ($iter_additional) {
    echo "<div style='text-align:center'>";
    echo "<form action='views.php' method='GET' style='display:inline'>";
    echo "<input type='hidden' name='view' value='Recordings'>";
    echo "<input type='hidden' name='species' value=\"" . htmlspecialchars($_GET['species'], ENT_QUOTES) . "\">";
    if(isset($_GET['sort'])) {
      echo "<input type='hidden' name='sort' value=\"" . htmlspecialchars($_GET['sort'], ENT_QUOTES) . "\">";
    }
    if(isset($_GET['only_excluded'])) {
      echo "<input type='hidden' name='only_excluded' value='" . $_GET['only_excluded'] . "'>";
    }
    if(isset($_SESSION['date'])) {
      echo "<input type='hidden' name='date' value='" . $_SESSION['date'] . "'>";
    }
    echo "<input type='hidden' name='limit' value='" . ($limit + 40) . "'>";
    echo "<button type='submit' class='loadmore'>Load 40 more...</button>";
    echo "</form>";
    echo "</div>";
  }

  if(isset($_GET['filename'])){
    $name = $_GET['filename'];
    $statement2 = $db->prepare("SELECT * FROM detections where File_name == :file_name ORDER BY Date DESC, Time DESC");
    ensure_db_ok($statement2);
    $statement2->bindValue(':file_name', $name, SQLITE3_TEXT);
    $result2 = $statement2->execute();
    $results = $result2->fetchArray(SQLITE3_ASSOC);
    $sciname = $results['Sci_Name'];
    $result2->reset();
    $info_url = get_info_url($sciname);
    $url = $info_url['URL'];
    echo "<table>
      <tr><th>$name<br>
      <i>$sciname</i><br>
          <a href=\"$url\" target=\"_blank\"><img title=\"$url_title\" src=\"images/info.png\" width=\"20\"></a>
          <a href=\"https://wikipedia.org/wiki/$sciname\" target=\"_blank\"><img title=\"Wikipedia\" src=\"images/wiki.png\" width=\"20\"></a>
      </th></tr>";
      while($results=$result2->fetchArray(SQLITE3_ASSOC))
      {
        $comname = preg_replace('/ /', '_', $results['Com_Name']);
        $comname = preg_replace('/\'/', '', $comname);
        $date = $results['Date'];
        $filename = "/By_Date/".$date."/".$comname."/".$results['File_Name'];
        $filename_shifted = "/By_Date/shifted/".$date."/".$comname."/".$results['File_Name'];
        $filename_png = $filename . ".png";
        $sciname = preg_replace('/ /', '_', $results['Sci_Name']);
        $sci_name = $results['Sci_Name'];
        $time = $results['Time'];
        $values = round((float)round($results['Confidence'],2) * 100 ) . '%';
        $filename_formatted = $date."/".$comname."/".$results['File_Name'];

        // add disk_check_exclude.txt lines into an array for grepping
        $fp = @fopen($home."/BirdNET-Pi/scripts/disk_check_exclude.txt", 'r');
        if ($fp) {
          $disk_check_exclude_arr = explode("\n", fread($fp, filesize($home."/BirdNET-Pi/scripts/disk_check_exclude.txt")));
        } else {
          $disk_check_exclude_arr = [];
        }

          if(!in_array($filename_formatted, $disk_check_exclude_arr)) {
            $imageicon = "images/unlock.svg";
            $title = "This file will be deleted when disk space needs to be freed (>95% usage).";
            $type = "add";
          } else {
            $imageicon = "images/lock.svg";
            $title = "This file is excluded from being purged.";
            $type = "del";
          }

      if(file_exists($shifted_path.$filename_formatted)) {
        $shiftImageIcon = "images/unshift.svg";
        $shiftTitle = "This file has been shifted down in frequency."; 
        $shiftAction = "unshift";
  $filename = $filename_shifted;
      } else {
        $shiftImageIcon = "images/shift.svg";
        $shiftTitle = "This file is not shifted in frequency.";
        $shiftAction = "shift";
      }

          echo "<tr>
      <td class=\"relative\"> 

<img style='cursor:pointer;right:120px' src='images/delete.svg' onclick='deleteDetection(\"".$filename_formatted."\", true)' class=\"copyimage\" width=25 title='Delete Detection'> 
<img style='cursor:pointer;right:85px' src='images/bird.svg' onclick='changeDetection(\"".$filename_formatted."\")' class=\"copyimage\" width=25 title='Change Detection'> 
<img style='cursor:pointer;right:45px' onclick='toggleLock(\"".$filename_formatted."\",\"".$type."\", this)' class=\"copyimage\" width=25 title=\"".$title."\" src=\"".$imageicon."\"> 
<img style='cursor:pointer' onclick='toggleShiftFreq(\"".$filename_formatted."\",\"".$shiftAction."\", this)' class=\"copyimage\" width=25 title=\"".$shiftTitle."\" src=\"".$shiftImageIcon."\">$date $time<br>$values<br>

<div class='custom-audio-player' data-audio-src='$filename' data-image-src='$filename_png'></div>
</td></tr>";

      }echo "</table>";}
      echo "</div>";
if (get_included_files()[0] === __FILE__) {
  echo '</html>';
}
