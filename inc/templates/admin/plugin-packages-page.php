<?php if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
} ?>
<div class="wrap upserv-wrap">
	<?php echo $header ? wp_kses_post( $header ) : ''; ?>
	<form autocomplete="off" id="upserv-packages-list" action="" method="post">
		<h3><?php esc_html_e( 'Packages', 'updatepulse-server' ); ?></h3>
		<?php $packages_table->search_box( 'Search', 'updatepulse-server' ); ?>
		<?php $packages_table->display(); ?>
		<?php if ( get_option( 'upserv_use_remote_repository' ) ) : ?>
		<ul class="description">
			<li>
				<?php esc_html_e( 'It is necessary to initialize packages linked to a Remote Repository for them to appear in this list, with one of the following methods:', 'updatepulse-server' ); ?>
				<ul>
					<li>
						<?php esc_html_e( 'using the "Prime a package using a Remote Repository" below', 'updatepulse-server' ); ?>
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
				</ul>
			</li>
			<li>
				<?php esc_html_e( 'If packages linked to a Remote Repository are deleted using this interface, they need to be re-initialized to appear in this list.', 'updatepulse-server' ); ?>
			</li>
		</ul>
		<?php endif; ?>
	</form>
	<br>
	<hr>
	<?php do_action( 'upserv_template_package_manager_option_before_add_packages' ); ?>
	<h3><?php esc_html_e( 'Add Packages', 'updatepulse-server' ); ?></h3>
	<table class="form-table upserv-add-packages">
		<?php if ( get_option( 'upserv_use_remote_repository' ) ) : ?>
		<tr>
			<th>
				<label for="upserv_prime_package_slug"><?php esc_html_e( 'Prime a package using a Remote Repository (recommended)', 'updatepulse-server' ); ?></label>
			</th>
			<td>
				<input class="regular-text" type="text" id="upserv_prime_package_slug" placeholder="<?php esc_attr_e( 'package-slug' ); ?>" name="upserv_prime_package_slug" value=""> <input type="button" id="upserv_prime_package_trigger" value="<?php print esc_attr_e( 'Get remote package', 'updatepulse-server' ); ?>" class="button button-primary" disabled /><div class="spinner"></div>
				<p class="description">
					<?php
					printf(
						// translators: %s is <code>$packages_dir</code>
						esc_html__( 'Get an archive of a package from a Remote Repository and put it in the %s directory by entering the package slug.', 'updatepulse-server' ),
						'<code>' . esc_html( $packages_dir ) . '</code>',
					);
					?>
					<?php
					printf(
						// translators: %s is <code>package-slug</code>
						esc_html__( 'The repository name should be %s and all the files should be located at the root of the repository.', 'updatepulse-server' ),
						'<code>package-slug</code>',
					);
					?>
					<br>
					<?php
					printf(
						// translators: %1$s is <code>package-slug</code>, %2$s is <code>package-slug.php</code>
						esc_html__( 'In the case of a plugin, the main plugin file must have the same name as the repository name - for example, the main plugin file in %1$s repository would be %2$s.', 'updatepulse-server' ),
						'<code>package-slug</code>',
						'<code>package-slug.php</code>',
					);
					?>
					<br>
					<?php esc_html_e( 'Using this method adds the package to the list if not present or forcefully downloads its latest version from the Remote Repository and overwrites the existing package.', 'updatepulse-server' ); ?>
					<br>
					<?php esc_html_e( 'Note: packages will be overwritten automatically and regularly with their counterpart from the Remote Repository if a newer version exists.', 'updatepulse-server' ); ?>
				</p>
			</td>
		</tr>
		<?php endif; ?>
		<tr id="upserv_manual_package_upload_dropzone">
			<th>
				<label for="upserv_manual_package_upload"><?php esc_html_e( 'Upload a package', 'updatepulse-server' ); ?>
				<?php if ( get_option( 'upserv_use_remote_repository' ) ) : ?>
					<?php esc_html_e( ' (discouraged)', 'updatepulse-server' ); ?>
				<?php endif; ?>
				</label>
			</th>
			<td>
				<input class="input-file hidden" type="file" id="upserv_manual_package_upload" name="upserv_manual_package_upload" value=""><label for="upserv_manual_package_upload" class="button"><?php esc_html_e( 'Choose package archive', 'updatepulse-server' ); ?></label> <input type="text" id="upserv_manual_package_upload_filename" placeholder="package-slug.zip" value="" disabled> <input type="button" value="<?php print esc_attr_e( 'Upload package', 'updatepulse-server' ); ?>" class="button button-primary manual-package-upload-trigger" id="upserv_manual_package_upload_trigger" disabled /><div class="spinner"></div>
				<p class="description">
					<?php
					printf(
						// translators: %s is <code>$packages_dir</code>
						esc_html__( 'Add a package zip archive to the %s directory. The archive needs to be a valid generic package, or a valid WordPress plugin or theme package.', 'updatepulse-server' ),
						'<code>' . esc_html( $packages_dir ) . '</code>',
					);
					?>
					<br>
					<?php
					printf(
						// translators: %1$s is <code>package-slug.zip</code>, %2$s is <code>package-slug.php</code>
						esc_html__( 'In the case of a plugin, the main plugin file must have the same name as the zip archive - for example, the main plugin file in %1$s would be %2$s.', 'updatepulse-server' ),
						'<code>package-slug.zip</code>',
						'<code>package-slug.php</code>',
					);
					?>
					<br>
					<?php esc_html_e( 'Using this method adds the package to the list if not present or overwrites the existing package.', 'updatepulse-server' ); ?>
					<?php if ( get_option( 'upserv_use_remote_repository' ) ) : ?>
					<br>
						<?php esc_html_e( 'Note: a manually uploaded package that does not have its counterpart in a Remote Repository will need to be uploaded manually for each new release to provide updates.', 'updatepulse-server' ); ?>
					<?php endif; ?>
				</p>
			</td>
		</tr>
	</table>
	<hr>
	<form autocomplete="off" action="" method="post">
		<?php do_action( 'upserv_template_package_manager_option_before_miscellaneous' ); ?>
		<h3><?php esc_html_e( 'Miscellaneous', 'updatepulse-server' ); ?></h3>
		<table class="form-table general-options">
			<tr>
				<th>
					<label for="upserv_archive_max_size"><?php esc_html_e( 'Archive max size (in MB)', 'updatepulse-server' ); ?></label>
				</th>
				<td>
					<input class="regular-text" type="number" id="upserv_archive_max_size" name="upserv_archive_max_size" value="<?php echo esc_attr( get_option( 'upserv_archive_max_size', $default_archive_size ) ); ?>">
					<p class="description">
						<?php esc_html_e( 'Maximum file size when uploading or downloading packages.', 'updatepulse-server' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th>
					<label for="upserv_cache_max_size"><?php esc_html_e( 'Cache max size (in MB)', 'updatepulse-server' ); ?></label>
				</th>
				<td>
					<input class="regular-text" type="number" id="upserv_cache_max_size" name="upserv_cache_max_size" value="<?php echo esc_attr( get_option( 'upserv_cache_max_size', $default_cache_size ) ); ?>"> <input type="button" value="<?php print esc_attr_e( 'Force Clean', 'updatepulse-server' ); ?> (<?php print esc_attr( $cache_size ); ?>)" class="button ajax-trigger" data-action="force_clean" data-type="cache" />
					<p class="description">
						<?php
						printf(
							// translators: %s is <code>cache_dir_path</code>
							esc_html__( 'Maximum size in MB for the %s directory. If the size of the directory grows larger, its content will be deleted at next cron run (checked hourly). The size indicated in the "Force Clean" button is the real current size.', 'updatepulse-server' ),
							'<code>' . esc_html( upserv_get_cache_data_dir() ) . '</code>'
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th>
					<label for="upserv_logs_max_size"><?php esc_html_e( 'Logs max size (in MB)', 'updatepulse-server' ); ?></label>
				</th>
				<td>
					<input class="regular-text" type="number" id="upserv_logs_max_size" name="upserv_logs_max_size" value="<?php echo esc_attr( get_option( 'upserv_logs_max_size', $default_logs_size ) ); ?>"> <input type="button" value="<?php print esc_attr_e( 'Force Clean', 'updatepulse-server' ); ?> (<?php print esc_attr( $logs_size ); ?>)" class="button ajax-trigger" data-action="force_clean" data-type="logs" />
					<p class="description">
						<?php
						printf(
							// translators: %s is <code>logs_dir_path</code>
							esc_html__( 'Maximum size in MB for the %s directory. If the size of the directory grows larger, its content will be deleted at next cron run (checked hourly). The size indicated in the "Force Clean" button is the real current size.', 'updatepulse-server' ),
							'<code>' . esc_html( upserv_get_logs_data_dir() ) . '</code>'
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<input type="hidden" name="upserv_settings_section" value="general-options">
		<?php wp_nonce_field( 'upserv_plugin_options', 'upserv_plugin_options_handler_nonce' ); ?>
		<p class="submit">
			<input type="submit" name="upserv_options_save" value="<?php esc_attr_e( 'Save', 'updatepulse-server' ); ?>" class="button button-primary" />
		</p>
	</form>
</div>
