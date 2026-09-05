# Off-grid deployment guide

BirdNET-Pi can record, identify, store detections, and serve its local dashboard without an internet
connection after installation. An off-grid station still needs a dependable plan for networking,
timekeeping, storage, power, environmental protection, maintenance, and recovery. This guide applies
to any installation that will operate with limited, intermittent, or no internet access.

## Basic setup — enough for most people

If the station will remain reasonably accessible and brief downtime would be manageable, these five
steps are generally enough:

1. **Prepare while online.** Install BirdNET-Pi and any intended updates using the supported 64-bit
   Raspberry Pi OS release.
2. **Configure the essentials.** Set the station coordinates and timezone, choose a non-blank
   BirdNET-Pi admin password, and use a strong Raspberry Pi/SSH password. Do not expose the dashboard
   directly to the internet or forward port 80 to the Pi.
3. **Confirm that it works.** Make sure the station records and identifies a new bird, then verify
   that its clip plays and its spectrogram appears in the dashboard.
4. **Provide suitable hardware.** Use a dependable regulated power source, enough storage for the
   expected deployment, and basic protection from the site's weather without blocking the
   microphone or trapping heat.
5. **Keep a simple recovery option.** Save any Wi-Fi network the station will use, know how you would
   reach or restart the Pi locally, and keep at least one recent BirdNET-Pi data backup somewhere
   other than the station.

For most accessible off-grid installations, that is enough. Weather and Wikipedia images can remain
enabled; if the internet disappears, those optional features may become stale or unavailable, but
local recording and identification continue. You do not need to add a recovery hotspot, hardware
watchdog, real-time clock, spare SD card, or strict no-network configuration unless your particular
deployment calls for one.

## Detailed planning — optional

The remaining sections are a reference for stations that will be unattended for long periods,
difficult or expensive to revisit, exposed to harsh conditions, limited by power or bandwidth, or
used where data loss has serious consequences. Use only the sections that apply to your deployment;
this is not a checklist that every user must complete.

The guide deliberately keeps risky behavior out of the BirdNET-Pi installer: it does not
automatically enable a hotspot, hardware watchdog, scheduled reboot, or different model, and
installing an update does not change the existing defaults described below.

### Additional preparation

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

### Wi-Fi recovery

BirdNET-Pi does not include its own Wi-Fi manager or captive portal. The least invasive approach is
to let Raspberry Pi OS NetworkManager handle Wi-Fi and save every known network before deployment.
For example, temporarily enable a phone hotspot or other recovery network that will be available at
the site, connect the Pi to it once, and verify that the saved connection reconnects after the
primary access point disappears. Ethernet or a local keyboard and display remain the most
dependable rescue routes.

