#!/usr/bin/env bash
set -x
# Update BirdNET-Pi
trap 'exit 1' SIGINT SIGHUP
source /etc/birdnet/birdnet.conf
if [ -n "${BIRDNET_USER}" ]; then
  echo "BIRDNET_USER: ${BIRDNET_USER}"
  USER=${BIRDNET_USER}
  # The account's real home dir - install_services.sh writes cron entries with
  # it, so assuming /home/ here would break them on a non-standard home.
  HOME=$(getent passwd "${BIRDNET_USER}" | cut -d: -f6)
  HOME=${HOME:-/home/${BIRDNET_USER}}
else
  echo "WARNING: no BIRDNET_USER found"
  USER=$(awk -F: '/1000/ {print $1}' /etc/passwd)
  HOME=$(awk -F: '/1000/ {print $6}' /etc/passwd)
fi
my_dir=$HOME/BirdNET-Pi/scripts
source "$my_dir/install_helpers.sh"

# Sets proper permissions and ownership
find $HOME/Bird* -type f ! -perm -g+wr -exec chmod g+wr {} + 2>/dev/null
find $HOME/Bird* -not -user $USER -execdir sudo -E chown $USER:$USER {} \+
chmod 666 ~/BirdNET-Pi/scripts/*.txt
chmod 666 ~/BirdNET-Pi/*.txt
find $HOME/BirdNET-Pi -path "$HOME/BirdNET-Pi/birdnet" -prune -o -type f ! -perm /o=w -exec chmod a+w {} \;
chmod g+r $HOME

# remove world-writable perms
chmod -R o-w ~/BirdNET-Pi/templates/*

APT_UPDATED=0
PIP_UPDATED=0

# helpers
sudo_with_user () {
  sudo -u $USER "$@"
}

ensure_apt_updated () {
  [[ $APT_UPDATED != "UPDATED" ]] && apt-get update && APT_UPDATED="UPDATED"
}

# Purge-protection list and its lock, owned by the station user and writable
# by the web user - see install_services.sh. The ownership pass above already
# reclaims a list that a web request created as caddy; this covers stations
# that have never had one.
[ -f "$my_dir/disk_check_exclude.txt" ] || sudo_with_user bash -c "printf '##start\n##end\n' > '$my_dir/disk_check_exclude.txt'"
sudo_with_user touch "$my_dir/disk_check_exclude.lock"
chmod 666 "$my_dir/disk_check_exclude.txt" "$my_dir/disk_check_exclude.lock"

ensure_pip_updated () {
  [[ $PIP_UPDATED != "UPDATED" ]] && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install -U pip && PIP_UPDATED="UPDATED"
}

remove_unit_file() {
  # remove_unit_file pushed_notifications.service $HOME/BirdNET-Pi/templates/pushed_notifications.service
  if systemctl list-unit-files "${1}" &>/dev/null;then
    systemctl disable --now "${1}"
    rm -f "/usr/lib/systemd/system/${1}"
    rm "$HOME/BirdNET-Pi/templates/${1}"
    if [ $# == 2 ]; then
      rm -f "${2}"
    fi
  fi
}

ensure_python_package() {
  # ensure_python_package pytest pytest==7.1.2
  pytest_installation_status=$(~/BirdNET-Pi/birdnet/bin/python3 -c 'import pkgutil; import sys; print("installed" if pkgutil.find_loader(sys.argv[1]) else "not installed")' "$1")
  if [[ "$pytest_installation_status" = "not installed" ]];then
    ensure_pip_updated
    sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install "$2"
  fi
}

# sed -i on /etc/birdnet/birdnet.conf overwites the symbolic link - restore the link
if ! [ -L /etc/birdnet/birdnet.conf ] ; then
  sudo_with_user cp -f /etc/birdnet/birdnet.conf $HOME/BirdNET-Pi/
  ln -fs  $HOME/BirdNET-Pi/birdnet.conf /etc/birdnet/birdnet.conf
fi

# update snippets below
SRC="APPRISE_NOTIFICATION_BODY='(.*)'$"
DST='APPRISE_NOTIFICATION_BODY="\1"'
sed -i --follow-symlinks -E "s/$SRC/$DST/" /etc/birdnet/birdnet.conf

if ! grep -E '^DATA_MODEL_VERSION=' /etc/birdnet/birdnet.conf &>/dev/null;then
    echo "DATA_MODEL_VERSION=1" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^BIRDNET_USER=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "## BIRDNET_USER is for scripts to easily find where BirdNET-Pi is installed" >> /etc/birdnet/birdnet.conf
  echo "## DO NOT EDIT!" >> /etc/birdnet/birdnet.conf
  echo "BIRDNET_USER=$(awk -F: '/1000/ {print $1}' /etc/passwd)" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^RTSP_STREAM_TO_LIVESTREAM=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "RTSP_STREAM_TO_LIVESTREAM=\"0\"" >> /etc/birdnet/birdnet.conf
fi

SRC='^APPRISE_NOTIFICATION_BODY="A \$comname \(\$sciname\)  was just detected with a confidence of \$confidence"$'
DST='APPRISE_NOTIFICATION_BODY="A \$comname (\$sciname)  was just detected with a confidence of \$confidence (\$reason)"'
sed -i --follow-symlinks -E "s/$SRC/$DST/" /etc/birdnet/birdnet.conf

if ! [ -f $HOME/BirdNET-Pi/body.txt ];then
  grep -E '^APPRISE_NOTIFICATION_BODY=".*"' /etc/birdnet/birdnet.conf | cut -d '"' -f 2 | sudo_with_user tee "$HOME/BirdNET-Pi/body.txt"
  chmod g+w "$HOME/BirdNET-Pi/body.txt"
  sed -i --follow-symlinks -E 's/^APPRISE_NOTIFICATION_BODY=/#APPRISE_NOTIFICATION_BODY=/' /etc/birdnet/birdnet.conf
fi

if ! grep -E '^INFO_SITE=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "INFO_SITE=\"ALLABOUTBIRDS\"" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^COLOR_SCHEME=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "COLOR_SCHEME=\"light\"" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^PURGE_THRESHOLD=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "PURGE_THRESHOLD=95" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^MAX_FILES_SPECIES=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "MAX_FILES_SPECIES=\"0\"" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^AUTOMATIC_UPDATE=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "AUTOMATIC_UPDATE=0" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^RARE_SPECIES_THRESHOLD=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo '## RARE_SPECIES_THRESHOLD defines after how many days a species is considered as rare and highlighted on overview page' >> /etc/birdnet/birdnet.conf
  echo "RARE_SPECIES_THRESHOLD=\"30\"" >> /etc/birdnet/birdnet.conf
fi

if ! grep -E '^IMAGE_PROVIDER=' /etc/birdnet/birdnet.conf &>/dev/null;then
  if grep -E '^FLICKR_API_KEY=\S+' /etc/birdnet/birdnet.conf &>/dev/null;then
    PROVIDER=FLICKR
  else
    PROVIDER=""
  fi
  echo '## WIKIPEDIA or FLICKR (Flickr requires API key)' >> /etc/birdnet/birdnet.conf
  echo "IMAGE_PROVIDER=${PROVIDER}" >> /etc/birdnet/birdnet.conf
fi

if grep -E '^DATABASE_LANG=zh$' /etc/birdnet/birdnet.conf &>/dev/null;then
  sed -i --follow-symlinks -E 's/^DATABASE_LANG=zh/DATABASE_LANG=zh_CN/' /etc/birdnet/birdnet.conf
  install_language_label.sh
fi

if [ -f "$HOME/BirdNET-Pi/scripts/birds.db" ]; then
  sudo_with_user sqlite3 "$HOME/BirdNET-Pi/scripts/birds.db" <<'EOF'
CREATE INDEX IF NOT EXISTS "detections_Sci_Name_Date" ON "detections" ("Sci_Name", "Date");
CREATE INDEX IF NOT EXISTS "detections_Date_Sci_Name" ON "detections" ("Date", "Sci_Name");
CREATE INDEX IF NOT EXISTS "detections_Sci_Name_Confidence" ON "detections" ("Sci_Name", "Confidence");
CREATE INDEX IF NOT EXISTS "detections_File_Name" ON "detections" ("File_Name");
EOF
fi

weather_cron_cmd="0 * * * * $USER $HOME/BirdNET-Pi/birdnet/bin/python3 $HOME/BirdNET-Pi/scripts/utils/weather.py >/dev/null 2>&1"
if ! grep -F "$weather_cron_cmd" /etc/crontab &>/dev/null; then
  sed -i '/BirdNET-Pi\/scripts\/utils\/weather.py/d' /etc/crontab
  echo "#birdnet weather sync" >> /etc/crontab
  echo "$weather_cron_cmd" >> /etc/crontab
fi
sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/python3 $HOME/BirdNET-Pi/scripts/utils/weather.py || true

[ -d $RECS_DIR/StreamData ] || sudo_with_user mkdir -p $RECS_DIR/StreamData
[ -L ${EXTRACTED}/spectrogram.png ] || sudo_with_user ln -sf ${RECS_DIR}/StreamData/spectrogram.png ${EXTRACTED}/spectrogram.png

if ! which inotifywait &>/dev/null;then
  ensure_apt_updated
  apt-get -y install inotify-tools
fi

apprise_version=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import apprise; print(apprise.__version__)")
[[ $apprise_version != "1.9.5" ]] && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install apprise==1.9.5
version=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import streamlit; print(streamlit.__version__)")
[[ $version != "1.44.0" ]] && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install streamlit==1.44.0
version=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import seaborn; print(seaborn.__version__)")
[[ $version != "0.13.2" ]] && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install seaborn==0.13.2
version=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import suntime; print(suntime.__version__)")
[[ $version != "1.3.2" ]] && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install suntime==1.3.2
version=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import pyarrow; print(pyarrow.__version__)")
[[ $version != "20.0.0" ]] && sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install pyarrow==20.0.0

PY_VERSION=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import sys; print(f'{sys.version_info[0]}{sys.version_info[1]}')")
tf_version=$($HOME/BirdNET-Pi/birdnet/bin/python3 -c "import tflite_runtime; print(tflite_runtime.__version__)")
if [ "$PY_VERSION" == 39 ] && [ "$tf_version" != "2.11.0" ] || [ "$PY_VERSION" != 39 ] && [ "$tf_version" != "2.17.1" ]; then
  get_tf_whl
  # include our numpy dependants so pip can figure out which numpy version to install
  sudo_with_user $HOME/BirdNET-Pi/birdnet/bin/pip3 install $HOME/BirdNET-Pi/$WHL pandas librosa matplotlib
fi

ensure_python_package inotify inotify
ensure_python_package soundfile soundfile

if ! which inotifywait &>/dev/null;then
  ensure_apt_updated
  apt-get -y install inotify-tools
fi

install_tmp_mount
remove_unit_file birdnet_server.service /usr/local/bin/server.py
remove_unit_file extraction.service /usr/local/bin/extract_new_birdsounds.sh

if ! grep 'daemon' $HOME/BirdNET-Pi/templates/chart_viewer.service &>/dev/null;then
  sed -i "s|daily_plot.py.*|daily_plot.py --daemon --sleep 2|" ~/BirdNET-Pi/templates/chart_viewer.service
  systemctl daemon-reload && restart_services.sh
fi

if grep -q 'birdnet_server.service' "$HOME/BirdNET-Pi/templates/birdnet_analysis.service" &>/dev/null; then
    sed -i '/After=.*/d' "$HOME/BirdNET-Pi/templates/birdnet_analysis.service"
    sed -i '/Requires=.*/d' "$HOME/BirdNET-Pi/templates/birdnet_analysis.service"
    sed -i '/RuntimeMaxSec=.*/d' "$HOME/BirdNET-Pi/templates/birdnet_analysis.service"
    sed -i "s|ExecStart=.*|ExecStart=$HOME/BirdNET-Pi/birdnet/bin/python3 /usr/local/bin/birdnet_analysis.py|" "$HOME/BirdNET-Pi/templates/birdnet_analysis.service"
    systemctl daemon-reload && restart_services.sh
