#!/usr/bin/env bash
# Läuft im Emulator-Runner: App installieren, starten, Fotos und Logcat sichern.
set -x
mkdir -p emulator-out
adb shell dumpsys package com.google.android.webview | grep versionName | head -2 > emulator-out/webview.txt
adb install -r android/app/build/outputs/apk/debug/app-debug.apk
adb logcat -c
adb shell am start -W -n run.zone3.app/.MainActivity
for t in 5 15 30 60; do
  sleep $(( t - ${last:-0} )); last=$t
  adb exec-out screencap -p > emulator-out/start-${t}s.png
done
adb shell uiautomator dump /sdcard/ui.xml && adb pull /sdcard/ui.xml emulator-out/ui-60s.xml
adb logcat -d > emulator-out/logcat.txt
# Zweiter Kaltstart
adb shell am force-stop run.zone3.app
adb logcat -c
adb shell am start -W -n run.zone3.app/.MainActivity
sleep 20
adb exec-out screencap -p > emulator-out/kaltstart2-20s.png
adb logcat -d > emulator-out/logcat-kaltstart2.txt
exit 0
