<?php if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
} ?>
<div class="wrap upserv-wrap">
	<?php echo $header ? wp_kses_post( $header ) : ''; ?>
	<?php if ( $options['use_vcs'] ) : ?>
	<div class="help-content">
		<h2><?php esc_html_e( 'Registering packages with a Remote Repository Service', 'updatepulse-server' ); ?></h2>
		<p>
			<?php esc_html_e( 'It is necessary to initialize packages linked to a Remote Repository for them to be available in UpdatePulse Server with one of the following methods:', 'updatepulse-server' ); ?>
		</p>
		<ul class="description">
			<li>
				<?php
					printf(
						// translators: %1$s is <strong>Register a package using a Remote Repository</strong>, %2$s is <a href="admin.php?page=upserv-page">Packages Overview</a>
						esc_html__( 'using the %1$s feature in %2$s', 'updatepulse-server' ),
						'<strong>' . esc_html__( 'Register a package using a Remote Repository', 'updatepulse-server' ) . '</strong>',
						'<a href="' . esc_url( admin_url( 'admin.php?page=upserv-page' ) ) . '">' . esc_html__( 'Packages Overview' ) . '</a>'
					);
				?>
			</li>
			<li>
				<?php
					printf(
						// translators: %s is <code>add</code>
						esc_html__( 'calling the %s method of the package API', 'updatepulse-server' ),
						'<code>add</code>'
					);
				?>
			</li>
			<li>
				<?php
					printf(
						// translators: %s is <code>wp updatepulse download_remote_package my-package plugin</code>
						esc_html__( 'calling %s in the command line', 'updatepulse-server' ),
						'<code>' . esc_html( 'wp updatepulse download_remote_package <package-slug> <plugin|theme|generic>' ) . '</code>'
					);
				?>
			</li>
			<li>
				<?php
					printf(
						// translators: %s is <code>upserv_download_remote_package( string $package_slug, string $type );</code>
						esc_html__( 'calling the %s method in your own code', 'updatepulse-server' ),
						'<code>upserv_download_remote_package( string $package_slug, string $type );</code>'
					);
				?>
			</li>
			<li>
				<?php
					esc_html_e( 'triggering a webhook from a Remote Repository' );
				?>
				<br>
				<?php
				printf(
					// translators: %1$s is the webhook URL, %2$s is <code>package-type</code>, %3$s is <code>plugin</code>, %4$s is <code>theme</code>, %5$s is <code>generic</code>, %6$s is <code>package-slug</code>
					esc_html__( 'Webhook URL: %1$s - where %2$s is the package type ( %3$s or %4$s or %5$s ) and %6$s is the slug of the package to register.', 'updatepulse-server' ),
					'<code>' . esc_url( home_url( '/updatepulse-server-webhook/package-type/package-slug' ) ) . '</code>',
					'<code>package-type</code>',
					'<code>plugin</code>',
					'<code>theme</code>',
					'<code>generic</code>',
					'<code>package-slug</code>'
				);
				?>
			</li>
		</ul>
	</div>
	<?php endif; ?>
	<div class="help-content">
		<h2><?php esc_html_e( 'Providing updates - packages requirements', 'updatepulse-server' ); ?></h2>
		<p>
			<?php esc_html_e( 'To link your packages to UpdatePulse Server, and optionally to prevent users from getting updates of your packages without a license, your packages need to include some extra code.', 'updatepulse-server' ); ?><br><br>
			<?php esc_html_e( 'For plugins, and themes, it is fairly straightforward:', 'updatepulse-server' ); ?>
		</p>
		<ul>
			<li>
			<?php
			printf(
				// translators: %1$s is <code>lib</code>, %2$s is <code>plugin-update-checker</code>, %3$s is <code>updatepulse-updater</code>, %4$s is <code>dummy-[plugin|theme]</code>
				esc_html__( 'Add a %1$s directory with the %2$s and %3$s libraries to the root of the package (provided in %4$s).', 'updatepulse-server' ),
				'<code>lib</code>',
				'<code>plugin-update-checker</code>',
				'<code>updatepulse-updater</code>',
				'<code>dummy-[plugin|theme]</code>',
			);
			?>
			</li>
			<li>
				<?php
				printf(
					// translators: %s is <code>functions.php</code>
					esc_html__( 'Add the following code to the main plugin file or to your theme\'s %s file:', 'updatepulse-server' ),
					'<code>functions.php</code>'
				);
				?>
				<br>