fi

TMP_MOUNT=$(systemd-escape -p --suffix=mount "$RECS_DIR/StreamData")
if ! [ -f "$HOME/BirdNET-Pi/templates/$TMP_MOUNT" ]; then
   install_birdnet_mount
   chown $USER:$USER "$HOME/BirdNET-Pi/templates/$TMP_MOUNT"
fi

if grep -q -e '-P log' $HOME/BirdNET-Pi/templates/birdnet_log.service ; then
  sed -i "s/-P log/--path log/" ~/BirdNET-Pi/templates/birdnet_log.service
  systemctl daemon-reload && restart_services.sh
fi

if grep -q -e '-P terminal' $HOME/BirdNET-Pi/templates/web_terminal.service ; then
  sed -i "s/-P terminal/--path terminal/" ~/BirdNET-Pi/templates/web_terminal.service
  systemctl daemon-reload && systemctl restart web_terminal.service
fi

if grep -q -e ' login' $HOME/BirdNET-Pi/templates/web_terminal.service ; then
  sed -i "s/ login/ bash -c 'read -p \"Login: \" username \&\& [[ \"\$username\" =~ ^[-_.a-z0-9]{1,30}\$ ]] \&\& su --pty -l \$username'/" ~/BirdNET-Pi/templates/web_terminal.service
  sed -i "/\[Service\]/a User=$BIRDNET_USER" ~/BirdNET-Pi/templates/web_terminal.service
  systemctl daemon-reload && systemctl restart web_terminal.service
