-- Issue #62: the 'vereinsname' setting (seeded in 007, never actually read
-- anywhere in the app) becomes the configurable application name - now
-- wired into <title>, header, manifest.webmanifest, push notifications,
-- alarm mail subjects, ICS feeds (X-WR-CALNAME) and the footer.
-- 'app_name_kurz' (manifest short_name) is new and stays unseeded -
-- SettingRepository::get() already returns a default for a missing key.
UPDATE setting SET `key` = 'app_name' WHERE `key` = 'vereinsname';
