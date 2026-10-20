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
