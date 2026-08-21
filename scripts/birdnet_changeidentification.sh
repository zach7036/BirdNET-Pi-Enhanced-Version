#!/usr/bin/env bash

# This scripts allows to change the identification of a Birdnet-pi detection

#################
# SET VARIABLES #
#################

# Define HOME in case environment is not correctly set
HOME="${HOME:-/home/pi}"

# shellcheck disable=sc1091
source /etc/birdnet/birdnet.conf &>/dev/null

# Get arguments
OLDNAME="$1" #OLDNAME="Mésange_charbonnière-78-2024-05-02-birdnet-RTSP_1-18:14:08.mp3"
NEWNAME="$2" #NEWNAME="Lapinus atricapilla_Lapinu à tête noire"

# Set log level
OUTPUT_TYPE="${3:-debug}" # Set 3rd argument to debug to have all outputs

# Ask for user input if no arguments
if [ -z "$OLDNAME" ]; then read -r -p 'OLDNAME (finishing by file extension): ' OLDNAME; fi
if [ -z "$NEWNAME" ]; then read -r -p 'NEWNAME (sciname_commoname): ' NEWNAME; fi

# Fixed values
LABELS_FILE="$HOME/BirdNET-Pi/model/labels.txt"
DB_FILE="$HOME/BirdNET-Pi/scripts/birds.db"
DETECTIONS_TABLE="detections"

# SQLite string literals escape an apostrophe by doubling it. Every value
# below comes from a filename or labels file, so quote them consistently
# before constructing the CLI statements.
sql_escape() {
  printf '%s' "$1" | sed "s/'/''/g"
}

###################
# VALIDITY CHECKS #
###################

# Check if files exist
if [ ! -f "$LABELS_FILE" ]; then echo "$LABELS_FILE doesn't exist, exiting" && exit 1; fi
if [ ! -f "$DB_FILE" ]; then echo "$DB_FILE doesn't exist, exiting" && exit 1; fi

# Check if inputs are valid
if [[ "$1" != *"."* ]]; then
  echo "The first argument should be a filename starting with the common name of the bird and finishing by the file extension!"
  echo "Instead, it is : $1"
  exit 1
elif [[ "$2" != *"_"* ]]; then
  echo "The second argument should be in the format : \"scientific name_common name\""
  echo "Instead, it is : $2"
  exit 1
fi

# Check if $NEWNAME is found in the file $LABELS_FILE
if ! grep -Fqx -- "$NEWNAME" "$LABELS_FILE"; then
    echo "Error: $NEWNAME not found in $LABELS_FILE"
    exit 1
fi

# Check if the common name as the same as the first
OLDNAME_space="${OLDNAME//_/ }"
if [[ "${OLDNAME_space%%-*}" == "${NEWNAME#*_}" ]]; then
    echo "Error: $OLDNAME has the same common name as $NEWNAME"
    exit 1
fi

##################
# EXECUTE SCRIPT #
##################

# Intro
[[ "$OUTPUT_TYPE" == "debug" ]] && echo "Starting to modify $OLDNAME to $NEWNAME"

# Get the line where the column "File_Name" matches exactly $OLDNAME. Capture
# sqlite3's status directly; process substitution previously hid lookup errors.
OLDNAME_sql="$(sql_escape "$OLDNAME")"
if ! old_row="$(sqlite3 -batch -bail -init /dev/null -noheader -list -cmd '.timeout 5000' -separator '|' "$DB_FILE" "SELECT Sci_Name, Com_Name, Date FROM $DETECTIONS_TABLE WHERE File_Name = '$OLDNAME_sql' LIMIT 1;")"; then
    echo "Error: database lookup failed for $OLDNAME"
    exit 1
fi
IFS='|' read -r OLDNAME_sciname OLDNAME_comname OLDNAME_date <<< "$old_row"

if [[ -z "$OLDNAME_sciname" ]]; then
    echo "Error: No line matching $OLDNAME in $DB_FILE"
    exit 1
fi

# Extract the part before the _ from $NEWNAME
NEWNAME_comname="${NEWNAME#*_}"
NEWNAME_sciname="${NEWNAME%%_*}"

# Replace spaces with underscores, and ' with "" (same logic as helpers.py)
NEWNAME_comname_safe="$(echo "$NEWNAME_comname" | tr -d "'" | tr ' ' '_')"
OLDNAME_comname_safe="$(echo "$OLDNAME_comname" | tr -d "'" | tr ' ' '_')"

# Replace OLDNAME_comname_safe with NEWNAME_comname_safe in OLDNAME
NEWNAME_filename="${OLDNAME//$OLDNAME_comname_safe/$NEWNAME_comname_safe}"

