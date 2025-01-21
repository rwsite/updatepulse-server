<?php if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
} ?>
<div class="wrap upserv-wrap">
	<?php echo $header ? wp_kses_post( $header ) : ''; ?>
	<form autocomplete="off" action="" method="post">
		<input type="hidden" class="repositories-values" id="upserv_repositories" name="upserv_repositories" value="<?php echo esc_attr( $options['repositories'] ); ?>">
		<table class="form-table package-source switch">
			<tr>
				<th>
					<label for="upserv_use_remote_repository"><?php esc_html_e( 'Use a Remote Repository Service', 'updatepulse-server' ); ?></label>
				</th>
				<td>
					<input type="checkbox" id="upserv_use_remote_repository" name="upserv_use_remote_repository" value="1" <?php checked( $options['use_remote_repositories'], 1 ); ?>>
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
						<strong>
							<?php
							printf(
								// translators: %s <a href="admin.php?page=upserv-page-help">initialize packages</a>
								esc_html__( 'It is necessary to %s linked to a Remote Repository for them to be available in UpdatePulse Server.', 'updatepulse-server' ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=upserv-page-help' ) ) . '">' . esc_html__( 'register packages', 'updatepulse-server' ) . '</a>'
							);
							?>
						</strong>
					</p>
				</td>
			</tr>
		</table>
		<div class="repositories" id="upserv_vcs_list">
			<div class="item template upserv-modal-open-handle" data-modal_id="upserv_modal_add_remote_source">
				<div class="placeholder">
					<span class="icon">+</span>
					<div><?php esc_html_e( 'Add a Remote Repository', 'updatepulse-server' ); ?></div>
				</div>
				<div class="service hidden">
					<span class="github hidden"><i class="fa-brands fa-github"></i><?php esc_html_e( 'Github', 'updatepulse-server' ); ?></span>
					<span class="bitbucket hidden"><i class="fa-brands fa-bitbucket"></i><?php esc_html_e( 'Bitbucket', 'updatepulse-server' ); ?></span>
					<span class="gitlab hidden"><i class="fa-brands fa-gitlab"></i><?php esc_html_e( 'Gitlab', 'updatepulse-server' ); ?></span>
					<span class="self-hosted hidden"><i class="fa-brands fa-square-gitlab"></i><?php esc_html_e( 'Self-hosted', 'updatepulse-server' ); ?></span>
				</div>
				<code class="url hidden"></code>
				<code class="branch-name hidden"></code>
			</div>
		</div>
		<div class="form-container package-source">
			<table class="form-table form-settings">
				<tr>
					<th>
						<label for="upserv_remote_repository_url"><?php esc_html_e( 'Remote Repository Service URL', 'updatepulse-server' ); ?></label>
					</th>
					<td>
						<input class="regular-text remote-repository-setting" type="text" id="upserv_remote_repository_url" data-prop="url" name="upserv_remote_repository_url" value=""> <button class="button remove" id="upserv_remove_remote_repository"><?php esc_html_e( 'Remove', 'updatepulse-server' ); ?></button>
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
								esc_html__( 'Each package repository URL must follow the following pattern: %1$s; the package files must be located at the root of the repository, and in the case of WordPress plugins the main plugin file must follow the pattern %2$s.', 'updatepulse-server' ),
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
						<input class="remote-repository-setting" type="checkbox" id="upserv_remote_repository_self_hosted" data-prop="self_hosted" name="upserv_remote_repository_self_hosted" value="1">
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
						<input class="remote-repository-setting regular-text" type="text" id="upserv_remote_repository_branch" data-prop="branch" name="upserv_remote_repository_branch" value="">
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
						<input class="remote-repository-setting regular-text secret" type="password" autocomplete="new-password" id="upserv_remote_repository_credentials" data-prop="credentials" name="upserv_remote_repository_credentials" value="">
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
						<input type="checkbox" id="upserv_remote_repository_filter_packages" data-prop="filter_packages" name="upserv_remote_repository_filter_packages" value="1">
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
							<strong><?php esc_html_e( 'Tests are not supported for Bitbucket; if you use Bitbucket, save your settings & try to prime a package in the "Packages Overview" tab.', 'updatepulse-server' ); ?></strong>
						</p>
					</td>
				</tr>
				<tr>
					<th>
						<label for="upserv_remote_repository_use_webhooks"><?php esc_html_e( 'Use Webhooks', 'updatepulse-server' ); ?></label>
					</th>
					<td>
						<input type="checkbox" id="upserv_remote_repository_use_webhooks" data-prop="use_webhooks" name="upserv_remote_repository_use_webhooks" value="1">
						<p class="description">
							<?php esc_html_e( 'Check this if you wish for each repository of the Remote Repository Service to call a Webhook when updates are pushed.', 'updatepulse-server' ); ?><br>
							<?php esc_html_e( 'When checked, UpdatePulse Server will not regularly poll repositories for package version changes, but relies on events sent by the repositories to schedule a package download.', 'updatepulse-server' ); ?>
							<br/>
							<?php
							printf(
								// translators: %1$s is the webhook URL, %2$s is <code>package-type</code>, %3$s is <code>plugin</code>, %4$s is <code>theme</code>, %5$s is <code>generic</code>, %6$s is <code>package-slug</code>
								esc_html__( 'Webhook URL: %1$s - where %2$s is the package type ( %3$s or %4$s or %5$s ) and %6$s is the slug of the package needing updates.', 'updatepulse-server' ),
								'<code>' . esc_url( home_url( '/updatepulse-server-webhook/package-type/package-slug' ) ) . '</code>',
								'<code>package-type</code>',
								'<code>plugin</code>',
								'<code>theme</code>',
								'<code>generic</code>',
								'<code>package-slug</code>'
							);
							?>
							<br>
							<?php esc_html_e( 'Note that UpdatePulse Server does not rely on the content of the payload to schedule a package download, so any type of event can be used to trigger the Webhook.', 'updatepulse-server' ); ?>
						</p>
					</td>
				</tr>
				<tr class="webhooks">
					<th>
						<label for="upserv_remote_repository_check_delay"><?php esc_html_e( 'Remote Download Delay', 'updatepulse-server' ); ?></label>
					</th>
					<td>
						<input type="number" min="0" id="upserv_remote_repository_check_delay" data-prop="check_delay" name="upserv_remote_repository_check_delay" value="">
						<p class="description">
							<?php esc_html_e( 'Delay in minutes after which UpdatePulse Server will poll the Remote Repository for package updates when the Webhook has been called.', 'updatepulse-server' ); ?><br>
							<?php esc_html_e( 'Leave at 0 to schedule a package update during the cron run happening immediately after the Webhook was called.', 'updatepulse-server' ); ?>
						</p>
					</td>
				</tr>
				<tr class="webhooks">
					<th>
						<label for="upserv_remote_repository_webhook_secret"><?php esc_html_e( 'Remote Repository Webhook Secret', 'updatepulse-server' ); ?></label>
					</th>
					<td>
						<input class="regular-text secret" type="password" autocomplete="new-password" id="upserv_remote_repository_webhook_secret" data-prop="webhook_secret" name="upserv_remote_repository_webhook_secret" value="">
						<p class="description">
							<?php esc_html_e( 'Preferably a random string, the secret string included in the request by the repository service when calling the Webhook.', 'updatepulse-server' ); ?>
							<br>
							<strong><?php esc_html_e( 'WARNING: Changing this value will invalidate all the existing Webhooks set up on all package repositories.', 'updatepulse-server' ); ?></strong>
							<br>
							<?php esc_html_e( 'After changing this setting, make sure to update the Webhooks secrets in the repository service.', 'updatepulse-server' ); ?></strong>
						</p>
					</td>
				</tr>
				<tr class="check-frequency">
					<th>
						<label for="upserv_remote_repository_check_frequency"><?php esc_html_e( 'Remote update check frequency', 'updatepulse-server' ); ?></label>
					</th>
					<td>
						<select name="upserv_remote_repository_check_frequency" id="upserv_remote_repository_check_frequency" data-prop="check_frequency">
							<?php foreach ( $schedules as $display => $schedule ) : ?>
								<option value="<?php echo esc_attr( $schedule['slug'] ); ?>"><?php echo esc_html( $display ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'How often UpdatePulse Server will poll each Remote Repository for package updates - checking too often may slow down the server (recommended "Once Daily").', 'updatepulse-server' ); ?>
						</p>
					</td>
				</tr>
				<?php do_action( 'upserv_template_remote_source_manager_option_after_recurring_check' ); ?>
			</table>
			<table class="form-table check-frequency">
				<tr>
					<th>
						<label for="upserv_remote_repository_force_remove_schedules"><?php esc_html_e( 'Clear & reschedule remote updates', 'updatepulse-server' ); ?></label>
					</th>
					<td>
						<input type="button" value="<?php print esc_attr_e( 'Force Clear & Reschedule', 'updatepulse-server' ); ?>" id="upserv_remote_repository_force_remove_schedules" class="button ajax-trigger" data-action="force_clean" data-type="schedules" data-selector="#upserv_vcs_list" />
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
		</div>
		<input type="hidden" name="upserv_settings_section" value="package-source">
		<?php wp_nonce_field( 'upserv_plugin_options', 'upserv_plugin_options_handler_nonce' ); ?>
		<p class="submit">
			<input type="submit" name="upserv_options_save" value="<?php esc_attr_e( 'Save', 'updatepulse-server' ); ?>" class="button button-primary" />
		</p>
	</form>
</div>
<div id="upserv_modal_add_remote_source" class='upserv-modal upserv-modal-remote-sources hidden'>
	<div class='upserv-modal-content'>
		<div class='upserv-modal-header'>
			<span class='upserv-modal-close'>&times;</span>
			<h2><?php esc_html_e( 'Add a Remote Source', 'upserv' ); ?><span></span></h2>
		</div>
		<div class='upserv-modal-body'>
			<table class="form-table form-settings">
				<tr>
					<th>
						<label for="upserv_add_remote_repository_url"><?php esc_html_e( 'Remote Repository Service URL', 'updatepulse-server' ); ?></label>
					</th>
					<td>
						<input class="regular-text remote-repository-setting" type="text" id="upserv_add_remote_repository_url" data-prop="url" name="upserv_add_remote_repository_url" value="">
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
								esc_html__( 'Each package repository URL must follow the following pattern: %1$s; the package files must be located at the root of the repository, and in the case of WordPress plugins the main plugin file must follow the pattern %2$s.', 'updatepulse-server' ),
								'<code>https://repository-service.tld/identifier/package-slug/</code>',
								'<code>package-slug.php</code>',
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th>
						<label for="upserv_add_remote_repository_branch"><?php esc_html_e( 'Packages Branch Name', 'updatepulse-server' ); ?></label>
					</th>
					<td>
						<input class="remote-repository-setting regular-text" type="text" id="upserv_add_remote_repository_branch" data-prop="branch" name="upserv_add_remote_repository_branch" value="">
						<p class="description">
							<?php esc_html_e( 'The branch to download when getting remote packages from the Remote Repository Service.', 'updatepulse-server' ); ?>
						</p>
					</td>
				</tr>
			</table>
			<div class="notice notice-error error hidden invalid-url" role="alert">
				<p>
					<?php esc_html_e( 'The URL is invalid.', 'updatepulse-server' ); ?>
				</p>
			</div>
			<div class="notice notice-error error hidden invalid-branch" role="alert">
				<p>
					<?php esc_html_e( 'The branch name is invalid.', 'updatepulse-server' ); ?>
				</p>
			</div>
			<p>
				<button class="button" id="upserv_add_remote_repository"><?php esc_html_e( 'Add', 'updatepulse-server' ); ?></button>
			</p>
		</div>
	</div>
</div>
