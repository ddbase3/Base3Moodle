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

## Native paginated user search (21 October 2026)

`src/Adapter/MoodleUsermanager.php::searchUsers()` uses the existing Moodle
user directory to search native user IDs, logins, first/last and composed
names, and email addresses. Searches are performed only when the current
Moodle actor has `moodle/user:update` at system context; the ClientStack HTTP
caller remains responsible for additional BASE3 authorization and CSRF
verification. Moodle's database API applies literal escaping to search terms,
binds all query values and limits returned records with native pagination.

The query fetches only account identity fields (including the supported Moodle
name components and language). The adapter maps returned records into BASE3
`User` objects without per-result role, group or administrative-privilege
resolution. Deleted accounts and the guest account are excluded; suspended
and unconfirmed records may appear because they are still active native
records. The implementation creates no new user database, persistence,
search history, third-party transfer or audit record. The host's existing
query/application logging, backups, access-control policy, and any deployment
retention obligations still require operator verification. Query results must
not be exposed to unauthorized frontends; results can contain personal data.

## Native identity and usermanager completion (22 October 2026)

`MoodleUsermanager` now reads native system-context role assignments and
capability definitions when returning a detailed `User` or effective metadata.
Detailed identities may include the native role ID, shortname and label, plus
an internal marker for native site administrators. Course/activity role
membership remains resolved through the existing context-specific Moodle
criterion. `getAllUsers()` and `searchUsers()` select only native user identity
fields and do not resolve per-user roles, groups or administrator status.
Their results can contain usernames, names, email addresses and language
preferences, so callers must restrict directory access. No additional BASE3
identity store, search log, receiver or external transfer is created here.

Account registration through this adapter processes the requested username,
first and last name, email address and initial password. When native permission
and validation succeed, Moodle creates the account using its normal database,
password hashing and account-created event. Password changes process the
current actor's old and new plaintext credentials transiently through Moodle
password verification, configured policy and reuse-history checks, and the
native manual authentication plugin. On success Moodle's password history is
updated, any configured other-session invalidation is performed, and password
change preferences are cleared. The adapter does not persist raw credentials
or maintain a separate account/credential table. Moodle authentication, audit
logging and backups may retain associated metadata; their exact retention
periods and recipients are not determined by this source snapshot.

Authorized role-assignment changes modify native Moodle `role_assignments`
records only in the system context, restricted to manually assigned roles.
Site-wide capability grants/revocations modify Moodle's existing
`role_capabilities` records only; they do not create a BASE3 ACL mirror.
Moodle owns the related cache invalidation and native role-change events.
The generic native-rights inspector remains read-only. User deletion,
role-assignment erasure and related backup deletion continue to be governed
by the Moodle host and its deployment policies, not by `IUsermanager`.

The current patch adds no HTML or JavaScript UI; no new interactive
accessibility behavior is claimed. Operators must verify consent/notification
requirements where applicable, access to identity and capability metadata,
account lifecycle, configured authentication services, audit logs, retention,
backup erasure and privacy rights in the actual Moodle environment. Native
Moodle integration tests are required in addition to isolated PHP tests.