<pre>/** Enable updates - note the  `$prefix_updater` variable: change `prefix` to a unique string for your package.
 * Replace vX_X with the version of the UpdatePulse Updater you are using
 * @see /lib/updatepulse-updater/class-updatepulse-updater.php
 **/
use Anyape\UpdatePulse\Updater\vX_X\UpdatePulse_Updater;
require_once __DIR__ . '/lib/updatepulse-updater/class-updatepulse-updater.php';

$prefix_updater = new UpdatePulse_Updater(
	wp_normalize_path( __FILE__ ),
	0 === strpos( __DIR__, WP_PLUGIN_DIR ) ? wp_normalize_path( __DIR__ ) : get_stylesheet_directory()
);
</pre>
			</li>
			<li>
				<?php
				printf(
					// translators: %1s is <code>style.css</code>
					esc_html__( 'Optionally add headers to the main plugin file or to your theme\'s %s file to enable license checks:', 'updatepulse-server' ),
					'<code>style.css</code>'
				);
				?>
				<br>
				<pre>Require License: yes
Licensed With: another-plugin-or-theme-slug</pre><br>
				<?php
				printf(
					// translators: %1$s is <code>yes</code>, %2$s is <code>true</code>, %3$s is <code>1</code>
					esc_html__( 'The "Require License" header can be %1$s, %2$s, or %3$s: all other values are considered as false ; it is used to enable license checks for your package.', 'updatepulse-server' ),
					'<code>yes</code>',
					'<code>true</code>',
					'<code>1</code>'
				);
				?>
				<br>
				<?php
				printf(
					// translators: %s is <code>another-plugin-or-theme-slug</code>
					esc_html__( 'The "Licensed With" header is used to link packages together (for example, in the case of an extension to a main plugin the user already has a license for, if this header is present in the extension, the license check will be made against the main plugin). It must be the slug of another plugin or theme that is already present in your UpdatePulse Server.', 'updatepulse-server' ),
					'<code>another-plugin-or-theme-slug</code>'
				);
				?>
			</li>
			<li>
				<?php
				printf(
					// translators: %1$s is <code>updatepulse.json</code>, %2$s is <code>"server"</code>
					esc_html__( 'Add a %1$s file at the root of the package with the following content - change the value of %2$s to your own (required):', 'updatepulse-server' ),
					'<code>updatepulse.json</code>',
					'<code>"server"</code>'
				);
				?>
				<br>
				<pre>{
	"server": "https://server.domain.tld/"
}</pre>
				</li>
				<li>
				<?php esc_html_e( 'Connect UpdatePulse Server with your repository and prime your package, or manually upload your package to UpdatePulse Server.', 'updatepulse-server' ); ?>
			</li>
		</ul>
		<p>
			<?php
			esc_html_e( 'For generic packages, the steps involved entirely depend on the language used to write the package and the update process of the target platform.', 'updatepulse-server' );
			?>
			<br>
			<?php
			printf(
				// translators: %s is a link to the documentation
				esc_html__( 'You may refer to the documentation found %s.', 'updatepulse-server' ),
				'<a target="_blank" href="' . esc_url( 'https://github.com/anyape/updatepulse-server/blob/main/integration/docs/generic.md' ) . '">' . esc_html__( 'here', 'updatepulse-server' ) . '</a>'
			);
			?>
		</p>
		<hr>
		<p>
			<?php
			printf(
				// translators: %1$s is <code>integration/dummy-plugin</code>, %2$s is <code>integration/dummy-theme</code>
				esc_html__( 'See %1$s for an example of plugin, and %2$s for an example of theme. They are fully functionnal and can be used to test all the features of the server with a test client installation of WordPress.', 'updatepulse-server' ),
				'<code>' . esc_html( UPSERV_PLUGIN_PATH ) . 'integration/dummy-theme</code>',
				'<code>' . esc_html( UPSERV_PLUGIN_PATH ) . 'integration/dummy-plugin</code>',
			);
			?>
		</p>
		<p>
			<?php
			printf(
				// translators: %1$s is <code>integration/dummy-generic</code>, %2$s is `updatepulse-api.[sh|php|js|py]`
				esc_html__( 'See %1$s for examples of a generic package written in Bash, NodeJS, PHP with Curl, and Python. The API calls made by generic packages to the license API and Update API are the same as the WordPress packages. Unlike the upgrade library provided with plugins & themes, the code found in %2$s files is NOT ready for production environment and MUST be adapted.', 'updatepulse-server' ),
				'<code>' . esc_html( UPSERV_PLUGIN_PATH ) . 'integration/dummy-generic</code>',
				'<code>updatepulse-api.[sh|php|js|py]</code>'
			);
			?>
		</p>
		<p>
			<?php
			printf(
				// translators: %1$s is <code>packages_dir</code>, %2$s is <code>package-slug.zip</code>, %3$s is <code>package-slug.php</code>
				esc_html__( 'Unless "Use Remote Repository Service" is checked in "Remote Sources", you need to manually upload the packages zip archives (and subsequent updates) in %1$s. A package needs to be a valid generic package, or a valid WordPress plugin or theme package, and in the case of a plugin the main plugin file must have the same name as the zip archive. For example, the main plugin file in %2$s would be %3$s.', 'updatepulse-server' ),
				'<code>' . esc_html( $packages_dir ) . '</code>',
				'<code>package-slug.zip</code>',
				'<code>package-slug.php</code>',
			);
			?>
		</p>
		<hr>
		<h2><?php esc_html_e( 'Requests optimisation', 'updatepulse-server' ); ?></h2>
		<p>
			<?php
			printf(
				// translators: %s is <code>parse_request</code>
				esc_html__( "When the remote clients where your plugins, themes, or generic packages are installed send a request to check for updates, download a package or check or change license status, the current server's WordPress installation is loaded, with its own plugins and themes. This is not optimised if left untouched because unnecessary action and filter hooks that execute before %s action hook are also triggered, even though the request is not designed to produce any on-screen output or further computation.", 'updatepulse-server' ),
				'<code>parse_request</code>',
			);
			?>
		</p>
		<p>
			<?php
			printf(
				// translators: %1$s is <code>optimisation/upserv-endpoint-optimiser.php</code>, %2$s is the MU Plugin's path
				esc_html__( 'To solve this, the file %1$s has been automatically copied to %2$s. This effectively creates a Must Use Plugin running before everything else and preventing themes and other plugins from being executed when an update request or a license API request is received by UpdatePulse Server.', 'updatepulse-server' ),
				'<code>' . esc_html( UPSERV_PLUGIN_PATH . 'optimisation/upserv-endpoint-optimiser.php' ) . '</code>',
				'<code>' . esc_html( dirname( dirname( UPSERV_PLUGIN_PATH ) ) . '/mu-plugins/upserv-endpoint-optimiser.php' ) . '</code>',
			);
			?>
		</p>
		<p>
			<?php
			printf(
				// translators: %1$s is <code>$upserv_doing_update_api_request</code>, %2$s is <code>$upserv_doing_license_api_request</code>, %3$s is <code>$upserv_always_active_plugins</code>, %4$s is <code>functions.php</code>, %5$s is <code>$upserv_bypass_themes</code>, %5$s is <code>false</code>
				esc_html__( 'The MU Plugin also provides the global variable %1$s and %2$s that can be tested when adding hooks and filters would you choose to keep some plugins active with %3$s or keep %4$s from themes included with %5$s set to %6$s.', 'updatepulse-server' ),
				'<code>$upserv_doing_update_api_request</code>',
				'<code>$upserv_doing_license_api_request</code>',
				'<code>$upserv_always_active_plugins</code>',
				'<code>functions.php</code>',
				'<code>$upserv_bypass_themes</code>',
				'<code>false</code>',
			);
			?>
		</p>
		<hr>
		<h2><?php esc_html_e( 'More help...', 'updatepulse-server' ); ?></h2>
		<p>
			<?php
			printf(
				// translators: %s is a link to the documentation
				esc_html__( 'The full documentation can be found %s, with more details for developers on how to integrate UpdatePulse Server with their own plugins, themes, and generic packages.', 'updatepulse-server' ),
				'<a target="_blank" href="https://github.com/anyape/updatepulse-server/blob/master/README.md">' . esc_html__( 'here', 'updatepulse-server' ) . '</a>',
			);
			?>
		</p>
		<p>
			<?php
			printf(
				// translators: %1$s is a link to opening an issue, %2$s is a contact email
				esc_html__( 'After reading the documentation, for more help on how to use UpdatePulse Server, please %1$s - bugfixes are welcome via pull requests, detailed bug reports with accurate pointers as to where and how they occur in the code will be addressed in a timely manner, and a fee will apply for any other request (if they are addressed). If and only if you found a security issue, please contact %2$s with full details for responsible disclosure.', 'updatepulse-server' ),
				'<a target="_blank" href="https://github.com/anyape/updatepulse-server/issues">' . esc_html__( 'open an issue on Github', 'updatepulse-server' ) . '</a>',
				'<a href="mailto:updatepulse@anyape.come">updatepulse@anyape.com</a>',
			);
			?>
		</p>
	</div>
</div>
