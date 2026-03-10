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

### Comprehensive List of Updates & New Features:

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

## Installation
The system can be installed with a single command designed for a fresh OS installation:
```
curl -s https://raw.githubusercontent.com/zach7036/BirdNET-Pi-Modern-Version/main/newinstaller.sh | bash
```
*Note: Make sure your SD Card is imaged with a 64-bit version of RaspiOS (Bookworm or Trixie).*

## Migrating from previous forks
If you already have a working installation from an older BirdNET-Pi fork and want to upgrade to this modern UI without losing your database and audio data:
```
git remote remove origin
git remote add origin https://github.com/zach7036/BirdNET-Pi-Modern-Version.git
./scripts/update_birdnet.sh
```

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

### Internationalization:
The bird names are in English by default, but other localized versions are available thanks to the efforts of [@patlevin](https://github.com/patlevin). Use the web interface's "Tools" > "Settings" and select your "Database Language" to have detections translated natively.

---
_Original BirdNET framework by [@kahst](https://github.com/kahst). Pre-built TFLite binaries by [@PINTO0309](https://github.com/PINTO0309)._
