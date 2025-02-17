=== UpdatePulse Server ===
Contributors: frogerme
Tags: Plugin updates, Theme updates, WordPress updates, License
Requires at least: 6.7
Tested up to: 6.7
Stable tag: 1.0
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Run your own update server for plugins, themes or any other software: manage packages & licenses, and provide updates to your users.

== Description ==

UpdatePulse Server allows developers to provide updates for software packages, including WordPress plugins and themes.

Some example use cases:

* provide updates for premium plugins or themes, with a license key
* provide custom theme or plugin updates to clients of a webdesign agency and not intended for the general public
* provide updates for a desktop software that integrates with UpdatePulse Server's update and license API

Packages may be either uploaded directly, or downloaded automatically from configured Version Control Systems, public or private.
Package updates may require a license ; both packages and licenses can be managed through an API or a user interface within UpdatePulse Server.

== Important notes ==

The target audience of this plugin is developers, not end-users.

Zip PHP extension is required.

For more information, available APIs, functions, actions and filters, see [the plugin's full documentation](https://github.com/anyape/updatepulse-server/blob/main/README.md).

Make sure to read the full documentation and the content of the "Help" tab under "UpdatePulse Server" settings before opening an issue or contacting the author.

== Overview ==

This plugin adds the following major features to WordPress:

* **Package management:** to manage update packages, showing a listing with Package Name, Version, Type, File Name, Size, Last Modified and License Status; includes bulk operations to delete and download, and the ability to delete all the packages.
* **Add Packages:** Upload update packages from a local machine to the server, or download them to the server from a Version Control System.
* **Version Control Systems:** Instead of manually uploading packages, use Version Control Systems to host packages, and download them to UpdatePulse Server automatically. Supports Bitbucket, Github and Gitlab, as well as self-hosted installations of Gitlab.
* **Cloud Storage**: Instead of storing packages on the file system where UpdatePulse Server is installed, they can be stored on a cloud storage service, as long as it is compatible with Amazon S3's API. Examples: Amazon S3, Cloudflare R2, Backblaze B2, MinIO, and many more!
* **UpdatePulse Server does not** install executable code from the Version Control System onto your installation of WordPress, and **does not** track your activity. It is designed to only store packages and licenses, and to provide updates when they are requested.
* **Licenses:** manage licenses with License Key, Registered Email, Status, Package Type, Package Slug, Creation Date, and Expiry Date; add and edit them with a form, or use the API for more control. Licenses prevent packages from being updated without a valid license. Licenses Keys are generated automatically by default and the values are unguessable (it is recommended to keep the default). When checking the validity of licenses, an extra license signature is also checked to prevent the use of a license on more than the configured allowed domains.
* **API:** UpdatePulse Server provides APIs to manage packages and licenses. The APIs keys are secured with a system of tokens: the API keys are never shared over the network, acquiring a token requires signed payloads, and the tokens have a limited lifetime. For more details about tokens and security, see [the Nonce API documentation](https://github.com/anyape/updatepulse-server/blob/main/docs/misc.md#nonce-api).

To connect their plugins or themes and UpdatePulse Server, developers can find integration examples in the [UpdatePulse Server Integration Examples](https://github.com/Anyape/updatepulse-server-integration) repository - theme and plugin examples rely heavily on the popular [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by [Yahnis Elsts](https://github.com/YahnisElsts).

== Screenshots ==

1. Packages Overview
2. Version Control Systems
3. Licenses
4. API & Webhooks
5. Help

== Installation ==

This section describes how to install the plugin and get it working.

1. Upload the plugin files to the `/wp-content/plugins/updatepulse-server` directory, or install the plugin through the WordPress plugins screen directly
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Edit plugin settings

== Changelog ==

= 1.0.1 =
* Minor readme updates
* Minor package API fixes
* Manual upload validation fix
* Cloud storage hooks fix

= 1.0 =
Major rewrite from the original WP Plugins Update Server - renamed to UpdatePulse Server, many new features, improvements and bugfixes. No upgrade path from WPPUS.