fi

if grep -q -e 'Environment=XDG_RUNTIME_DIR=/run/user/' $HOME/BirdNET-Pi/templates/birdnet_recording.service; then
  sed -i '/^Environment=XDG_RUNTIME_DIR=\/run\/user\/[0-9]\+/d' $HOME/BirdNET-Pi/templates/birdnet_recording.service
  systemctl daemon-reload && restart_services.sh
fi

if grep -q -e 'Environment=XDG_RUNTIME_DIR=/run/user/' $HOME/BirdNET-Pi/templates/custom_recording.service; then
  sed -i '/^Environment=XDG_RUNTIME_DIR=\/run\/user\/[0-9]\+/d' $HOME/BirdNET-Pi/templates/custom_recording.service
  systemctl daemon-reload && restart_services.sh
fi

if grep -q -e 'Environment=XDG_RUNTIME_DIR=/run/user/' $HOME/BirdNET-Pi/templates/livestream.service; then
  sed -i '/^Environment=XDG_RUNTIME_DIR=\/run\/user\/[0-9]\+/d' $HOME/BirdNET-Pi/templates/livestream.service
  systemctl daemon-reload && restart_services.sh
fi

if grep -q 'php7.4-' /etc/caddy/Caddyfile &>/dev/null; then
  sed -i 's/php7.4-/php-/' /etc/caddy/Caddyfile
