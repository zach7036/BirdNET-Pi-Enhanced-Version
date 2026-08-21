#!/usr/bin/env bash
set -x

source /etc/birdnet/birdnet.conf
used="$(df -h ${EXTRACTED} | tail -n1 | awk '{print $5}')"
purge_threshold="${PURGE_THRESHOLD:-95}"

if [ "${used//%}" -ge "$purge_threshold" ]; then

  case $FULL_DISK in
    purge) echo "Removing oldest data"
        cd ${EXTRACTED}/By_Date/ || { echo "disk_check: cannot enter ${EXTRACTED}/By_Date" >&2; exit 1; }
        exclude_file="$HOME/BirdNET-Pi/scripts/disk_check_exclude.txt"
        # One lock shared with disk_species_clean.sh, the protection generator
        # and the web Lock/Pin writers: nothing rewrites the list while this
        # deletion pass is reading it. Held until the script exits.
        exec 9>"$HOME/BirdNET-Pi/scripts/disk_check_exclude.lock"
        if ! flock -w 120 9; then
            echo "disk_check: cleanup lock busy, skipping purge" >&2
            exit 1
        fi
        # Refresh purge protection (each species' best recordings + pinned clips)
        # straight from the database before deleting anything - every time, so
        # a pin or lock made since the last pass always counts.
        if ! PURGE_LOCK_HELD=1 php "$HOME/BirdNET-Pi/scripts/update_purge_protection.php" >/dev/null; then
            # Fail closed: deleting against a stale or empty list is how
            # best recordings got lost before.
            echo "disk_check: purge protection refresh failed, skipping purge" >&2
            exit 1
        fi
        if ! grep -qxFe \#\#start "$exclude_file"; then
            exit
        fi
        filestodelete=$(($(find ${EXTRACTED}/By_Date/* -type f | wc -l) / $(find ${EXTRACTED}/By_Date/* -maxdepth 0 -type d | wc -l)))
        deleted=0
        for i in */*/*; do
            if [ $deleted -ge $filestodelete ]; then
                break
            fi
            if ! grep -qxFe "$i" "$exclude_file"; then
                # Count what was actually removed: a run of protected files at
                # the head of the purge order must not use up the quota and
                # stall the purge.
                rm "$i" && deleted=$((deleted + 1))
            fi
        done
        find ~/BirdSongs/ -type d -empty -mtime +90 -delete
        find ${EXTRACTED}/By_Date/ -empty -type d -delete;;

       #rm -drfv "$(find ${EXTRACTED}/By_Date/* -maxdepth 1 -type d -prune \
        # | sort -r | tail -n1)";;
    keep) echo "Stopping Core Services"
       /usr/local/bin/stop_core_services.sh;;
  esac
fi
sleep 1
# Re-measure: the purge above may already have cleared the threshold, in
# which case the processed recordings need not be thrown away as well.
used="$(df -h ${EXTRACTED} | tail -n1 | awk '{print $5}')"
if [ "${used//%}" -ge "$purge_threshold" ]; then
  case $FULL_DISK in
    purge) echo "Removing more data"
       rm -rfv ${PROCESSED}/*;;
    keep) echo "Stopping Core Services"
       /usr/local/bin/stop_core_services.sh;;
  esac
fi
