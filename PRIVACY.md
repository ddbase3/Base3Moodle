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

## BASE3 groups, features and native authorization (19 October 2026)

`MoodleAuthorizationService` reads the current Moodle-authenticated identity,
checks `is_siteadmin()` or `has_capability()` for native rights, and evaluates
BASE3 feature audiences against discovered group and criterion expressions.
`MoodleRoleCriterion` calls Moodle's native role resolver for the chosen user
and context; `MoodleCourseGroupCriterion` calls native course-group services.
Both resolve attributes in memory and do not replicate Moodle role or course
group records. Request-local caching of role IDs is limited to the PHP request.

The site-supplied `ISettingsStore` persists managed group definitions and
criteria in `base3moodle/groups`, and feature audience overrides in
`base3_authorization_features/catalog`. Depending on enabled criteria, these
records may contain Moodle user IDs, role IDs, context IDs, course/group IDs,
exact user login names, full email addresses, email domains, and other
plugin-specific personal attributes. Code-defined group expressions are in
plugin source; source/deployment backups may consequently contain personal
data when maintainers configure bootstrap identities.

Group and feature editors require native Moodle site administration AND BASE3
`development` membership, including for direct endpoint calls. Mutating POSTs
require a valid Moodle session key and the ClientStack editor's separate
BASE3 session CSRF token. A user with only a BASE3 development
assignment cannot acquire native Moodle administrator status from these
rules. The unmodified ClientStack Group/Feature displays include the shared
styles/scripts. The existing same-origin `local/base3/ajax.php` endpoint
handles edits; no separate remote recipient, external transfer or
administration data store is introduced by the replacement.

Removing a managed group removes its current settings record only if no
feature refers to it and it is not the current configured fallback. Removing a feature override through an administrator's
host settings processes does not delete Moodle user accounts, course-group
records, roles or historical backup copies. The source does not establish
a universal retention policy for host-managed rules, Moodle audit logs, or
backups. The Moodle operator must assess configured plugin criteria, access
to source containing bootstrap identities, backups, erasure requests and
external integrations. The interface-level implementation does not establish
GDPR compliance for every deployment.

The shared ClientStack repeated-assignment editor provides labeled controls,
keyboard-capable form interactions, server-side validation, CSRF checks and
status feedback. The optional group fallback persists only the chosen logical
group name in the existing `base3moodle/groups` settings record; no Moodle
identity or native role record is copied. Membership for a fallback group is
computed transiently for authenticated users with no specific BASE3 match;
privileged `development` and `administration` fallbacks are prohibited. A
real Moodle browser/theme with keyboard and assistive technology must still
be checked against BASE3 WCAG 2.2 AA before production release.
