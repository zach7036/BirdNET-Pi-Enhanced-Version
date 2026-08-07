#!/usr/bin/env bash
source /etc/birdnet/birdnet.conf
sqlite3 $HOME/BirdNET-Pi/scripts/birds.db << EOF
DROP TABLE IF EXISTS detections;
CREATE TABLE IF NOT EXISTS detections (
  Date DATE,
  Time TIME,
  Sci_Name VARCHAR(100) NOT NULL,
  Com_Name VARCHAR(100) NOT NULL,
  Confidence FLOAT,
  Lat FLOAT,
  Lon FLOAT,
  Cutoff FLOAT,
  Week INT,
  Sens FLOAT,
  Overlap FLOAT,
  File_Name VARCHAR(100) NOT NULL);
CREATE INDEX "detections_Com_Name" ON "detections" ("Com_Name");
CREATE INDEX "detections_Sci_Name" ON "detections" ("Sci_Name");
CREATE INDEX "detections_Date_Time" ON "detections" ("Date" DESC, "Time" DESC);
CREATE INDEX "detections_Sci_Name_Date" ON "detections" ("Sci_Name", "Date");
CREATE INDEX "detections_Date_Sci_Name" ON "detections" ("Date", "Sci_Name");
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
-- Weather is isolated from detections: created here (not dropped) so the table
-- exists from first boot even before the first successful sync, and so
-- clearing detections keeps the weather history.
CREATE TABLE IF NOT EXISTS weather (
  Date DATE,
  Hour INT,
  Temp FLOAT,
  ConditionCode INT,
  IsDay INT,
  WindSpeed FLOAT,
  WindDirection INT,
  PRIMARY KEY(Date, Hour));
EOF
chown $USER:$USER $HOME/BirdNET-Pi/scripts/birds.db
chmod g+w $HOME/BirdNET-Pi/scripts/birds.db
