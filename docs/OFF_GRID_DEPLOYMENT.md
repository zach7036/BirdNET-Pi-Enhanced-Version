# Off-grid and remote deployment

BirdNET-Pi's recording, identification, database, and local dashboard do not require an internet
connection after installation. Remote operation is still a system-design problem: Wi-Fi
credentials change, clocks drift, storage fills, power fails, and a process can be alive without
producing new audio. Build and test the exact finished station before taking it into the field.

This guide deliberately keeps risky behavior out of the BirdNET-Pi installer. It does not enable a
hotspot, hardware watchdog, scheduled reboot, or new model, and installing an update does not
change any of the existing defaults described below.

## Before leaving

1. Use the supported 64-bit Raspberry Pi OS release and finish all installation and updates while
   you still have dependable internet access.
2. Set the station's coordinates and timezone. Set a non-blank BirdNET-Pi admin password and a
   strong Raspberry Pi/SSH password.
3. Confirm that new audio is recorded, analyzed, inserted into the database, extracted into a
   playable clip, and given a spectrogram.
4. Exercise expected internet and Wi-Fi failures while you still have Ethernet or a local console
   available. Test abrupt power loss and the disk-cleanup threshold only on a cloned or disposable
   card; the default `purge` policy is supposed to delete old recordings near that threshold.
5. Keep a full, tested SD-card image and preferably a second imaged card. Also copy the BirdNET-Pi
   backup somewhere other than the station.

## Wi-Fi recovery

BirdNET-Pi does not include its own Wi-Fi manager or captive portal. The least invasive approach is
to let Raspberry Pi OS NetworkManager handle Wi-Fi and save every known network before deployment.
For example, temporarily enable the phone hotspot you will carry, connect the Pi to it once, and
verify that the saved connection reconnects after the primary access point disappears. Ethernet or
a local keyboard and display remain the most dependable rescue routes.