[Comitup](https://davesteele.github.io/comitup/) is an optional third-party project that can expose a
temporary setup hotspot when no saved network works. It is not installed, configured, or supported
by BirdNET-Pi. If you evaluate it, test it repeatedly on the exact finished OS image before
deployment. Do not flash Comitup's standalone SD image over the only BirdNET-Pi card; that would
replace the installation and its data. Evaluate the package on a cloned or spare card, because it
takes over Wi-Fi management.

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

### Network traffic

Detection stays local. The following features and status checks can contact another system:

- BirdWeather, Apprise notifications, and the heartbeat URL only run after their corresponding
  values are configured.
- Automatic updates are off by default. Opening an authenticated dashboard or System Controls can
  still perform an update-status `git fetch`; that check is time-bounded so an offline network does
  not leave it running indefinitely. Leave automatic updates off for an unattended off-grid station
  and update while someone has local access and a rollback card.
- Wikipedia is the default species-image provider. Select **None** under **Settings → Species
  Images** for a station that should not request images.
- Weather syncing is enabled by default. Under **Settings → Location & Weather**, turn
  off **Enable weather syncing** to stop both Open-Meteo and configured Home Assistant requests.
  Existing weather history is kept, and turning syncing back on resumes the normal hourly process.
- Configured Home Assistant weather/temperature entities can save local readings during an internet
  outage. Open-Meteo is still requested while syncing is enabled and provides available fallback.
  Missing fields remain unavailable. See [Local weather](LOCAL_WEATHER.md) for source behavior and rollback.
- Opening **System Info** performs a public-IP lookup, and a few optional admin tools load interface
  assets from public CDNs. These do not run the detection pipeline, but they matter to a strict
  outbound-network policy.

Default weather, image, and update-status failures do not stop local analysis. Configured reporting
integrations are different: slow BirdWeather or notification requests can delay the reporting queue,
so leave BirdWeather, Apprise, and heartbeat values blank unless they have been tested under the
deployment network conditions. A strict no-outbound-network installation should also enforce that
policy at its router or firewall.

### Power and optional services

Continuous identification has to keep the microphone and inference process awake. Raising the
confidence threshold can reduce stored clips and notifications, but it does not stop the model from
analyzing the audio, so it is not a major CPU-power control.

Raspberry Pi OS Lite and avoiding unnecessary live views are the safest savings. Under **Settings →
Services**, you may disable features you do not use, such as Live Audio Stream, Web Terminal,
BirdNET Log, Streamlit Statistics, Chart Viewer, and Spectrogram Viewer. Disabling a viewer makes
that viewer unavailable; it does not disable recording or analysis. BirdNET-Pi will preserve an
explicitly disabled optional service during later settings saves and restarts.

Measure the complete station—Pi, USB microphone, storage, network equipment, and conversion
losses—over several real days. Size batteries, solar panels, generators, or other supplies for the
site's worst expected conditions rather than their advertised peak output. Provide regulated power
and a safe shutdown or sufficient reserve rather than relying on scheduled reboots.

### Recovery and watchdogs

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

### Clock, storage, and environment

- A Raspberry Pi 5 has a real-time clock, but it needs a compatible rechargeable backup battery to
  retain time through a total power loss. Charging is disabled by default, so follow Raspberry Pi's
  [RTC and battery setup](https://www.raspberrypi.com/documentation/computers/raspberry-pi.html#real-time-clock-rtc);
  do not substitute a primary lithium or lithium-ion cell. Older models generally need an external
  RTC if correct time must survive a long power outage without NTP. Verify the timestamp after a
  fully offline cold start.
- Use high-endurance storage and configure Disk Management for the failure mode you prefer: purge
  old material at the threshold or stop services instead. Confirm cleanup behavior on test data.
- Protect the Pi, connectors, and microphone from the site's likely rain, snow, dust, condensation,
  humidity, insects or other wildlife, heat, cold, and cable strain without sealing the microphone
  acoustically or trapping excessive heat.
- A full card image is the recovery plan for OS files, packages, systemd units, permissions, and
  boot configuration. The application backup is useful for data, but is not a complete machine
  image.

### Privacy and sensitive locations

The human-voice filter is available under **Advanced Settings**. The default threshold `0` still
checks the model's top 10 predictions for a Human label; raising the percentage checks more of the
ranked predictions. If people may be recorded, test an appropriate setting while remembering that
an automated classifier cannot guarantee that all speech is removed.

BirdWeather is opt-in: a blank station ID means no BirdWeather upload. When enabled, the current
integration uploads the full analyzed audio segment as FLAC and then sends detection timestamps,
species, confidence, and coordinates. That audio can contain sounds—including speech—that are not
present in the locally extracted bird clip. Obtain any consent required at the site, review local
recording law, and consider whether precise wildlife locations are sensitive before enabling it.

### Model limits

The bundled model has 6,522 labels, including 101 generic or non-bird classes. That does not make it
a general wildlife survey model. Its non-bird coverage is uneven: it contains only one primate label
(`Alouatta pigra`, Mexican Black Howler Monkey), offers no broad mammal coverage, and has no bat
classes. Check the shipped labels before relying on BirdNET-Pi for any target other than birds.

The normal recorder samples at 48 kHz, which cannot capture much ultrasonic bat echolocation, and
the supplied bird model is not trained as a bat detector. Bat monitoring needs suitable ultrasonic
hardware and specialized software. Projects such as
[BattyBirdNET-Pi](https://github.com/rdz-oss/BattyBirdNET-Pi) can be evaluated separately, but they
are not a drop-in model for this installation and do not provide a supported general-purpose mammal
detector.
