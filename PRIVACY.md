# Base3Moodle: verified data processing

Source reviewed: `src/Adapter/MoodleFileManagerHttpService.php`,
`MoodleFileUploadService.php`, `MoodleManagedFileStorageService.php`,
`MoodleFileStorage.php`, `MoodleUsermanager.php`, and
`Base3MoodleFileManagerOutput.php` (17 October 2026).

The FileManager output handles the active Moodle user ID and the admin
capability check (`moodle/site:config`) via BASE3 `IUsermanager`, plus the
existing Moodle session key. The configured owner group/name and requested
file operation are supplied to the unchanged HTTP service. JSON requests,
file names, paths, file bytes and upload metadata are processed to manage
resources for that configured owner.

The managed storage backend persists content beneath `DIR_LOCAL/storage/`,
using a SHA-256 owner identifier. `DIR_LOCAL` is set to the Moodle data root's
`base3/` directory by the native integration. Chunk upload sessions store
metadata (including user ID and creation time) and parts beneath
`DIR_LOCAL/filemanager-upload/`. Completed and aborted sessions are deleted;
there is no verified automatic expiry for abandoned sessions.

Only the routing has changed. It does not introduce additional recipients,
external transfers, or new persistent data stores. The storage API already
supports file deletion and removal of owners; deleting physical backups is
outside its scope. Deployment-specific controls, log/backup retention,
processor information, and any other plugins' data flow remain to be
assessed by the operator. The existing ClientStack FileManager UI requires
live keyboard and assistive-technology verification for WCAG 2.2 AA.