[Comitup](https://davesteele.github.io/comitup/) is an optional third-party project that can expose a
temporary setup hotspot when no saved network works. It is not installed, configured, or supported
by BirdNET-Pi. If you evaluate it, test it repeatedly on the exact finished OS image before travel.
Do not flash Comitup's standalone SD image over the only BirdNET-Pi card; that would replace the
installation and its data. Evaluate the package on a cloned or spare card, because it takes over
Wi-Fi management.

Because Caddy normally owns port 80, review Comitup's `web_service` integration and configure a
strong `ap_password`. The relevant `/etc/comitup.conf` entries are:

```yaml
ap_password: replace-with-a-long-unique-password
web_service: caddy.service
```

This makes Comitup stop Caddy while its recovery portal owns port 80, then start Caddy again after
the Pi connects. The BirdNET-Pi web interface is unavailable in recovery-hotspot mode. Keep a wired
or local-console fallback in case the hotspot transition fails.

Do not expose the dashboard directly to the internet or forward router port 80 to the Pi.

## Network traffic

Detection stays local. The following features and status checks can contact another system:

- BirdWeather, Apprise notifications, and the heartbeat URL only run after their corresponding
  values are configured.
- Automatic updates are off by default. Opening an authenticated dashboard or System Controls can
  still perform an update-status `git fetch`; that check is time-bounded so an offline network does
  not leave it running indefinitely. Leave automatic updates off for an unattended field station
  and update while someone has local access and a rollback card.
- Wikipedia is the default species-image provider. Select **None** under **Settings → Settings →
  Species Images** for a station that should not request images.
- Weather is fetched from Open-Meteo by an hourly job, and the dashboard may request a refresh when
  stored weather is stale. There is currently no weather-off switch in Settings.
- A configured Home Assistant temperature sensor does not make weather fully local; online weather
  remains the fallback and supplies the other conditions.
- Opening **System Info** performs a public-IP lookup, and a few optional admin tools load interface
  assets from public CDNs. These do not run the detection pipeline, but they matter to a strict
  outbound-network policy.

Default weather, image, and update-status failures do not stop local analysis. Configured reporting
integrations are different: slow BirdWeather or notification requests can delay the reporting queue,
so leave BirdWeather, Apprise, and heartbeat values blank unless they have been tested under the
field network conditions. A strict no-outbound-network installation should also enforce that policy
at its router or firewall.

## Power and optional services

Continuous identification has to keep the microphone and inference process awake. Raising the
confidence threshold can reduce stored clips and notifications, but it does not stop the model from
analyzing the audio, so it is not a major CPU-power control.

Raspberry Pi OS Lite and avoiding unnecessary live views are the safest savings. Under **Settings →
Services**, you may disable features you do not use, such as Live Audio Stream, Web Terminal,
BirdNET Log, Streamlit Statistics, Chart Viewer, and Spectrogram Viewer. Disabling a viewer makes
that viewer unavailable; it does not disable recording or analysis. BirdNET-Pi will preserve an
explicitly disabled optional service during later settings saves and restarts.

Measure the complete station—Pi, USB microphone, storage, network equipment, and conversion
losses—over several real days. Size the battery and solar supply for the worst expected weather,
not the panel's advertised peak output. Provide regulated power and a safe shutdown or sufficient
reserve rather than relying on scheduled reboots.

## Recovery and watchdogs

The recording and analysis systemd services already restart when their processes crash. That does
not prove the microphone is still producing new files: a process can remain running while audio is
stalled. BirdNET-Pi does not currently restart the recorder based on recording freshness, and this
guide does not add that behavior without a reproducible failure and Pi-level testing.

Raspberry Pi OS supports a hardware watchdog, but it is an operating-system setting rather than a
BirdNET-Pi feature. A bad watchdog configuration can cause a reboot loop. If the deployment needs
one, follow the current [Raspberry Pi watchdog documentation](https://www.raspberrypi.com/documentation/computers/config_txt.html#kernel_watchdog_timeout),
test power-loss and recovery cycles on a clone of the finished station, and retain a way to edit the
card from another computer. An external heartbeat service also requires internet, so it cannot
supervise a fully offline site.

## Clock, storage, and environment

- A Raspberry Pi 5 has a real-time clock, but it needs a compatible rechargeable backup battery to
  retain time through a total power loss. Charging is disabled by default, so follow Raspberry Pi's
  [RTC and battery setup](https://www.raspberrypi.com/documentation/computers/raspberry-pi.html#real-time-clock-rtc);
  do not substitute a primary lithium or lithium-ion cell. Older models generally need an external
  RTC if correct time must survive a long power outage without NTP. Verify the timestamp after a
  fully offline cold start.
- Use high-endurance storage and configure Disk Management for the failure mode you prefer: purge
  old material at the threshold or stop services instead. Confirm cleanup behavior on test data.
- Protect the Pi, connectors, and microphone from rain, condensation, insects, heat, and cable
  strain without sealing the microphone acoustically or trapping excessive heat.
- A full card image is the recovery plan for OS files, packages, systemd units, permissions, and
  boot configuration. The application backup is useful for data, but is not a complete machine
  image.

## Privacy in a remote or shared location

The human-voice filter is available under **Advanced Settings**. The default threshold `0` still
checks the model's top 10 predictions for a Human label; raising the percentage checks more of the
ranked predictions. If people may be recorded, test an appropriate setting while remembering that
an automated classifier cannot guarantee that all speech is removed.

BirdWeather is opt-in: a blank station ID means no BirdWeather upload. When enabled, the current
integration uploads the full analyzed audio segment as FLAC and then sends detection timestamps,
species, confidence, and coordinates. That audio can contain sounds—including speech—that are not
present in the locally extracted bird clip. Obtain any consent required at the site, review local
recording law, and consider whether precise wildlife locations are sensitive before enabling it.

## Model limits

The bundled model has 6,522 labels, including 101 generic or non-bird classes. That does not make it
a general wildlife survey model. It contains only one primate label (`Alouatta pigra`, Mexican
Black Howler Monkey), which is not useful coverage for an Amazon mammal survey, and it has no bat
classes.

The normal recorder samples at 48 kHz, which cannot capture much ultrasonic bat echolocation, and
the supplied bird model is not trained as a bat detector. Bat monitoring needs suitable ultrasonic
hardware and specialized software. Projects such as
[BattyBirdNET-Pi](https://github.com/rdz-oss/BattyBirdNET-Pi) can be evaluated separately, but they
are not a drop-in model for this installation and do not provide a supported Amazon mammal model.
