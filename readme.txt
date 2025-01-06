=== UpdatePulse Server ===
Contributors: frogerme
Tags: plugins, themes, updates, license
Requires at least: 4.9.5
Tested up to: 6.3
Stable tag: trunk
Requires PHP: 7.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Run your own update server for plugins and themes.

== Description ==

UpdatePulse Server allows developers to provide updates for plugins and themes packages not hosted on wordpress.org. It is useful to provide updates for plugins or themes not compliant with the GPLv2 (or later).
Packages may be either uploaded directly, or hosted in a Remote Repository, public or private. It supports Bitbucket, Github and Gitlab, as well as self-hosted installations of Gitlab.
Package updates may require a license, and licenses can be managed through an API or a user interface within UpdatePulse Server.

== Important notes ==

This plugin is for developers only.

Zip PHP extension is required (use ZipArchive, no fallback to PclZip).

For more information, available APIs, functions, actions and filters, see [the plugin's full documentation](https://github.com/anyape/updatepulse-server/blob/main/README.md).

Make sure to read the full documentation and the content of the "Help" tab under "UpdatePulse Server" settings before opening an issue or contacting the author.

== Overview ==

This plugin adds the following major features to WordPress:

* **UpdatePulse Server admin page:** to manage the list of packages and configure the plugin.
* **Package management:** to manage update packages, showing a listing with Package Name, Version, Type, File Name, Size, Last Modified and License Status ; includes bulk operations to delete, download and change the license status, and the ability to delete all the packages.
* **Add Packages:** Upload update packages from a local machine to the server, or download them to the server from a Remote Repository.
* **General settings:** for archive files download size, cache, and logs, with force clean.
* **Packages licensing:** Prevent plugins and themes installed on remote WordPress installation from being updated without a valid license. Licenses are generated automatically by default and the values are unguessable (it is recommended to keep the default). When checking the validity of licenses an extra license signature is also checked to prevent the use of a license on more than the configured allowed domains.
* **Packages remote source:** UpdatePulse Server can act as a proxy and will help you to connect your clients with your plugins and themes kept on a Remote Repository, so that they are always up to date. Supports Bitbucket, Github and Gitlab, as well as self-hosted installations of Gitlab. Packages will not be installed on your server, only transferred to the clients whenever they request them.

To connect their plugins or themes and UpdatePulse Server, developers can find integration examples in the `updatepulse-server/integration` directory.

In addition, a [Must Use Plugin](https://codex.wordpress.org/Must_Use_Plugins) developers can add to the WordPress installation running UpdatePulse Server is available in `updatepulse-server/optimisation/upserv-endpoint-optimizer.php`.

== Special Thanks ==

A warm thank you to [Yahnis Elsts](https://github.com/YahnisElsts), the author of [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) and [WP Update Server](https://github.com/YahnisElsts/wp-update-server) libraries, without whom the creation of this plugin would not have been possible.
Authorisation to use these libraries freely provided relevant licenses are included has been graciously granted [here](https://github.com/YahnisElsts/wp-update-server/issues/37#issuecomment-386814776).

== Installation ==

This section describes how to install the plugin and get it working.

1. Upload the plugin files to the `/wp-content/plugins/updatepulse-server` directory, or install the plugin through the WordPress plugins screen directly
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Edit plugin settings

== Changelog ==

= 2.0 =
Major update from WPPUS - renamed to UpdatePulse Server, many new features, improvements and bugfixes. No upgrade path from WPPUS.