fi

if ! [ -L /etc/avahi/services/http.service ];then
  # symbolic link does not work here, so just copy
  cp -f $HOME/BirdNET-Pi/templates/http.service /etc/avahi/services/
  systemctl restart avahi-daemon.service
fi

if [ -L /usr/local/bin/analyze.py ];then
  rm -f /usr/local/bin/analyze.py
fi

if [ -L /usr/local/bin/birdnet_analysis.sh ];then
  rm -f /usr/local/bin/birdnet_analysis.sh
fi

# Clean state and update cron if all scripts are not installed
if [ "$(grep -o "#birdnet" /etc/crontab | wc -l)" -lt 6 ]; then
  sudo sed -i '/birdnet/,+1d' /etc/crontab
  sed "s/\$USER/$USER/g" "$HOME"/BirdNET-Pi/templates/cleanup.cron >> /etc/crontab
  sed "s/\$USER/$USER/g" "$HOME"/BirdNET-Pi/templates/weekly_report.cron >> /etc/crontab
  sed "s/\$USER/$USER/g" "$HOME"/BirdNET-Pi/templates/automatic_update.cron >> /etc/crontab
fi

set +x
AUTH=$(grep basicauth /etc/caddy/Caddyfile)
[ -n "${CADDY_PWD}" ] && [ -z "${AUTH}" ] && sudo /usr/local/bin/update_caddyfile.sh > /dev/null 2>&1
set -x

if [ -L $HOME/BirdNET-Pi/model/labels_flickr.txt ]; then
  rm $HOME/BirdNET-Pi/model/labels_flickr.txt
fi
if [ -L $HOME/BirdNET-Pi/model/labels.txt ]; then
  sudo_with_user install_language_label.sh
fi

sqlite3 $HOME/BirdNET-Pi/scripts/birds.db << EOF
CREATE INDEX IF NOT EXISTS "detections_Sci_Name" ON "detections" ("Sci_Name");
EOF

# Re-link homepage files into the web root so files added after the original
# install (e.g. styleguide.php) become reachable on updated systems.
# -n replaces existing directory symlinks instead of descending into them.
for homepage_entry in $HOME/BirdNET-Pi/homepage/*; do
  sudo_with_user ln -fsn "$homepage_entry" "${EXTRACTED}/$(basename "$homepage_entry")"
done

# Harden the livestream unit on existing installs: never stop retrying after
# ALSA underrun crashes, and escalate hung stops to SIGKILL after 10s instead
# of 90s. Keep in sync with install_livestream_service in install_services.sh.
if ! grep -q "TimeoutStopSec=10" "$HOME/BirdNET-Pi/templates/livestream.service" 2>/dev/null; then
  cat << EOF > $HOME/BirdNET-Pi/templates/livestream.service
[Unit]
Description=BirdNET-Pi Live Stream
After=network-online.target
Requires=network-online.target
StartLimitIntervalSec=0
[Service]
Restart=always
Type=simple
RestartSec=3
TimeoutStopSec=10
User=${USER}
ExecStart=/usr/local/bin/livestream.sh
[Install]
WantedBy=multi-user.target
EOF
  chown $USER:$USER $HOME/BirdNET-Pi/templates/livestream.service
fi

# Notification v2 config keys (Phase 4): seeded so the Settings UI's
# preg_replace-based writer can update them.
if ! grep -E '^APPRISE_NOTIFY_RARE=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "APPRISE_NOTIFY_RARE=0" >> /etc/birdnet/birdnet.conf
fi
if ! grep -E '^APPRISE_VISIT_GROUPING=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "APPRISE_VISIT_GROUPING=1" >> /etc/birdnet/birdnet.conf
fi
if ! grep -E '^APPRISE_QUIET_HOURS_START=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo 'APPRISE_QUIET_HOURS_START=""' >> /etc/birdnet/birdnet.conf
fi
if ! grep -E '^APPRISE_QUIET_HOURS_END=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo 'APPRISE_QUIET_HOURS_END=""' >> /etc/birdnet/birdnet.conf
fi
if ! grep -E '^VISIT_GAP_MINUTES=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "VISIT_GAP_MINUTES=5" >> /etc/birdnet/birdnet.conf
fi

# Display & units keys: same seeding requirement as above.
if ! grep -E '^TEMPERATURE_UNIT=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "TEMPERATURE_UNIT=fahrenheit" >> /etc/birdnet/birdnet.conf
fi
if ! grep -E '^WIND_SPEED_UNIT=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "WIND_SPEED_UNIT=mph" >> /etc/birdnet/birdnet.conf
fi
if ! grep -E '^TIME_FORMAT=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "TIME_FORMAT=12" >> /etc/birdnet/birdnet.conf
fi
if ! grep -E '^SIDEBAR_SITE_NAME=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "SIDEBAR_SITE_NAME=0" >> /etc/birdnet/birdnet.conf
fi
if ! grep -E '^NUMBER_FORMAT=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo "NUMBER_FORMAT=point" >> /etc/birdnet/birdnet.conf
fi
if ! grep -E '^HA_URL=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo 'HA_URL=""' >> /etc/birdnet/birdnet.conf
fi
if ! grep -E '^HA_TOKEN=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo 'HA_TOKEN=""' >> /etc/birdnet/birdnet.conf
fi
if ! grep -E '^HA_TEMP_ENTITY=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo 'HA_TEMP_ENTITY=""' >> /etc/birdnet/birdnet.conf
fi
if ! grep -E '^PROTECTED_RECORDINGS_PER_SPECIES=' /etc/birdnet/birdnet.conf &>/dev/null;then
  echo 'PROTECTED_RECORDINGS_PER_SPECIES=2' >> /etc/birdnet/birdnet.conf
fi
# Protect each species' current best recordings right away rather than at the
# next cleanup run - the old page-render mechanism left stale lists behind.
# A failure here is not fatal to the update: the cleanup scripts refresh (and
# fail closed) before they delete anything.
sudo_with_user php "$HOME/BirdNET-Pi/scripts/update_purge_protection.php" || echo "WARNING: purge protection refresh failed; cleanup will retry before deleting"

# Data spine tables (Phase 1): reviews, species prefs, notes. Additive only -
# the detections table is never altered. Keep in sync with createdb.sh and
# spine_schema_statements() in scripts/common.php.
sqlite3 $HOME/BirdNET-Pi/scripts/birds.db << EOF
CREATE TABLE IF NOT EXISTS detection_reviews (
  id INTEGER PRIMARY KEY,
  file_name VARCHAR(100) NOT NULL UNIQUE,
  sci_name VARCHAR(100) NOT NULL,
  com_name VARCHAR(100) NOT NULL,
  date DATE NOT NULL,
  time TIME NOT NULL,
  status TEXT NOT NULL CHECK (status IN ('confirmed','false_positive','hidden','unsure')),
  reviewed_via TEXT,
  note TEXT,
  created_at TEXT DEFAULT (datetime('now','localtime')));
CREATE INDEX IF NOT EXISTS idx_reviews_sci_status ON detection_reviews(sci_name, status);
CREATE TABLE IF NOT EXISTS species_prefs (
  sci_name VARCHAR(100) PRIMARY KEY,
  com_name VARCHAR(100),
  favorite INTEGER NOT NULL DEFAULT 0,
  muted INTEGER NOT NULL DEFAULT 0,
  notify_mode TEXT NOT NULL DEFAULT 'default',
  custom_threshold FLOAT,
  crowned_clip VARCHAR(100),
  updated_at TEXT DEFAULT (datetime('now','localtime')));
CREATE TABLE IF NOT EXISTS notes (
  id INTEGER PRIMARY KEY,
  date DATE,
  sci_name VARCHAR(100),
  file_name VARCHAR(100),
  body TEXT NOT NULL,
  created_at TEXT DEFAULT (datetime('now','localtime')));
CREATE INDEX IF NOT EXISTS idx_notes_date ON notes(date);
CREATE INDEX IF NOT EXISTS idx_notes_sci ON notes(sci_name);
EOF

# update snippets above

systemctl daemon-reload
restart_services.sh