[[ "$OUTPUT_TYPE" == "debug" ]] && echo "This script will change the identification $OLDNAME from $OLDNAME_comname to ${NEWNAME#*_}"

########################
# EXECUTE : MOVE FILES #
########################

# Check if the file exists
FILE_PATH="$HOME/BirdSongs/Extracted/By_Date/$OLDNAME_date/$OLDNAME_comname_safe/$OLDNAME"
NEW_DIR="$HOME/BirdSongs/Extracted/By_Date/$OLDNAME_date/$NEWNAME_comname_safe"
NEW_FILE_PATH="$NEW_DIR/$NEWNAME_filename"
OLD_PNG_PATH="$FILE_PATH.png"
NEW_PNG_PATH="$NEW_FILE_PATH.png"

if [[ ! -f "$FILE_PATH" ]]; then
    echo "Error: File $FILE_PATH does not exist"
    exit 1
fi

same_path=0
if [[ "$FILE_PATH" == "$NEW_FILE_PATH" ]]; then
    # Some names differ only by punctuation that filenames already strip
    # (for example Coopers Hawk -> Cooper's Hawk). The database still needs
    # updating, but moving a file onto itself would falsely fail.
    same_path=1
fi

if [[ "$same_path" -eq 0 ]]; then
    if [[ -e "$NEW_FILE_PATH" ]] || [[ -e "$NEW_PNG_PATH" ]]; then
        echo "Error: destination file already exists for $NEWNAME_filename"
        exit 1
    fi
fi

if ! mkdir -p "$NEW_DIR"; then
    echo "Error: could not create $NEW_DIR"
    exit 1
fi

audio_moved=0
png_moved=0
rollback_files() {
    local rollback_failed=0
    if [[ "$png_moved" -eq 1 ]] && ! mv -- "$NEW_PNG_PATH" "$OLD_PNG_PATH"; then
        rollback_failed=1
    fi
    if [[ "$audio_moved" -eq 1 ]] && ! mv -- "$NEW_FILE_PATH" "$FILE_PATH"; then
        rollback_failed=1
    fi
    return "$rollback_failed"
}

if [[ "$same_path" -eq 0 ]]; then
    if ! mv -- "$FILE_PATH" "$NEW_FILE_PATH"; then
        echo "Error: could not move $FILE_PATH"
        exit 1
    fi
    audio_moved=1

    # A spectrogram can legitimately be absent (for example after a quarantine
    # retry), but if it exists its move is part of the rename and must succeed.
    if [[ -f "$OLD_PNG_PATH" ]]; then
        if ! mv -- "$OLD_PNG_PATH" "$NEW_PNG_PATH"; then
            echo "Error: could not move $OLD_PNG_PATH"
            rollback_files || echo "Error: rollback of the audio file also failed"
            exit 1
        fi
        png_moved=1
    fi
fi

[[ "$OUTPUT_TYPE" == "debug" ]] && echo "Files moved!"

###################################
# EXECUTE : UPDATE DATABASE FILES #
###################################

# Update the database and verify that at least one detection row changed. If
# SQLite is busy or rejects a value, put the files back so the database and
# filesystem cannot silently disagree.
NEWNAME_sciname_sql="$(sql_escape "$NEWNAME_sciname")"
NEWNAME_comname_sql="$(sql_escape "$NEWNAME_comname")"
NEWNAME_filename_sql="$(sql_escape "$NEWNAME_filename")"
if ! updated_rows="$(sqlite3 -batch -bail -init /dev/null -noheader -list -cmd '.timeout 5000' "$DB_FILE" "BEGIN IMMEDIATE; UPDATE $DETECTIONS_TABLE SET Sci_Name = '$NEWNAME_sciname_sql', Com_Name = '$NEWNAME_comname_sql', Confidence = 0, File_Name = '$NEWNAME_filename_sql' WHERE File_Name = '$OLDNAME_sql'; SELECT changes(); COMMIT;")"; then
    echo "Error: database update failed; restoring the original files"
    rollback_files || echo "Error: file rollback also failed"
    exit 1
fi
updated_rows="$(printf '%s' "$updated_rows" | tr -d '[:space:]')"
if [[ ! "$updated_rows" =~ ^[0-9]+$ ]] || [[ "$updated_rows" -lt 1 ]]; then
    echo "Error: database update changed no rows; restoring the original files"
    rollback_files || echo "Error: file rollback also failed"
    exit 1
fi

[[ "$OUTPUT_TYPE" == "debug" ]] && echo "Database entry updated"

[[ "$OUTPUT_TYPE" == "debug" ]] && echo "All done!"

# Return success only after both the files and database were updated.
exit 0
