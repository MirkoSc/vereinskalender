-- Update channel (CLAUDE.md sections 10/11): 'stable' ignores pre-releases,
-- 'beta' (the subdomain test instance) also installs pre-releases.
INSERT INTO setting (`key`, `value`) VALUES ('update_kanal', 'stable');
