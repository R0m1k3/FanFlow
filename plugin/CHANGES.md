###@@VERSION@@
- 3-pin fans (not drivable) are always shown on the Dashboard tile and the settings page, stopped ones included, with "running since 14:32" / "stopped since 14:40". A fan FanFlow does not know is added as soon as it turns, even after detection.

###2026.11.25.1945
- Fix: a fan plugged on a header that was empty at detection was never seen. FanFlow now notices a fan it does not know turning, tests the empty headers again (at start and while running) and drives the new fan.

###2026.11.25.1912
- Fix: after a reboot the fans were "no longer found" and stayed with the BIOS, because the sensor chip driver loaded during detection was not loaded again. The service now loads it at start (and tries again every 10 minutes while fans are missing).

###2026.11.24.1001
- First release. Detects the fans by itself (sensor chip driver, CPU, graphics cards and disk temperatures, which output drives which fan, each fan's speed range) and drives them from the CPU, graphics card and disk temperatures. Graphics cards keep their own fans.
- Dashboard tile: temperatures with their last ten minutes, each fan with its speed and what drives it.
- Settings page: profiles (Silent, Balanced, Performance), curves with draggable points, fixed speed or BIOS per fan, full-speed limits, notifications. French and English.
- Safety: full speed when the CPU sensor stops answering, past a critical temperature or if the service stops unexpectedly; the BIOS gets the fans back when FanFlow is stopped or removed.
