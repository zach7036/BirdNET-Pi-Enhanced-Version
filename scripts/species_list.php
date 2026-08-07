<?php
    error_reporting(E_ALL);
    ini_set('display_errors',1);

    if ($species_list=="include") {
        $title="Included";
        $message="Warning!<br>If this list contains ANY species, the system will ONLY recognize those species. Keep this list EMPTY unless you are ONLY interested in detecting specific species.";
        $selectedfilename = './scripts/include_species_list.txt';
    } elseif ($species_list=="exclude") {
        $title="Excluded";
        $message="Once the desired species has been highlighted, click it and then click ADD to have it excluded.";
        $selectedfilename = './scripts/exclude_species_list.txt';
    } elseif ($species_list=="whitelist") {
        $title="Whitelisted";
        $message="Once the desired species has been highlighted, click it and then click ADD to have it whitelisted. This species will be detected even if below the Species Occurrence Frequency Threshold defined in the settings.<br>This is not a recommended way of working : it is preferable to first try first both Species Occurrence models (v1 and v2.4).";
        $selectedfilename = './scripts/whitelist_species_list.txt';   
    }
    

    // file() returns false on an unreadable file even when file_exists() passed
    // (e.g. root-owned after a restore); count(false) is a fatal in PHP 8 and
    // this page displays all warnings.
    if (file_exists($selectedfilename)) {
        $eachselected = @file($selectedfilename, FILE_IGNORE_NEW_LINES) ?: [];
    }
    else {
        $eachselected = [];
    }
    // labels.txt is an installer-created symlink and can dangle after a failed
    // language change; render an empty list instead of raw warnings.
    $filename = './scripts/labels.txt';
    $eachlabel = @file($filename, FILE_IGNORE_NEW_LINES) ?: [];
?>


<meta name="viewport" content="width=device-width, initial-scale=1">

<div class="left-column">
<?php echo $message ?>
</div>

<div class="customlabels column1">
<form action="" method="GET" id="add">
  <h3>All Species Labels</h3>
  <input autocomplete="off" size="28" type="text" placeholder="Search Species..." id="species_searchterm" name="species_searchterm">
  <select name="species[]" id="species" multiple size="25">
    <?php
    foreach($eachlabel as $lines){echo
    "<option value=\"".$lines."\">$lines</option>";
    } ?>
  </select>
  <input type="hidden" name="add" value="add">
</form>
<div class="customlabels smaller">
  <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="add">>>ADD>></button>
</div>
</div>

<div class="customlabels column2">
  <table><td>
  <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="add">>>ADD>></button>
  <br><br>
  <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="del">REMOVE</button>
  </td></table>
</div>

<div class="customlabels column3">
<form action="" method="GET" id="del">
  <h3><?php echo "$title" ?> Species List</h3>
  <input style="visibility:hidden" autocomplete="off" size="18" type="text" id="dummy" name="dummy">
  <select name="species[]" id="value2" multiple size="25">
  <?php
  if (count($eachselected) == 0) echo '<option disabled value="base">Please Select</option>';
  foreach($eachselected as $lines){echo
    "<option value=\"".$lines."\">$lines</option>";
  } ?>
  </select>
  <input type="hidden" name="del" value="del">
</form>
<div class="customlabels smaller">
  <button type="submit" name="view" value=<?php echo "\"$title\"" ?> form="del">REMOVE</button>
</div>
</div>

<script>
    // Store the original list of options in a variable
    var originalOptions = {};

    document.getElementById("add").addEventListener("submit", function(event) {
      var speciesSelect = document.getElementById("species");
      if (speciesSelect.selectedIndex < 0) {
        alert("Please click the species you want to add.");
        document.querySelector('.views').style.opacity = 1;
        event.preventDefault();
      }
    });

    var search_term = document.querySelector("input#species_searchterm");
    search_term.addEventListener("keyup", function() {
      filterOptions("species");
    });

    // Function to filter options in a select element
    function filterOptions(id) {
      var input = document.getElementById("species_searchterm");
      var filter = input.value.toUpperCase();
      var select = document.getElementById(id);
      var options = select.getElementsByTagName("option");

      // If the original list of options for this select element hasn't been stored yet, store it
      if (!originalOptions[id]) {
        originalOptions[id] = Array.from(options).map(option => option.value);
      }

      // Clear the select element
      while (select.firstChild) {
        select.removeChild(select.firstChild);
      }

      // Populate the select element with the filtered labels
      originalOptions[id].forEach(label => {
        if (label.toUpperCase().indexOf(filter) > -1) {
          let option = document.createElement('option');
          option.value = label;
          option.text = label;
          select.appendChild(option);
        }
      });
    }
</script>
