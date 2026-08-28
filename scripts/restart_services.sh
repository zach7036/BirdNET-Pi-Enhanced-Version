#!/usr/bin/env bash
# Restarts core services and any optional services the user left enabled.
source /etc/birdnet/birdnet.conf
set -x
my_dir=$HOME/BirdNET-Pi/scripts


sudo systemctl stop birdnet_recording.service

services=(chart_viewer.service
  spectrogram_viewer.service
  icecast2.service
  birdnet_recording.service
  birdnet_analysis.service
  birdnet_log.service
  birdnet_stats.service)

for i in "${services[@]}"; do
  case "${i}" in
    birdnet_recording.service|birdnet_analysis.service)
      sudo systemctl restart "${i}"
      ;;
    *)
      # Optional services that a user disabled must stay disabled. Enabled
      # services are still restarted after backup/restore temporarily stops them.
      if sudo systemctl is-enabled --quiet "${i}"; then
        sudo systemctl restart "${i}"
      fi
      ;;
  esac
done

# Restarting icecast2 above severs ffmpeg's source connection, and ffmpeg can
# keep writing into the dead socket without exiting - leaving /stream silently
# empty. Bounce the livestream AFTER icecast so it reconnects cleanly.
# try-restart only acts if the service is running, respecting users who have
# deliberately disabled the livestream.
sudo systemctl try-restart livestream.service

for i in {1..5}; do
  # We want to loop here (5*5seconds) until the birdnet_analysis.service is running
  systemctl is-active --quiet birdnet_analysis.service \
	  && logger "[$0] birdnet_analysis.service is running" \
	  && break

  sleep 5
done
