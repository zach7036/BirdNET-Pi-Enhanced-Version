<h1 align="center"><a href="https://github.com/mcguirepr89/BirdNET-Pi/blob/main/LICENSE">Review the license!!</a></h1>
<h1 align="center">You may not use BirdNET-Pi to develop a commercial product!!!!</h1>
<h1 align="center">
  BirdNET-Pi: Modern Version
</h1>
<p align="center">
A realtime acoustic bird classification system for the Raspberry Pi 5, 4B, 400, 3B+, and 0W2
</p>
<p align="center">
  <img src="https://user-images.githubusercontent.com/60325264/140656397-bf76bad4-f110-467c-897d-992ff0f96476.png" />
</p>

## About This Modern Version
This repository is an enhanced, fully modernized version of BirdNET-Pi, built on top of the excellent backend foundations laid by [Nachtzuster](https://github.com/Nachtzuster/BirdNET-Pi) and the original creator [mcguirepr89](https://github.com/mcguirepr89/BirdNET-Pi). 

This version introduces a complete overhaul of the user interface to bring the system inline with modern web design standards, along with highly requested functional improvements.

Note: see 'Migrating from previous forks' on how to migrate from Nachtzuster.

## Introduction
BirdNET-Pi is built on the [BirdNET framework](https://github.com/kahst/BirdNET-Analyzer) by [**@kahst**](https://github.com/kahst) <a href="https://creativecommons.org/licenses/by-nc-sa/4.0/"><img src="https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-lightgrey.svg"></a> using [pre-built TFLite binaries](https://github.com/PINTO0309/TensorflowLite-bin) by [**@PINTO0309**](https://github.com/PINTO0309) . It is able to recognize bird sounds from a USB microphone or sound card in realtime and share its data with the rest of the world.

Check out birds from around the world
- [BirdWeather](https://app.birdweather.com)<br>

## Features Deep Dive
* **24/7 recording and automatic identification** of bird songs, chirps, and peeps using BirdNET machine learning
* **Automatic extraction and cataloguing** of bird clips from full-length recordings
* **Tools to visualize your recorded bird data** and analyze trends
* **Live audio stream and spectrogram**
* **Automatic disk space management** that periodically purges old audio files
* [BirdWeather](https://app.birdweather.com) integration
* Web interface access to all data and logs provided by [Caddy](https://caddyserver.com)
* SQLite3 Database, [Adminer](https://www.adminer.org/) database maintenance, and FTP server included
* [Apprise Notifications](https://github.com/caronc/apprise) supporting 90+ notification platforms



## Comprehensive List of Updates & New Features:

**1. Complete UI & Theme Redesign**
- Fully modernized visual aesthetic using a cohesive teal, slate, and off-white color palette.
- Implementation of a CSS variable system for consistent styling and a beautiful dark mode.
- Replaced outdated HTML tables with responsive, rounded, shadow-styled CSS "cards" across almost all views (Overview, Recordings, Species, Analytics).
- Compacted header and grouped left-side navigation elements for a cleaner layout.

**2. Dashboard & Layout Overhaul**
- **KPI Cards:** Transformed the old overview statistics into sleek, responsive Key Performance Indicator (KPI) cards aligned smoothly at the top.
- **Live Activity Feed:** Built a brand new, constantly updating "Live Activity" feed displaying recent detections with confidence badges, cleanly integrated into the right-side navigation column.
- **Live Audio Player:** Redesigned the Live Audio player as a dynamic floating panel that pins to the top right and gracefully auto-retracts.

**3. Advanced Analytics & Data Visualizations**
- **Comprehensive Analytics Dashboard (Chart.js):** Replaced legacy graphs with fully interactive Chart.js instances (hover tooltips, legend toggling, responsive resizing). Specific tools include:
  - **Top 10 Species:** Horizontal bar ranking of most frequent visitors.
  - **Detections by Time of Day:** Bar chart showing aggregate hourly activity levels.
  - **Detection Trends:** Line chart plotting overall daily volume.
  - **Species Detection Trends:** Stacked interactive area chart comparing daily volumes between uniquely selected species over time.
  - **Species Diversity Over Time:** Line chart mapping the number of unique species detected per day.
  - **Detection Patterns by Time of Day:** Multi-line chart overlapping hourly activity patterns for specific selected species.

**4. All-New Deep Insights Pages**
- Built an entirely new "Insights" suite that analyzes your raw SQLite data to generate behavioral conclusions:
  - **Dashboard:** Generates a dynamic "Yard Health Score" based on volume, stability, and rarity. Highlights Lifetime Milestones and categorizes "Rare Visitors" (<5 detections).
  - **Behavior Analysis:** Automatically calculates your local "Dawn Chorus" participants, identifies "Nocturnal Species", and plots the earliest/latest active windows for your birds.
  - **Migration & Seasonality:** Tracks "New Arrivals" (species seen for the first time in 14 days), notes who has "Gone Quiet", and visualizes "Seasonal Presence" matching actual detections against eBird expected frequencies.

**5. Enhanced Heatmap & Weather Integration**
- **High-DPI Heatmaps:** Upgraded the 24-hour heatmap to render flawlessly on high-resolution Retina displays. 
- **Weather Integration:** Integrated **Open-Meteo** hourly weather data to paint real-time temperature/conditions directly onto the Heatmap and Live Activity feed.

**6. Enhanced Species & Media Galleries**
- **Robust Image Fetching:** Completely overhauled how species thumbnails are fetched. Added fail-safes, session caching, and multi-source fallbacks (Wikipedia & Flickr API).
- High-resolution 1024px thumbnails and proper scaling for crisp image rendering.
- Modernized the "Best Recordings" and "Species Detail" views into responsive grid layouts highlighting audio/video players.

**7. Upgraded eBird Integration**
- Completely rebuilt the eBird Checklist Export utility.
- Added a calendar **Date Picker** to allow historical checklist generation.
- Added strict JavaScript validation to ensure required fields (Protocol, Observers, Distance) are filled.
- Engineered dynamic CSS **SVG Tooltips** (`ⓘ`) next to every field to actively guide the user on eBird's official formatting rules.

## Screenshots
![Analytics Dashboard - Heatmap](docs/analytics_heatmap.png)

![Overview Dashboard](docs/overview.png)

![Analytics Dashboard - Top](docs/analytics_top.png)

![Analytics Dashboard - Bottom](docs/analytics_bottom.png)

![Insights - Migration](docs/insights_migration.png)

![Insights - Seasonal Presence](docs/insights_seasonality.png)

![Insights - Weather](docs/insights_weather.png)

![Species Gallery](docs/species_gallery.png)

## Requirements
* A Raspberry Pi 5, Raspberry 4B, Raspberry Pi 400, Raspberry Pi 3B+, or Raspberry Pi 0W2 (The 3B+ and 0W2 must run on RaspiOS-ARM64-Lite)
* An SD Card with the 64-bit version of RaspiOS installed (please use **Trixie**) -- Lite is recommended, but the installation works on RaspiOS-ARM64-Full as well. Downloads available within the [Raspberry Pi Imager](https://www.raspberrypi.com/software/).
* A USB Microphone or Sound Card

## Installation
[A comprehensive installation guide is available here](https://github.com/mcguirepr89/BirdNET-Pi/wiki/Installation-Guide).

*Note: The title of this wiki guide states that it is deprecated and tells you to "use Nachtzuster's fork." Please be aware that this guide was written for the original creator (mcguirepr89), who predates both Nachtzuster and this Modern Version. **This Modern Version fork is NOT deprecated and you should absolutely still use it.** While the wiki guide itself is visually outdated and contains this warning, the initial OS installation process before you reach the web UI is still exactly the same. Just follow the steps, but make sure to choose **Bookworm** or **Trixie** when imaging your SD card, and use the `curl` command provided below instead of the one listed in the wiki.*

Please note that installing BirdNET-Pi on top of other existing servers is not supported. If this is something that you require, please open a discussion for your idea and inquire about how to contribute to development.

[Raspberry Pi 3B[+] and 0W2 installation guide available here](https://github.com/mcguirepr89/BirdNET-Pi/wiki/RPi0W2-Installation-Guide)

The system can be installed with a single command designed for a fresh OS installation:
```
curl -s https://raw.githubusercontent.com/zach7036/BirdNET-Pi-Modern-Version/main/newinstaller.sh | bash
```
The installer takes care of any and all necessary updates, so you can run that as the very first command upon the first boot. The installation creates a log in `$HOME/installation-$(date "+%F").txt`.

## Access
The BirdNET-Pi can be accessed from any web browser on the same network:
- `http://birdnetpi.local` OR your Pi's IP address
- Default Basic Authentication Username: `birdnet`
- Password is empty by default. Set this in "Tools" > "Settings" > "Advanced Settings"

Please take a look at the [wiki](https://github.com/mcguirepr89/BirdNET-Pi/wiki) and [discussions](https://github.com/mcguirepr89/BirdNET-Pi/discussions) for information on:
- [BirdNET-Pi's Deep Convolutional Neural Network(s)](https://github.com/mcguirepr89/BirdNET-Pi/wiki/BirdNET-Pi:-some-theory-on-classification-&-some-practical-hints)
- [Making your installation public](https://github.com/mcguirepr89/BirdNET-Pi/wiki/Sharing-Your-BirdNET-Pi)
- [Backing up and restoring your database](https://github.com/mcguirepr89/BirdNET-Pi/wiki/Backup-and-Restore-the-Database)
- [Adjusting your sound card settings](https://github.com/mcguirepr89/BirdNET-Pi/wiki/Adjusting-your-sound-card)
- [Suggested USB microphones](https://github.com/mcguirepr89/BirdNET-Pi/discussions/39)
- [Building your own microphone](https://github.com/DD4WH/SASS/wiki/Stereo--(Mono)-recording-low-noise-low-cost-system)
- [Privacy concerns and options](https://github.com/mcguirepr89/BirdNET-Pi/discussions/166)

## Updating 
Use the web interface and go to **"Tools" > "System Controls" > "Update"**. If you encounter any issues with that, or suspect that the update did not work for some reason, please save its output and post it in an issue where we can help.

## Backup and Restore
Use the web interface and go to **"Tools" > "System Controls" > "Backup"** or **"Restore"**. Backup/Restore is primarily meant for migrating your data from one system to another. Since the time required to create or restore a backup depends on the size of the data set and the speed of the storage, this could take quite a while.

Alternatively, the backup script can be used directly from the command line. These examples assume the backup medium is mounted on `/mnt`:
To backup:
```commandline
./scripts/backup_data.sh -a backup -f /mnt/birds/backup-2024-07-09.tar
```
To restore:
```commandline
./scripts/backup_data.sh -a restore -f /mnt/birds/backup-2024-07-09.tar
```

## x86_64 support
x86_64 support is mainly intended for developers or highly Linux-savvy users. Some brief pointers:
- Use Debian 12 or 13.
- The user needs passwordless `sudo`.

For Proxmox, a user has reported adding this in their `cpu-models.conf` in order for the custom TFLite build to work:
```
cpu-model: BirdNet
    flags +sse4.1
    reported-model host
```

## Uninstallation
The following command will completely uninstall the software and remove the BirdNET-Pi directory from your home folder, deleting all audio and database files in the process:
```
/usr/local/bin/uninstall.sh && cd ~ && rm -drf BirdNET-Pi
```

## Migrating from previous forks
Before switching, make sure your current installation is fully up-to-date and **make sure to have a backup**. A backup is the only way to get back to the original fork if desired. Please note that upgrading your underlying OS in-place from Bullseye to Bookworm/Trixie is not going to work. If you are upgrading your OS, you need to start from a fresh install and copy back your data via the Restore tool.

If your OS is already correct, run these commands to migrate your existing installation to this modern repo:
```
git remote remove origin
git remote add origin https://github.com/zach7036/BirdNET-Pi-Modern-Version.git
./scripts/update_birdnet.sh
```

## Troubleshooting and Ideas
*Hint: A lot of weird problems can be solved by simply restarting the core services. Do this from the web interface "Tools" > "Services" > "Restart Core Services".*

Having trouble or have an idea? Submit an issue for trouble and a discussion for ideas. Please do *not* submit an issue as a discussion. Ensure you search the repo for your issue before creating a new one.

## Sharing
Please join a Discussion and consider joining [BirdWeather!](https://app.birdweather.com) If you find BirdNET-Pi has been worth your time, please consider [making your installation public](https://github.com/mcguirepr89/BirdNET-Pi/wiki/Sharing-Your-BirdNET-Pi).

## Homeassistant addon
BirdNET-Pi can also be run as a [Homeassistant](https://www.home-assistant.io/) addon through Docker.
For more information: https://github.com/alexbelgium/hassio-addons/blob/master/birdnet-pi/README.md

## Docker
BirdNET-Pi can also be run as a standalone Docker container.
For more information: https://github.com/alexbelgium/hassio-addons/blob/master/birdnet-pi/README_standalone.md

## Cool Links
- [Marie Lelouche's <i>Out of Spaces</i>](https://www.lestanneries.fr/exposition/marie-lelouche-out-of-spaces/) using BirdNET-Pi in post-sculpture VR! [Press Kit](https://github.com/mcguirepr89/BirdNET-Pi-assets/blob/main/dp_out_of_spaces_marie_lelouche_digital_05_01_22.pdf)
- [Research on noded BirdNET-Pi networks for farming](https://github.com/mcguirepr89/BirdNET-Pi-assets/blob/main/G23_Report_ModelBasedSysEngineering_FarmMarkBirdDetector_V1__Copy_.pdf)
- [PixCams Build Guide](https://pixcams.com/building-a-birdnet-pi-real-time-acoustic-bird-id-station/)
- [Core-Electronics Build Article](https://core-electronics.com.au/projects/bird-calls-raspberry-pi)
- [RaspberryPi.com Blog Post](https://www.raspberrypi.com/news/classify-birds-acoustically-with-birdnet-pi/)
- [MagPi Issue 119 Showcase Article](https://magpi.raspberrypi.com/issues/119/pdf)

### Internationalization:
The bird names are in English by default, but other localized versions are available thanks to the efforts of [@patlevin](https://github.com/patlevin). Use the web interface's "Tools" > "Settings" and select your "Database Language" to have detections translated natively.

---
_Original BirdNET framework by [@kahst](https://github.com/kahst). Pre-built TFLite binaries by [@PINTO0309](https://github.com/PINTO0309)._
