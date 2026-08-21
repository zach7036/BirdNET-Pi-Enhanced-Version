#!/usr/bin/env bash
set -x

source /etc/birdnet/birdnet.conf
used="$(df -h ${EXTRACTED} | tail -n1 | awk '{print $5}')"
purge_threshold="${PURGE_THRESHOLD:-95}"

if [ "${used//%}" -ge "$purge_threshold" ]; then

  case $FULL_DISK in
    purge) echo "Removing oldest data"
        cd ${EXTRACTED}/By_Date/
        # Refresh purge protection (each species' best recordings + pinned clips)
        # straight from the database before deleting anything, so a new best is
        # already protected and the one it displaced is purgeable. A list
        # refreshed in the last 30 minutes is reused: one purge pass rarely
        # clears the threshold, and this branch re-enters every 5 minutes.
        exclude_file="$HOME/BirdNET-Pi/scripts/disk_check_exclude.txt"
        if [ -z "$(find "$exclude_file" -mmin -30 2>/dev/null)" ]; then
            if ! php "$HOME/BirdNET-Pi/scripts/update_purge_protection.php" >/dev/null; then
                # Fail closed: deleting against a stale or empty list is how
                # best recordings got lost before.
                echo "disk_check: purge protection refresh failed, skipping purge" >&2
                exit 1
            fi
        fi
        if ! grep -qxFe \#\#start "$exclude_file"; then
            exit
        fi
        filestodelete=$(($(find ${EXTRACTED}/By_Date/* -type f | wc -l) / $(find ${EXTRACTED}/By_Date/* -maxdepth 0 -type d | wc -l)))
        iter=0
        for i in */*/*; do
            if [ $iter -ge $filestodelete ]; then
                break
            fi
            if ! grep -qxFe "$i" $HOME/BirdNET-Pi/scripts/disk_check_exclude.txt; then
                rm "$i"
            fi
            ((iter++))
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
if [ "${used//%}" -ge "$purge_threshold" ]; then
  case $FULL_DISK in
    purge) echo "Removing more data"
       rm -rfv ${PROCESSED}/*;;
    keep) echo "Stopping Core Services"
       /usr/local/bin/stop_core_services.sh;;
  esac
fi
