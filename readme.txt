=== Calico JetEngine MPC CPT Bridge ===
Contributors: calicodesignco
Tags: jetengine, mcp, custom-post-types, abilities-api
Requires at least: 6.9
Requires PHP: 7.4
Stable tag: 2.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Expose administrator-approved custom post types and fields through the WordPress Abilities API, with native JetEngine Advanced Date saving.

== Description ==
Administrators scan and approve the post types and fields available to MCP clients. Eight abilities support content reads, creation, updates and image metadata. Native WordPress capabilities and revision checks remain enforced.

JetEngine Advanced Date schedules are saved as one complete config through JetEngine's native provider. The bridge verifies the resulting timestamp rows before publication. Ordinary date-only fields retain their date-only convention. This protection applies to bridge abilities, not every third-party writer.

== Installation ==
1. Download calico-jetengine-mpc-cpt-bridge.zip from the GitHub release.
2. WordPress: Plugins > Add Plugin > Upload Plugin > Install Now > Activate.
3. Tools > Calico CPT Bridge: scan, approve only needed post types and fields, and save.
4. Grant the bridge abilities to the intended connector role and refresh tool discovery.
5. For Advanced Date, approve the base, FIELD__end_date and FIELD__config keys together.

== Frequently Asked Questions ==
= How do updates work? =
Version 2.6.0 checks public stable GitHub releases through WordPress Updates. Earlier versions require one manual upload to bootstrap the updater. No token is bundled or needed. Automatic updates are not forced.

= Does it require JetEngine? =
Ordinary approved post-meta fields can be used without JetEngine. Native Advanced Date saving requires the active JetEngine provider. Custom database tables and relations are outside this plugin's scope.

= Can it publish events? =
Only an authorized WordPress user with the native publishing capability can request publication. A recognized inconsistent Advanced Date schedule stops publication through the bridge. Clients should still verify the saved complete schedule.

== Changelog ==
= 2.6.0 =
* Add GitHub-backed WordPress updates with stable version-pinned packages.
* Preserve field allowlists, existing ability names and Advanced Date safeguards.

= 2.5.0 =
* Save Advanced Date schedules through the native JetEngine provider.
* Verify schedules, block inconsistent publication, and recover affected metadata after schedule failures.

== Upgrade Notice ==
= 2.6.0 =
Upload once manually from versions 2.5.0 and earlier to enable future updates through WordPress.
