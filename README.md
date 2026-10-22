# Base3Moodle

Native Moodle integration of BASE3 services and the common BASE3 authorization contracts.
The Moodle host remains the single authority for Moodle identities, context-specific
capabilities, role assignments, and course groups. BASE3 does not replicate Moodle RBAC
or add its own native-role database.

## Authorization integration

`Base3MoodlePlugin` binds the framework `IAuthorizationService` to
`MoodleAuthorizationService`, with default authority `moodle`. The service
handles three well-defined permission scopes:

- `system/admin` checks Moodle `is_siteadmin()`.
- `moodle/<native capability name>` calls Moodle `has_capability()` in its
  native context. Supply a `ResourceReference('moodle', 'context_id', '<id>')`
  for resource-specific checks. Without a resource, the native system
  context is used. Only the current, authenticated host user can be checked.
- `feature/<permission>` resolves exactly one discovered `IFeature`, then
  evaluates the host-configured or code-defined audience using the single
  framework `UserCriterionEvaluator` and effective BASE3 groups. The
  default audience is defined by the feature plugin, not by this adapter.

`MoodleAuthorizationAdministrationService` provides read-only direct
Moodle role-capability assignments for the shared BASE3 Permissions UI.
The input resource type is `context_id`, with an existing Moodle context
identifier. The view intentionally shows only direct `CAP_ALLOW` grants:
`CAP_PREVENT`, `CAP_PROHIBIT` and effective inherited capabilities cannot be
presented as equivalent direct grants. Use Moodle's native role-permission
administration to edit roles and capabilities. This does not restrict
feature/group audience administration.

## Groups and criteria

`MoodleGroupConfiguration` merges code-discovered `IGroup` definitions and
host-managed `Group` records. Code-owned membership expressions remain
immutable. Additional criteria configured by administrators are OR-combined
with the fixed expression; they cannot revoke code-owned members.

Available code-defined groups include `anonymous`, `authenticated`, and
`administration`. `Base3MoodleLab` separately owns the code-defined
`development` group for this project. Host-defined groups have unique logical
names and share the same evaluator. Group criteria must not refer to another
BASE3 group. Consequently there is no group dependency cycle.

Supported platform predicates are discoverable via `IClassMap`:

- `MoodleSiteAdministratorCriterion` delegates to `is_siteadmin()`.
- `MoodleRoleCriterion` delegates to `get_user_roles()` for the specified
  `context_id`, including roles inherited from ancestors. Without
  `context_id`, it uses the Moodle system context. `role_ids` are Moodle-native
  IDs. The UI exposes system-context role selections directly; course-context
  rules may be added by selecting this criterion and setting `context_id`.
- `MoodleCourseGroupCriterion` validates the native course-group relationship
  and checks `groups_is_member()`. Specify `course_id` and `group_id`.

Native Moodle course groups are NOT also injected into BASE3's logical
`IUsermanager::getGroups()` list. Use the discoverable criterion to include
course-group membership wherever a BASE3 group or feature needs it.

The common `Base3UserIdCriterion`, `Base3UserIdentityCriterion`, email-domain
criteria and other plugin predicates remain available through class-map discovery.

## Stored audiences and administration

The project bootstrap selects the existing Moodle-backed `ISettingsStore`.
The adapter owns these two named datasets, not separate tables:

- `base3moodle/groups`: custom group records and editable additions to code groups.
- `base3_authorization_features/catalog`: overrides for discovered features.

Each administration update writes its dataset once. A saved empty feature
audience means explicit denial; absent configuration retains the
feature's code-defined default.

The Groups and Features tabs use the **unchanged ClientStack** displays
`authorizationgroupsdisplay` and `authorizationfeaturesdisplay` and the
`UiFoundation\Api\IAuthorizationAudienceAdministrationService` contract.
`MoodleAudienceAdministrationService` adapts these to the existing Moodle
settings services; it does not render a separate UI, duplicate permissions or
create a new storage backend. The Moodle expression translator converts the
common form rows to the same canonical `any`/`all`/`not` expressions and
provides discoverable criterion schemas. Native role IDs and Moodle user IDs
are passed unchanged; context-specific Moodle roles use
`moodlerolecriterion` with `context_id`.

Administrators can create/remove managed BASE3 groups and add criteria to
editable code-defined groups without overriding fixed code membership. A
nonprivileged editable group can optionally be selected as the authenticated
fallback for users without a more specific BASE3 group match. Neither
`development` nor `administration` can be a fallback; removing a referenced
or configured fallback group is prohibited. Existing settings remain valid
without migration, and the initial fallback remains disabled.

Group/Feature configuration is allowed only when BOTH checks succeed:

1. The current user is a Moodle site administrator.
2. The current user is an effective BASE3 `development` group member.

These checks run in `MoodleAudienceAdministrationService` for each read and
mutation, independent of navigation visibility; native rights continue to be
decided solely by `IAuthorizationService`. All mutating requests require both
Moodle's `sesskey` at the existing generic AJAX dispatcher and the shared
ClientStack display's BASE3 session CSRF token. The generic Moodle AJAX
dispatcher remains the only transport.

## Native user and role metadata (22 October 2026)

`MoodleUsermanager` now populates `User::roles` for the active identity and for
`getUserById()`. The roles come from `get_user_roles()` in the Moodle system
context; role `id` is the native role ID and `name` is its stable shortname.
Site administrators additionally receive an internal, non-assignable
`base3:admin` marker. Course and activity context roles remain accessible
through the existing `MoodleRoleCriterion`, with explicit context identity.
A BASE3 logical group is never substituted for a native Moodle role.

