<?php if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
} ?>
<div class="wrap upserv-wrap">
	<?php UPServ::get_instance()->display_settings_header( $result ); ?>
	<form autocomplete="off" action="" method="post">
		<table class="form-table package-source">
			<tr>
				<th>
					<label for="upserv_use_remote_repository"><?php esc_html_e( 'Use a Remote Repository Service', 'updatepulse-server' ); ?></label>
				</th>
				<td>
					<input type="checkbox" id="upserv_use_remote_repository" name="upserv_use_remote_repository" value="1" <?php checked( get_option( 'upserv_use_remote_repository', 0 ), 1 ); ?>>
					<p class="description">
						<?php esc_html_e( 'Enables this server to download plugins, themes and generic packages from a Remote Repository before delivering updates.', 'updatepulse-server' ); ?>
						<br>
						<?php esc_html_e( 'Supports Bitbucket, Github and Gitlab.', 'updatepulse-server' ); ?>
						<br>
						<?php
						printf(
							// translators: %s is the path where zip packages need to be uploaded
							esc_html__( 'If left unchecked, zip packages need to be manually uploaded to %s.', 'updatepulse-server' ),
							'<code>' . esc_html( $packages_dir ) . '</code>'
						);
						?>
						<br>
						<strong><?php esc_html_e( 'It affects all the packages delivered by this installation of UpdatePulse Server if they have a corresponding repository in the Remote Repository Service.', 'updatepulse-server' ); ?></strong>
						<br>
						<strong><?php esc_html_e( 'Settings of the "Remote Sources" section will be saved only if this option is checked.', 'updatepulse-server' ); ?></strong>
					</p>
				</td>
			</tr>
			<tr>
				<th>
					<label for="upserv_remote_repository_url"><?php esc_html_e( 'Remote Repository Service URL', 'updatepulse-server' ); ?></label>
				</th>
				<td>
					<input class="regular-text remote-repository-setting" type="text" id="upserv_remote_repository_url" name="upserv_remote_repository_url" value="<?php echo esc_attr( get_option( 'upserv_remote_repository_url' ) ); ?>">
					<p class="description">
						<?php esc_html_e( 'The URL of the Remote Repository Service where packages are hosted.', 'updatepulse-server' ); ?>
						<br>
						<?php
						printf(
							// translators: %1$s is <code>https://repository-service.tld/identifier/</code>, %2$s is <code>identifier</code>, %3$s is <code>https://repository-service.tld</code>
							esc_html__( 'Must follow the following pattern: %1$s where %2$s is the user or the organisation name in case of Github, is the user name in case of BitBucket, is a group in case of Gitlab (no support for Gitlab subgroups), and where %3$s may be a self-hosted instance of Gitlab.', 'updatepulse-server' ),
							'<code>https://repository-service.tld/identifier/</code>',
							'<code>identifier</code>',
							'<code>https://repository-service.tld</code>'
						);
						?>
						<br>
						<?php
						printf(
							// translators: %1$s is <code>https://repository-service.tld/identifier/package-slug/</code>, %2$s is <code>identifier</code>
							esc_html__( 'Each package repository URL must follow the following pattern: %1$s ; the package files must be located at the root of the repository, and in the case of WordPress plugins the main plugin file must follow the pattern %2$s.', 'updatepulse-server' ),
							'<code>https://repository-service.tld/identifier/package-slug/</code>',
							'<code>package-slug.php</code>',
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th>
					<label for="upserv_remote_repository_self_hosted"><?php esc_html_e( 'Self-hosted Remote Repository Service', 'updatepulse-server' ); ?></label>
				</th>
				<td>
					<input class="remote-repository-setting" type="checkbox" id="upserv_remote_repository_self_hosted" name="upserv_remote_repository_self_hosted" value="1" <?php checked( get_option( 'upserv_remote_repository_self_hosted', 0 ), 1 ); ?>>
					<p class="description">
						<?php esc_html_e( 'Check this only if the Remote Repository Service is a self-hosted instance of Gitlab.', 'updatepulse-server' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th>
					<label for="upserv_remote_repository_branch"><?php esc_html_e( 'Packages Branch Name', 'updatepulse-server' ); ?></label>
				</th>
				<td>
					<input class="remote-repository-setting regular-text" type="text" id="upserv_remote_repository_branch" name="upserv_remote_repository_branch" value="<?php echo esc_attr( get_option( 'upserv_remote_repository_branch', 'main' ) ); ?>">
					<p class="description">
						<?php esc_html_e( 'The branch to download when getting remote packages from the Remote Repository Service.', 'updatepulse-server' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th>
					<label for="upserv_remote_repository_credentials"><?php esc_html_e( 'Remote Repository Service Credentials', 'updatepulse-server' ); ?></label>
				</th>
				<td>
					<input class="remote-repository-setting regular-text secret" type="password" autocomplete="new-password" id="upserv_remote_repository_credentials" name="upserv_remote_repository_credentials" value="<?php echo esc_attr( get_option( 'upserv_remote_repository_credentials' ) ); ?>">
					<p class="description">
						<?php esc_html_e( 'Credentials for non-publicly accessible repositories.', 'updatepulse-server' ); ?>
						<br>
						<?php
						printf(
							// translators: %s is <code>token</code>
							esc_html__( 'In the case of Github and Gitlab, an access token (%s).', 'updatepulse-server' ),
							'<code>token</code>'
						);
						?>
						<br>
						<?php
						printf(
							// translators: %s is <code>consumer_key|consumer_secret</code>
							esc_html__( 'In the case of Bitbucket, the Consumer key and secret separated by a pipe (%s).', 'updatepulse-server' ),
							'<code>consumer_key|consumer_secret</code>'
						);
						?>
						<br>
						<?php esc_html_e( 'IMPORTANT: when creating the consumer, "This is a private consumer" must be checked.', 'updatepulse-server' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th>
					<label for="upserv_remote_repository_filter_packages"><?php esc_html_e( 'Filter Packages', 'updatepulse-server' ); ?></label>
				</th>
				<td>
					<input type="checkbox" id="upserv_remote_repository_filter_packages" name="upserv_remote_repository_filter_packages" value="1" <?php checked( get_option( 'upserv_remote_repository_filter_packages' ), 1 ); ?>>
					<p class="description">
						<?php esc_html_e( 'Check this if you wish to filter the packages to download from the Remote Repository Service so that only packages explicitly associated with this server are downloaded.', 'updatepulse-server' ); ?>
						<br/>
						<?php
						printf(
							// translators: %1$s is <code>updatepulse.json</code>, %2$s is <code>server</code>, %3$s is <code>https://sub.domain.tld/</code>
							esc_html__( 'When checked, UpdatePulse Server will only download packages that have a file named %1$s in the root of the repository, with the %2$s value set to %3$s.', 'updatepulse-server' ),
							'<code>' . esc_html( apply_filters( 'upserv_filter_packages_flag_file', 'updatepulse.json' ) ) . '</code>',
							'<code>server</code>',
							'<code>' . esc_url( trailingslashit( home_url() ) ) . '</code>',
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th>
					<label for="upserv_remote_repository_test"><?php esc_html_e( 'Test Remote Repository Access', 'updatepulse-server' ); ?></label>
				</th>
				<td>
					<input type="button" value="<?php print esc_attr_e( 'Test Now', 'updatepulse-server' ); ?>" id="upserv_remote_repository_test" class="button ajax-trigger" data-selector=".remote-repository-setting" data-action="remote_repository_test" data-type="none" />
					<p class="description">
						<?php esc_html_e( 'Send a test request to the Remote Repository Service.', 'updatepulse-server' ); ?>
						<br/>
						<?php esc_html_e( 'The request checks whether the service is reachable and if the request can be authenticated.', 'updatepulse-server' ); ?>
						<br/>
						<strong><?php esc_html_e( 'Tests are not supported for Bitbucket ; if you use Bitbucket, save your settings & try to prime a package in the "Packages Overview" tab.', 'updatepulse-server' ); ?></strong>
					</p>
				</td>
			</tr>
			<?php do_action( 'upserv_template_remote_source_manager_option_before_recurring_check' ); ?>
			<tr class="check-frequency <?php echo ( $hide_check_frequency ) ? 'hidden' : ''; ?>">
				<th>
					<label for="upserv_remote_repository_check_frequency"><?php esc_html_e( 'Remote update check frequency', 'updatepulse-server' ); ?></label>
				</th>
				<td>
					<select name="upserv_remote_repository_check_frequency" id="upserv_remote_repository_check_frequency">
						<?php foreach ( $schedules as $display => $schedule ) : ?>
							<option value="<?php echo esc_attr( $schedule['slug'] ); ?>" <?php selected( get_option( 'upserv_remote_repository_check_frequency', 'daily' ), $schedule['slug'] ); ?>><?php echo esc_html( $display ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php esc_html_e( 'How often UpdatePulse Server will poll each Remote Repository for package updates - checking too often may slow down the server (recommended "Once Daily").', 'updatepulse-server' ); ?>
					</p>
				</td>
			</tr>
			<?php do_action( 'upserv_template_remote_source_manager_option_after_recurring_check' ); ?>
		</table>
		<input type="hidden" name="upserv_settings_section" value="package-source">
		<?php wp_nonce_field( 'upserv_plugin_options', 'upserv_plugin_options_handler_nonce' ); ?>
		<p class="submit">
			<input type="submit" name="upserv_options_save" value="<?php esc_attr_e( 'Save', 'updatepulse-server' ); ?>" class="button button-primary" />
		</p>
		<?php if ( get_option( 'upserv_use_remote_repository' ) ) : ?>
		<hr>
		<table class="form-table package-source check-frequency <?php echo ( $hide_check_frequency ) ? 'hidden' : ''; ?>">
			<tr>
				<th>
					<label for="upserv_remote_repository_force_remove_schedules"><?php esc_html_e( 'Clear & reschedule remote updates', 'updatepulse-server' ); ?></label>
				</th>
				<td>
					<input type="button" value="<?php print esc_attr_e( 'Force Clear & Reschedule', 'updatepulse-server' ); ?>" id="upserv_remote_repository_force_remove_schedules" class="button ajax-trigger" data-action="force_clean" data-type="schedules" />
					<p class="description">
						<?php esc_html_e( 'Clears & Reschedules remote updates from the repository service for all the packages.', 'updatepulse-server' ); ?>
						<br/>
						<?php esc_html_e( 'WARNING: after rescheduling remote updates, an action will be scheduled for all the packages, including those uploaded manually and without a corresponding Remote Repository.', 'updatepulse-server' ); ?>
						<br/>
						<?php esc_html_e( 'Make sure either all packages have a corresponding Remote Repository in the repository service, or to use the delete operation and re-upload the packages that were previously manually uploaded to clear the useless scheduled actions.', 'updatepulse-server' ); ?>
						<br/>
						<?php esc_html_e( 'If there are useless scheduled actions left, they will not trigger any error, but the server will query the repository service needlessly.', 'updatepulse-server' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php endif; ?>
	</form>
</div>