`getPermissions()` enumerates the current user's granted native capabilities
in the system context. `getAllPermissions()` exposes the native capability
catalog only to Moodle site administrators. Both return descriptive BASE3
`Permission` DTOs, not authorization decisions. `getAllRoles()` returns native
role definitions with empty permission lists because effective capabilities
vary by context and must not be invented from a global role definition.
Actual access checks continue to use `IAuthorizationService` and Moodle's
`has_capability()` in the owning resource context.

Native membership changes use `role_assign()` and `role_unassign()`, with
Moodle's system-context capability check and `get_assignable_roles()`.
The adapter removes only manual assignments in the system context; roles
owned by enrolment plugins and roles in courses are not changed. User IDs,
role IDs and role names are validated against Moodle before write operations.
`assignRoleToGroup()` and `revokeRoleFromGroup()` remain unsupported because
native course groups do not imply a Moodle role-inheritance relationship.
Site-wide capability changes, when invoked directly through `IUsermanager`,
require native site administration and use Moodle's `assign_capability()` or
`unassign_capability()` APIs. The shared BASE3 native-rights inspector stays
read-only. It never becomes a parallel role editor.

`registUser()` creates manually authenticated Moodle accounts only for actors
with `moodle/user:create`, enforcing Moodle's username and password rules,
required identity data and duplicate-account checks. Moodle owns credential
hashing, persistence and account-created events. `changePassword()` is limited
to the current manual-authentication account, checks the previous credential,
Moodle's password policy, reuse history, impersonation and native own-password
permission or a forced-change preference, and delegates the update to the
native authentication plugin. Moodle's password history and configured session
invalidation are updated. These methods do not add an HTTP endpoint; callers
must authorize and protect any exposed action independently.

`getAllUsers()` returns an identity-only directory listing, subject to
`moodle/user:update` in the system context. Like `searchUsers()`, it omits
expensive native role/admin expansion. Deleted and guest accounts are excluded.
User searches and lists do not create another identity database or search log.

## Native user search

`MoodleUsermanager::searchUsers(string $query, int $limit = 20, int $offset = 0): array`
implements the shared BASE3 `IUsermanager` search contract. It searches the native
Moodle `{user}` table for a literal, case-insensitive substring in username,
first name, last name, either order of the full name, or email. All-digit queries
also match the native Moodle user ID exactly. An empty search returns no results.

The implementation uses Moodle's own `$DB->sql_like()`,
`$DB->sql_like_escape()`, `$DB->sql_fullname()` and
`$DB->get_records_sql()` APIs, with bound parameters and server-side
`$offset`/`$limit` (1..100). Results are sorted by full name and native ID.
Deleted accounts and the Moodle guest account are excluded; suspended or
unconfirmed accounts are not silently excluded. Search result identities use
the existing `User` mapping, but skip the per-user `is_siteadmin()` lookup and
do not expand roles or BASE3 memberships. `getUserById()` now resolves native system roles for the addressed user,
while preserving the original host-specific numeric identifier.

As with `getAllUsers()`, search requires `moodle/user:update` in the system
context. This is a host-side directory-access boundary, not a BASE3
authorization decision. The caller, such as the ClientStack audience-search
endpoint, must separately enforce its own `IAuthorizationService` check and
CSRF validation before exposing any search results. Neither the search text
nor result data are persistently stored by this adapter.

The existing `test/Adapter/MoodleUsermanagerSearchTest.php` checks the shared
search contract and pagination boundaries. The standalone
`test/Adapter/MoodleUsermanagerSmokeTest.php` exercises identity DTOs, native
role/capability operations, write authorization and password validation with
Moodle function stubs. The latter can be run with:

```sh
php modules/Base3Moodle/test/Adapter/MoodleUsermanagerSmokeTest.php
```

Complete native role, database, authentication and capability behavior still
requires verification within a live Moodle 5.3 installation.

## Deployment

`Base3MoodleLab` owns the project bootstrap members for `development`:
`Base3MoodleLab/src/Group/MoodleLabDevelopmentGroup.php`. Configure trusted,
**exact Moodle login names** in `BOOTSTRAP_USERNAMES` or appropriately verified
complete email addresses in `BOOTSTRAP_EMAILS` BEFORE enabling restricted
administration. In the provided `Base3MoodleLab` source, the bootstrap login list currently
contains the exact account name `admin` and the email list is empty. Verify
that this is the intended trusted account on each Moodle installation.
An email domain match alone never grants developer membership. Additional
members can subsequently be assigned under BASE3 > Groups, and do not remove
the bootstrap membership.

Run `base3/install.sh` after removing the legacy Moodle-specific editor files
to delete published obsolete authorization assets; the common ClientStack
screens provide their own styles and scripts. BASE3 does not
require another user/role schema or authorization database. Moodle deployments
remain responsible for PHP dependencies, the standard Moodle update cycle,
permissions, logging, backups, and privacy notices.

## Verification

From the BASE3 project source root:

```sh
php modules/Base3Moodle/test/Adapter/MoodleUsermanagerSmokeTest.php
php modules/Base3Moodle/test/Authorization/MoodleAuthorizationSmokeTest.php
php modules/Base3Moodle/test/Adapter/Base3MoodleFileManagerOutputSmokeTest.php
php modules/Base3MoodleLab/test/GlobalChatbotLauncherSmokeTest.php
```

Also verify site-administrator navigation, group/feature AJAX editing,
unauthorized direct display URLs, native course-context roles and PulseCore
visibility against a real Moodle 5.3 environment. These live integration
checks cannot be performed from an isolated PHP test harness.

See `PRIVACY.md` for data categories, retained host data, deletion boundaries
and deployment-specific validation requirements.
