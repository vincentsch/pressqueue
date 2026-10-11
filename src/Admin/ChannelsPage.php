<?php
/**
 * Channels screen.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Admin;

use PressQueue\Security\SafeOperatorError;
use PressQueue\Storage\PublicIds;
use PressQueue\Storage\Record\ChannelRecord;
use PressQueue\Storage\Record\ConnectionRecord;
use PressQueue\Storage\Repository\ChannelRepository;
use PressQueue\Storage\Repository\ConnectionRepository;
use PressQueue\Storage\Repository\RuleRepository;

/**
 * Renders the Buffer connection and channel switches on one page.
 */
final class ChannelsPage {
	private const BUFFER_API_URL = 'https://publish.buffer.com/settings/api';
	private const SALT_URL       = 'https://api.wordpress.org/secret-key/1.1/salt/';

	/**
	 * Whether the "Add another Buffer account" disclosure starts open.
	 *
	 * @var bool
	 */
	private static bool $open_add_another = false;

	/**
	 * Connection whose "More" menu starts open.
	 *
	 * @var string
	 */
	private static string $open_menu_for = '';

	/**
	 * Render the page.
	 */
	public static function render(): void {
		Ui::page_start( __( 'Channels', 'pressqueue' ) );

		try {
			$connections = ( new ConnectionRepository() )->find_all();
			$channels    = array();
			foreach ( ( new ChannelRepository() )->find_all() as $channel ) {
				$channels[ $channel->connection_public_id ][] = $channel;
			}
			$usage = ChannelStatus::usage_counts( ( new RuleRepository() )->find_enabled() );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			echo '<div class="pq-callout pq-callout--bad"><p>' . esc_html__( 'PressQueue could not load your Buffer connections. Reload the page. If this keeps happening, open PressQueue Settings to check the plugin status.', 'pressqueue' ) . '</p></div>';
			Ui::page_end();
			return;
		}

		$active       = array();
		$waiting      = array();
		$disconnected = array();
		foreach ( $connections as $connection ) {
			if ( 'disconnected' === $connection->connection_status ) {
				$disconnected[] = $connection;
			} elseif ( self::is_waiting( $connection ) ) {
				$waiting[] = $connection;
			} else {
				$active[] = $connection;
			}
		}

		self::render_notice( $connections, $channels, $usage );

		$storage_problem = ConnectionController::credential_storage_problem();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only routing hints.
		$error_form       = isset( $_GET['pressqueue_error'], $_GET['pressqueue_form'] ) ? sanitize_key( wp_unslash( $_GET['pressqueue_form'] ) ) : '';
		$error_connection = isset( $_GET['pressqueue_connection'] ) ? sanitize_text_field( wp_unslash( $_GET['pressqueue_connection'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		self::$open_add_another = 'connect' === $error_form;
		self::$open_menu_for    = 'replace' === $error_form && PublicIds::is_valid( $error_connection ) ? $error_connection : '';

		if ( array() === $active && array() === $waiting ) {
			echo '<div class="pq-stack">';
			self::render_connect_card( $storage_problem, false );
			echo '<div class="pq-card pq-card--pad pq-muted">';
			echo '<p class="pq-flush">' . esc_html__( 'After connecting you will see your Buffer channels here. They all start switched off, so nothing is shared until you choose.', 'pressqueue' ) . '</p>';
			if ( self::has_earlier_connection( $disconnected ) ) {
				echo '<p class="pq-flush pq-gap-top">' . esc_html__( 'Connecting a Buffer account you used before brings back its channels and the rules that use them.', 'pressqueue' ) . '</p>';
			}
			echo '</div>';
			echo '</div>';
		} else {
			echo '<div class="pq-stack">';
			foreach ( $active as $connection ) {
				self::render_connection( $connection, $channels[ $connection->public_id ] ?? array(), $usage, $connections, count( $active ) > 1 );
			}
			foreach ( $waiting as $connection ) {
				self::render_waiting( $connection, $connections );
			}
			if ( array() !== $active ) {
				self::render_add_another( $storage_problem );
			}
			echo '</div>';
		}

		self::render_technical_details( $connections, $channels );
		Ui::page_end();
	}

	/**
	 * Return organizations stored on a connection.
	 *
	 * @param ConnectionRecord $connection Connection.
	 * @return array<int,array<string,mixed>>
	 */
	public static function organizations( ConnectionRecord $connection ): array {
		$organizations = $connection->account['organizations'] ?? array();

		return is_array( $organizations ) ? array_values( array_filter( $organizations, 'is_array' ) ) : array();
	}

	/**
	 * Return the name people know a connection by: its Buffer organization, or its label.
	 *
	 * @param ConnectionRecord $connection Connection.
	 * @return string
	 */
	public static function connection_name( ConnectionRecord $connection ): string {
		foreach ( self::organizations( $connection ) as $organization ) {
			if ( null !== $connection->remote_organization_id && ( $organization['remote_organization_id'] ?? null ) === $connection->remote_organization_id ) {
				$label = (string) ( $organization['display_label'] ?? '' );
				if ( '' !== trim( $label ) ) {
					return $label;
				}
			}
		}

		return $connection->label;
	}

	/**
	 * Return the display state of a connection.
	 *
	 * @param ConnectionRecord $connection Connection.
	 * @return string One of on, off, invalid, refreshing, setup, choose, retry.
	 */
	public static function state( ConnectionRecord $connection ): string {
		if ( 'credential_invalid' === $connection->connection_status ) {
			return 'invalid';
		}

		if ( null !== $connection->sync_lease_owner && null !== $connection->sync_lease_expires_at && $connection->sync_lease_expires_at->getTimestamp() > time() ) {
			return 'refreshing';
		}

		if ( 'verified' === $connection->connection_status ) {
			return $connection->enabled ? 'on' : 'off';
		}

		if ( null === $connection->remote_organization_id ) {
			return 'setup';
		}

		return self::organization_visible( $connection, $connection->remote_organization_id ) ? 'retry' : 'choose';
	}

	/**
	 * Check whether a saved connection waits for an organization choice.
	 *
	 * @param ConnectionRecord $connection Connection.
	 * @return bool
	 */
	private static function is_waiting( ConnectionRecord $connection ): bool {
		return 'unverified' === $connection->connection_status
			&& $connection->has_credential
			&& null === $connection->remote_organization_id
			&& count( self::organizations( $connection ) ) > 1;
	}

	/**
	 * Check whether an organization is still listed on a connection's account.
	 *
	 * @param ConnectionRecord $connection Connection.
	 * @param string           $organization_id Organization ID.
	 * @return bool
	 */
	private static function organization_visible( ConnectionRecord $connection, string $organization_id ): bool {
		foreach ( self::organizations( $connection ) as $organization ) {
			if ( ( $organization['remote_organization_id'] ?? null ) === $organization_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether an earlier connection can be picked up again.
	 *
	 * @param array<int,ConnectionRecord> $disconnected Disconnected connections.
	 * @return bool
	 */
	private static function has_earlier_connection( array $disconnected ): bool {
		foreach ( $disconnected as $connection ) {
			if ( null !== $connection->remote_organization_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render the connect card.
	 *
	 * @param string|null $storage_problem Credential storage problem, if any.
	 * @param bool        $compact Whether this is the "Add another Buffer account" variant.
	 */
	private static function render_connect_card( ?string $storage_problem, bool $compact ): void {
		$field_id = $compact ? 'pq-buffer-key-another' : 'pq-buffer-key';

		echo '<div class="pq-card pq-connect">';
		echo '<div class="pq-card-head"><div>';
		if ( $compact ) {
			echo '<h3 class="pq-connect-title">' . esc_html__( 'Connect another Buffer account', 'pressqueue' ) . '</h3>';
			echo '<p class="pq-sub">' . esc_html__( 'Use a key from another Buffer account. A key from an account you already connected lets you add its other organizations.', 'pressqueue' ) . '</p>';
		} else {
			echo '<h2>' . esc_html__( 'Connect your Buffer account', 'pressqueue' ) . '</h2>';
			echo '<p class="pq-sub">' . esc_html__( 'PressQueue posts through Buffer. Free Buffer plans work. It takes about a minute.', 'pressqueue' ) . '</p>';
		}
		echo '</div></div>';

		$heading = $compact ? 'h4' : 'h3';
		echo '<div class="pq-connect-steps">';

		echo '<div class="pq-connect-step">';
		echo '<span class="pq-step-label">' . esc_html__( 'Step 1', 'pressqueue' ) . '</span>';
		echo '<' . esc_html( $heading ) . ' class="pq-step-heading">' . esc_html__( 'Create an API key in Buffer', 'pressqueue' ) . '</' . esc_html( $heading ) . '>';
		echo '<p class="pq-muted pq-flush">';
		printf(
			/* translators: %s: the "New API key" button label in Buffer. */
			esc_html__( 'In Buffer, click %s, name it PressQueue and copy it.', 'pressqueue' ),
			'<span class="pq-kbd">' . esc_html__( 'New API key', 'pressqueue' ) . '</span>'
		);
		echo '</p>';
		echo '<p class="pq-flush">';
		self::render_buffer_link( __( 'Open Buffer API settings', 'pressqueue' ), 'button' );
		echo '</p>';
		echo '</div>';

		echo '<div class="pq-connect-step">';
		echo '<span class="pq-step-label">' . esc_html__( 'Step 2', 'pressqueue' ) . '</span>';
		if ( null !== $storage_problem ) {
			echo '<' . esc_html( $heading ) . ' class="pq-step-heading">' . esc_html__( 'Paste the key here', 'pressqueue' ) . '</' . esc_html( $heading ) . '>';
			self::render_storage_callout( $storage_problem );
		} else {
			Ui::form_start( 'pressqueue_connect_buffer', 'pressqueue_connect_buffer', array(), array( 'class' => 'pq-connect-form' ) );
			echo '<' . esc_html( $heading ) . ' class="pq-step-heading"><label for="' . esc_attr( $field_id ) . '">' . esc_html__( 'Paste the key here', 'pressqueue' ) . '</label></' . esc_html( $heading ) . '>';
			echo '<div class="pq-keyrow">';
			self::render_key_input( $field_id, __( 'Paste your Buffer API key', 'pressqueue' ) );
			echo '<button type="submit" class="button button-primary" data-pq-busy="' . esc_attr__( 'Connecting...', 'pressqueue' ) . '">' . esc_html__( 'Connect', 'pressqueue' ) . '</button>';
			echo '</div>';
			echo '<p class="pq-help">' . esc_html__( 'Stored encrypted on this site. It is only sent to Buffer.', 'pressqueue' ) . '</p>';
			Ui::form_end();
		}
		echo '</div>';

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render a password input for an API key. The value is never filled in.
	 *
	 * @param string $id Field ID.
	 * @param string $placeholder Placeholder.
	 */
	private static function render_key_input( string $id, string $placeholder ): void {
		echo '<input type="password" id="' . esc_attr( $id ) . '" name="api_key" class="pq-field" autocomplete="off" spellcheck="false" autocapitalize="off" autocorrect="off" required placeholder="' . esc_attr( $placeholder ) . '" />';
	}

	/**
	 * Render a link to Buffer's API settings that opens in a new tab.
	 *
	 * @param string $label Visible label.
	 * @param string $css_class Link class.
	 */
	private static function render_buffer_link( string $label, string $css_class ): void {
		echo '<a class="' . esc_attr( $css_class ) . '" href="' . esc_url( self::BUFFER_API_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label );
		echo '<svg class="pq-external" width="12" height="12" viewBox="0 0 12 12" aria-hidden="true" focusable="false"><path d="M5 2.5h4.5V7M9.5 2.5 3 9" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		echo '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'pressqueue' ) . '</span></a>';
	}

	/**
	 * Explain what to fix when this server cannot store a key.
	 *
	 * @param string $problem Problem: sodium or salts.
	 */
	private static function render_storage_callout( string $problem ): void {
		echo '<div class="pq-callout pq-callout--warn">';
		echo '<p class="pq-strong">' . esc_html__( 'PressQueue cannot store a Buffer key on this server yet.', 'pressqueue' ) . '</p>';
		echo '<p>' . esc_html( self::storage_problem_text( $problem ) ) . '</p>';
		if ( 'salts' === $problem ) {
			echo '<p><a href="' . esc_url( self::SALT_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Generate new keys and salts', 'pressqueue' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'pressqueue' ) . '</span></a></p>';
		}
		echo '</div>';
	}

	/**
	 * Return the explanation for a storage problem.
	 *
	 * @param string $problem Problem: sodium or salts.
	 * @return string
	 */
	private static function storage_problem_text( string $problem ): string {
		if ( 'sodium' === $problem ) {
			return __( 'PressQueue encrypts the key with the PHP Sodium extension, and this server does not have it turned on. Ask your host to enable the sodium extension for PHP, then reload this page.', 'pressqueue' );
		}

		return __( 'PressQueue encrypts the key with the security keys and salts in wp-config.php, and they are missing or still set to the placeholder text. Add unique values for all eight keys and salts, then reload this page. Changing them signs everyone out once.', 'pressqueue' );
	}

	/**
	 * Render one connected Buffer account with its channels.
	 *
	 * @param ConnectionRecord            $connection Connection.
	 * @param array<int,ChannelRecord>    $channels Channels of this connection.
	 * @param array<string,int>           $usage Enabled rule counts per channel.
	 * @param array<int,ConnectionRecord> $connections All connections.
	 * @param bool                        $several Whether more than one account is shown.
	 */
	private static function render_connection( ConnectionRecord $connection, array $channels, array $usage, array $connections, bool $several ): void {
		$state = self::state( $connection );
		$name  = self::connection_name( $connection );
		$pill  = self::state_pill( $state );

		echo '<section class="pq-account-group" aria-label="' . esc_attr( $name ) . '">';
		echo '<div class="pq-card pq-account pq-account--' . esc_attr( $state ) . '">';
		echo '<div class="pq-account-head">';
		echo '<span class="pq-avatar" aria-hidden="true">' . esc_html( self::initials( $name ) ) . '</span>';
		echo '<div class="pq-account-info">';
		echo '<h2 class="pq-account-name"><span>' . esc_html( $name ) . '</span> ' . Ui::pill( $pill[0], $pill[1] ) . '</h2>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::pill escapes.
		self::render_account_meta( $connection );
		echo '</div>';

		echo '<div class="pq-account-actions">';
		if ( in_array( $state, array( 'on', 'off' ), true ) && null !== $connection->remote_organization_id ) {
			Ui::form_start(
				'pressqueue_sync_connection',
				'pressqueue_sync_connection',
				array(
					'connection_public_id'   => $connection->public_id,
					'remote_organization_id' => (string) $connection->remote_organization_id,
				),
				array( 'class' => 'pq-inline' )
			);
			echo '<button type="submit" class="button" data-pq-busy="' . esc_attr__( 'Refreshing...', 'pressqueue' ) . '">' . esc_html__( 'Refresh channels', 'pressqueue' ) . '</button>';
			Ui::form_end();
		}
		if ( 'off' === $state ) {
			Ui::button_form( 'pressqueue_enable_connection', 'pressqueue_enable_connection', array( 'connection_public_id' => $connection->public_id ), __( 'Turn on', 'pressqueue' ), 'button button-primary' );
		}
		self::render_more_menu( $connection, $state, $name, $connections );
		echo '</div>';
		echo '</div>';

		self::render_account_body( $connection, $state, $connections );
		echo '</div>';

		if ( null !== $connection->remote_organization_id && ! in_array( $state, array( 'setup', 'choose' ), true ) ) {
			self::render_channels( $connection, $channels, $usage, $several ? $name : '' );
		}

		echo '</section>';
	}

	/**
	 * Render the muted line under the account name.
	 *
	 * @param ConnectionRecord $connection Connection.
	 */
	private static function render_account_meta( ConnectionRecord $connection ): void {
		$parts   = array();
		$account = isset( $connection->account['display_label'] ) && is_string( $connection->account['display_label'] ) ? trim( $connection->account['display_label'] ) : '';

		if ( '' !== $account ) {
			/* translators: %s: Buffer account name. */
			$parts[] = esc_html( sprintf( __( 'Buffer account: %s', 'pressqueue' ), $account ) );
		}

		$checked = $connection->credential_verified_at;
		if ( null !== $checked && 'credential_invalid' !== $connection->connection_status ) {
			$relative = time() - $checked->getTimestamp() < 60
				? __( 'Key checked just now', 'pressqueue' )
				/* translators: %s: relative time such as "5 mins ago" or a date. */
				: sprintf( __( 'Key last checked %s', 'pressqueue' ), Ui::relative_time( $checked ) );
			$parts[] = '<time datetime="' . esc_attr( $checked->format( DATE_ATOM ) ) . '" title="' . esc_attr( Ui::local_datetime( $checked ) ) . '">' . esc_html( $relative ) . '</time>';
		}

		if ( array() === $parts ) {
			$parts[] = esc_html__( 'Buffer', 'pressqueue' );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part is escaped above.
		echo '<p class="pq-account-meta">' . implode( ' &middot; ', $parts ) . '</p>';
	}

	/**
	 * Return the tone and label for a connection state.
	 *
	 * @param string $state State.
	 * @return array{0:string,1:string}
	 */
	private static function state_pill( string $state ): array {
		$pills = array(
			'on'         => array( 'ok', __( 'Connected', 'pressqueue' ) ),
			'off'        => array( 'idle', __( 'Turned off', 'pressqueue' ) ),
			'invalid'    => array( 'bad', __( 'Key no longer works', 'pressqueue' ) ),
			'refreshing' => array( 'info', __( 'Loading channels', 'pressqueue' ) ),
			'setup'      => array( 'warn', __( 'Setup not finished', 'pressqueue' ) ),
			'choose'     => array( 'warn', __( 'Choose an organization', 'pressqueue' ) ),
			'retry'      => array( 'warn', __( 'Needs attention', 'pressqueue' ) ),
		);

		return $pills[ $state ] ?? array( 'idle', $state );
	}

	/**
	 * Render the account problem area, if the state needs one.
	 *
	 * @param ConnectionRecord            $connection Connection.
	 * @param string                      $state State.
	 * @param array<int,ConnectionRecord> $connections All connections.
	 */
	private static function render_account_body( ConnectionRecord $connection, string $state, array $connections ): void {
		if ( 'on' === $state && null === $connection->last_error ) {
			return;
		}

		echo '<div class="pq-account-body">';

		switch ( $state ) {
			case 'on':
				echo '<div class="pq-callout pq-callout--warn"><p>' . esc_html( self::last_error_text( $connection->last_error ) ) . ' ' . esc_html__( 'Sharing still uses the channels loaded before. Click Refresh channels to try again.', 'pressqueue' ) . '</p></div>';
				break;

			case 'off':
				echo '<div class="pq-callout"><p>' . esc_html__( 'This account is turned off. Its channels do not share until you turn it back on. The saved key and your channel switches stay as they are.', 'pressqueue' ) . '</p></div>';
				break;

			case 'invalid':
				echo '<div class="pq-callout pq-callout--bad">';
				echo '<p class="pq-strong">' . esc_html__( 'Buffer no longer accepts the saved key, so nothing is shared through this account.', 'pressqueue' ) . '</p>';
				echo '<p>' . esc_html__( 'This happens when the key is deleted in Buffer. Create a new key in Buffer and paste it below. Your channels and rules stay as they are.', 'pressqueue' ) . '</p>';
				echo '</div>';
				self::render_replace_form( $connection, true );
				break;

			case 'refreshing':
				echo '<div class="pq-callout"><p>' . esc_html__( 'PressQueue is loading the channels for this account. Reload the page in a moment.', 'pressqueue' ) . '</p></div>';
				break;

			case 'setup':
				echo '<div class="pq-callout pq-callout--warn"><p>' . esc_html__( 'Setup is not finished. PressQueue still needs to check the key with Buffer and load your channels.', 'pressqueue' ) . '</p></div>';
				echo '<div class="pq-row pq-gap-top">';
				Ui::button_form( 'pressqueue_finish_connection', 'pressqueue_finish_connection', array( 'connection_public_id' => $connection->public_id ), __( 'Finish setup', 'pressqueue' ), 'button button-primary' );
				echo '</div>';
				break;

			case 'choose':
				echo '<div class="pq-callout pq-callout--warn"><p>' . esc_html__( 'The Buffer organization this account used is no longer available to the key. Choose which organization PressQueue should use now.', 'pressqueue' ) . '</p></div>';
				self::render_organization_form( $connection, $connections, __( 'Use this organization', 'pressqueue' ), false );
				break;

			case 'retry':
				$detail = null === $connection->last_error ? '' : ' ' . self::last_error_text( $connection->last_error );
				echo '<div class="pq-callout pq-callout--warn"><p>' . esc_html__( 'PressQueue could not finish loading the channels for this account.', 'pressqueue' ) . esc_html( $detail ) . '</p></div>';
				echo '<div class="pq-row pq-gap-top">';
				Ui::button_form( 'pressqueue_finish_connection', 'pressqueue_finish_connection', array( 'connection_public_id' => $connection->public_id ), __( 'Try again', 'pressqueue' ), 'button button-primary' );
				echo '</div>';
				break;
		}

		echo '</div>';
	}

	/**
	 * Return a plain sentence for a stored sync failure.
	 *
	 * @param array<string,mixed>|null $error Redacted error document.
	 * @return string
	 */
	private static function last_error_text( ?array $error ): string {
		$classification = is_array( $error ) && isset( $error['classification'] ) && is_string( $error['classification'] ) ? $error['classification'] : '';
		$texts          = array(
			'invalid_credential' => __( 'The last refresh failed because Buffer did not accept the key.', 'pressqueue' ),
			'forbidden'          => __( 'The last refresh failed because Buffer refused access.', 'pressqueue' ),
			'network_failure'    => __( 'The last refresh failed because this site could not reach Buffer.', 'pressqueue' ),
			'rate_limited'       => __( 'The last refresh failed because Buffer asked PressQueue to slow down.', 'pressqueue' ),
		);

		return $texts[ $classification ] ?? __( 'The last refresh did not finish.', 'pressqueue' );
	}

	/**
	 * Render the "More" menu for an account.
	 *
	 * @param ConnectionRecord            $connection Connection.
	 * @param string                      $state State.
	 * @param string                      $name Connection name.
	 * @param array<int,ConnectionRecord> $connections All connections.
	 */
	private static function render_more_menu( ConnectionRecord $connection, string $state, string $name, array $connections ): void {
		echo '<details class="pq-more pq-menu"' . ( self::$open_menu_for === $connection->public_id ? ' open' : '' ) . '>';
		echo '<summary>' . esc_html__( 'More', 'pressqueue' ) . '<span class="screen-reader-text"> ' . esc_html(
			/* translators: %s: Buffer organization name. */
			sprintf( __( 'options for %s', 'pressqueue' ), $name )
		) . '</span></summary>';
		echo '<div class="pq-more-body pq-menu-body">';

		if ( 'invalid' !== $state && null !== $connection->credential_envelope_hash ) {
			echo '<div class="pq-menu-section">';
			self::render_replace_form( $connection, false );
			echo '</div>';
		}

		if ( in_array( $state, array( 'on', 'off', 'retry' ), true ) && self::can_switch( $connection, $connections ) ) {
			echo '<div class="pq-menu-section">';
			self::render_organization_form( $connection, $connections, __( 'Switch', 'pressqueue' ), true );
			echo '</div>';
		}

		if ( 'on' === $state ) {
			echo '<div class="pq-menu-section">';
			echo '<p class="pq-menu-title">' . esc_html__( 'Turn off', 'pressqueue' ) . '</p>';
			echo '<p class="pq-help pq-flush">' . esc_html__( 'Pause sharing through this account. The key and your channel switches stay saved.', 'pressqueue' ) . '</p>';
			echo '<div class="pq-gap-top">';
			Ui::button_form(
				'pressqueue_disable_connection',
				'pressqueue_disable_connection',
				array( 'connection_public_id' => $connection->public_id ),
				__( 'Turn off', 'pressqueue' ),
				'button',
				/* translators: %s: Buffer organization name. */
				sprintf( __( 'Turn off %s? Its channels stop sharing and rules skip them until you turn it back on.', 'pressqueue' ), $name )
			);
			echo '</div>';
			echo '</div>';
		}

		echo '<div class="pq-menu-section">';
		echo '<p class="pq-menu-title">' . esc_html__( 'Disconnect', 'pressqueue' ) . '</p>';
		echo '<p class="pq-help pq-flush">' . esc_html__( 'Deletes the saved key. Connecting the same Buffer account again brings its channels back.', 'pressqueue' ) . '</p>';
		echo '<div class="pq-gap-top">';
		Ui::button_form(
			'pressqueue_disconnect_connection',
			'pressqueue_disconnect_connection',
			array( 'connection_public_id' => $connection->public_id ),
			__( 'Disconnect', 'pressqueue' ),
			'button pq-danger',
			/* translators: %s: Buffer organization name. */
			sprintf( __( 'Disconnect %s? PressQueue deletes the saved key. Its channels stop and rules skip them until you connect this Buffer account again.', 'pressqueue' ), $name )
		);
		echo '</div>';
		echo '</div>';

		echo '</div>';
		echo '</details>';
	}

	/**
	 * Check whether a connection has another organization it could switch to.
	 *
	 * @param ConnectionRecord            $connection Connection.
	 * @param array<int,ConnectionRecord> $connections All connections.
	 * @return bool
	 */
	private static function can_switch( ConnectionRecord $connection, array $connections ): bool {
		foreach ( self::organizations( $connection ) as $organization ) {
			$id = (string) ( $organization['remote_organization_id'] ?? '' );
			if ( '' !== $id && $id !== $connection->remote_organization_id && ! self::organization_taken( $connections, $connection, $id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render the "Replace API key" form.
	 *
	 * @param ConnectionRecord $connection Connection.
	 * @param bool             $reconnect Whether the account should be turned back on afterwards.
	 */
	private static function render_replace_form( ConnectionRecord $connection, bool $reconnect ): void {
		$field_id = 'pq-replace-key-' . strtolower( $connection->public_id ) . ( $reconnect ? '-fix' : '' );
		$hidden   = array(
			'connection_public_id'  => $connection->public_id,
			'credential_rotated_at' => null === $connection->credential_rotated_at ? '' : $connection->credential_rotated_at->format( 'Y-m-d H:i:s' ),
		);
		if ( $reconnect ) {
			$hidden['reconnect'] = '1';
		}

		Ui::form_start( 'pressqueue_rotate_connection', 'pressqueue_rotate_connection', $hidden, array( 'class' => 'pq-key-form' ) );
		echo '<label class="pq-menu-title" for="' . esc_attr( $field_id ) . '">' . esc_html( $reconnect ? __( 'Paste a new API key', 'pressqueue' ) : __( 'Replace API key', 'pressqueue' ) ) . '</label>';
		echo '<div class="pq-keyrow">';
		self::render_key_input( $field_id, __( 'Paste a new Buffer API key', 'pressqueue' ) );
		echo '<button type="submit" class="button' . ( $reconnect ? ' button-primary' : '' ) . '" data-pq-busy="' . esc_attr__( 'Checking the key...', 'pressqueue' ) . '">' . esc_html( $reconnect ? __( 'Replace key and reconnect', 'pressqueue' ) : __( 'Replace', 'pressqueue' ) ) . '</button>';
		echo '</div>';
		echo '<p class="pq-help">';
		echo esc_html__( 'Use a key from the same Buffer account. Channels and rules stay as they are.', 'pressqueue' ) . ' ';
		self::render_buffer_link( __( 'Open Buffer API settings', 'pressqueue' ), 'pq-link' );
		echo '</p>';
		Ui::form_end();
	}

	/**
	 * Render an organization choice form.
	 *
	 * @param ConnectionRecord            $connection Connection.
	 * @param array<int,ConnectionRecord> $connections All connections.
	 * @param string                      $button Button label.
	 * @param bool                        $switching Whether this switches away from a working organization.
	 */
	private static function render_organization_form( ConnectionRecord $connection, array $connections, string $button, bool $switching ): void {
		$name       = self::connection_name( $connection );
		$attributes = array( 'class' => 'pq-org-form' );
		if ( $switching ) {
			$attributes['data-pq-confirm'] = sprintf(
				/* translators: %s: current Buffer organization name. */
				__( 'Switch organization? Channels from %s stop, and rules that use them skip them. Channels from the new organization start switched off.', 'pressqueue' ),
				$name
			);
		}

		Ui::form_start( 'pressqueue_finish_connection', 'pressqueue_finish_connection', array( 'connection_public_id' => $connection->public_id ), $attributes );
		echo '<fieldset>';
		echo '<legend class="pq-menu-title">' . esc_html( $switching ? __( 'Switch organization', 'pressqueue' ) : __( 'Buffer organization', 'pressqueue' ) ) . '</legend>';
		echo '<div class="pq-checks">';
		foreach ( self::organizations( $connection ) as $organization ) {
			$id = (string) ( $organization['remote_organization_id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}

			$current  = $switching && $id === $connection->remote_organization_id;
			$taken    = self::organization_taken( $connections, $connection, $id );
			$disabled = $current || $taken;
			$note     = $current ? __( 'in use now', 'pressqueue' ) : ( $taken ? __( 'already connected', 'pressqueue' ) : self::channel_count_text( $organization ) );

			echo '<label><input type="radio" name="remote_organization_id" value="' . esc_attr( $id ) . '" required' . ( $disabled ? ' disabled' : '' ) . ' /> <span>' . esc_html( (string) ( $organization['display_label'] ?? $id ) ) . ' <span class="pq-muted">&middot; ' . esc_html( $note ) . '</span></span></label>';
		}
		echo '</div>';
		echo '</fieldset>';
		echo '<div class="pq-gap-top"><button type="submit" class="button' . ( $switching ? '' : ' button-primary' ) . '" data-pq-busy="' . esc_attr__( 'Loading channels...', 'pressqueue' ) . '">' . esc_html( $button ) . '</button></div>';
		if ( $switching ) {
			echo '<p class="pq-help">' . esc_html__( 'Channels from the new organization start switched off.', 'pressqueue' ) . '</p>';
		}
		Ui::form_end();
	}

	/**
	 * Return "5 channels" for an organization.
	 *
	 * @param array<string,mixed> $organization Organization document.
	 * @return string
	 */
	private static function channel_count_text( array $organization ): string {
		$count = (int) ( $organization['channel_count'] ?? 0 );

		/* translators: %d: number of channels in a Buffer organization. */
		return sprintf( _n( '%d channel', '%d channels', $count, 'pressqueue' ), $count );
	}

	/**
	 * Check whether another active connection already uses an organization.
	 *
	 * @param array<int,ConnectionRecord> $connections All connections.
	 * @param ConnectionRecord            $connection Connection being set up.
	 * @param string                      $organization_id Organization ID.
	 * @return bool
	 */
	private static function organization_taken( array $connections, ConnectionRecord $connection, string $organization_id ): bool {
		foreach ( $connections as $other ) {
			if (
				$other->public_id !== $connection->public_id
				&& 'disconnected' !== $other->connection_status
				&& $other->provider === $connection->provider
				&& null !== $other->remote_account_id
				&& $other->remote_account_id === $connection->remote_account_id
				&& $other->remote_organization_id === $organization_id
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Render a saved key that waits for an organization choice.
	 *
	 * @param ConnectionRecord            $connection Connection.
	 * @param array<int,ConnectionRecord> $connections All connections.
	 */
	private static function render_waiting( ConnectionRecord $connection, array $connections ): void {
		$cancel_id = 'pq-cancel-' . strtolower( $connection->public_id );
		$first     = '';

		echo '<div class="pq-card pq-chooser">';
		Ui::form_start( 'pressqueue_finish_connection', 'pressqueue_finish_connection', array( 'connection_public_id' => $connection->public_id ) );
		echo '<div class="pq-section">';
		echo '<div><h2>' . esc_html__( 'Which Buffer organization should PressQueue use?', 'pressqueue' ) . '</h2>';
		echo '<p class="pq-sub">' . esc_html__( 'Your key works and can see more than one Buffer organization. Pick the one whose channels PressQueue should post to. You can switch later.', 'pressqueue' ) . '</p></div>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Buffer organization', 'pressqueue' ) . '</legend>';
		echo '<div class="pq-choices">';
		foreach ( self::organizations( $connection ) as $organization ) {
			$id = (string) ( $organization['remote_organization_id'] ?? '' );
			if ( '' === $id ) {
				continue;
			}

			$taken   = self::organization_taken( $connections, $connection, $id );
			$checked = ! $taken && '' === $first;
			if ( $checked ) {
				$first = $id;
			}

			echo '<label class="pq-choice' . ( $taken ? ' pq-choice--taken' : '' ) . '"><input type="radio" name="remote_organization_id" value="' . esc_attr( $id ) . '" required' . ( $checked ? ' checked' : '' ) . ( $taken ? ' disabled' : '' ) . ' />';
			echo '<span><b>' . esc_html( (string) ( $organization['display_label'] ?? $id ) ) . '</b><span class="pq-choice-help">' . esc_html( $taken ? __( 'Already connected', 'pressqueue' ) : self::channel_count_text( $organization ) ) . '</span></span></label>';
		}
		echo '</div>';
		echo '</fieldset>';
		echo '</div>';
		echo '<div class="pq-actions-bar">';
		echo '<button type="submit" class="button button-primary" data-pq-busy="' . esc_attr__( 'Loading channels...', 'pressqueue' ) . '">' . esc_html__( 'Use this organization', 'pressqueue' ) . '</button>';
		echo '<button type="submit" class="button-link" form="' . esc_attr( $cancel_id ) . '">' . esc_html__( 'Cancel', 'pressqueue' ) . '</button>';
		echo '</div>';
		Ui::form_end();
		Ui::form_start(
			'pressqueue_disconnect_connection',
			'pressqueue_disconnect_connection',
			array(
				'connection_public_id' => $connection->public_id,
				'intent'               => 'cancel',
			),
			array( 'id' => $cancel_id )
		);
		Ui::form_end();
		echo '</div>';
	}

	/**
	 * Render the channel list for one connection.
	 *
	 * @param ConnectionRecord         $connection Connection.
	 * @param array<int,ChannelRecord> $channels Channels.
	 * @param array<string,int>        $usage Enabled rule counts per channel.
	 * @param string                   $organization Organization name when several accounts are shown.
	 */
	private static function render_channels( ConnectionRecord $connection, array $channels, array $usage, string $organization ): void {
		$listed = array();
		foreach ( $channels as $channel ) {
			if ( ChannelStatus::is_listed( $channel, $connection ) ) {
				$listed[] = $channel;
			}
		}

		echo '<div class="pq-card pq-channels">';
		echo '<div class="pq-card-head"><div>';
		echo '<h2>' . esc_html(
			'' === $organization
				? __( 'Your channels', 'pressqueue' )
				/* translators: %s: Buffer organization name. */
				: sprintf( __( 'Channels in %s', 'pressqueue' ), $organization )
		) . '</h2>';
		echo '<p class="pq-sub">' . esc_html__( 'Switch on the channels PressQueue may post to. New channels start switched off. Rules then choose which posts go where.', 'pressqueue' ) . '</p>';
		echo '</div></div>';

		if ( array() === $listed ) {
			Ui::empty_state(
				__( 'No channels yet', 'pressqueue' ),
				__( 'This Buffer organization has no channels. Add a social account in Buffer, then click Refresh channels.', 'pressqueue' )
			);
			echo '</div>';
			return;
		}

		echo '<ul class="pq-channel-list">';
		foreach ( ChannelStatus::sort( $listed ) as $channel ) {
			self::render_channel_row( $channel, $connection, (int) ( $usage[ $channel->public_id ] ?? 0 ) );
		}
		echo '</ul>';
		echo '</div>';
	}

	/**
	 * Render one channel row.
	 *
	 * @param ChannelRecord    $channel Channel.
	 * @param ConnectionRecord $connection Connection.
	 * @param int              $used_by Number of enabled rules that share to the channel.
	 */
	private static function render_channel_row( ChannelRecord $channel, ConnectionRecord $connection, int $used_by ): void {
		$name    = ActivitySummary::channel_display_name( $channel );
		$service = Labels::service( $channel->service );
		$problem = ChannelStatus::problem( $channel, $connection->remote_organization_id );
		$classes = 'pq-channel' . ( $channel->enabled ? '' : ' pq-channel--off' ) . ( null === $problem ? '' : ' pq-channel--unavailable' );

		echo '<li class="' . esc_attr( $classes ) . '">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::service_badge escapes.
		echo Ui::service_badge( $channel->service, 'lg' );
		echo '<div class="pq-channel-main">';
		echo '<div class="pq-channel-title">' . esc_html( $name ) . '<span class="screen-reader-text"> (' . esc_html( $service ) . ')</span></div>';
		echo '<div class="pq-channel-meta">' . esc_html( null === $problem ? ChannelStatus::summary( $channel ) : $service . ' · ' . $problem['text'] ) . '</div>';
		echo '</div>';

		echo '<div class="pq-channel-status">';
		if ( null !== $problem ) {
			$tone = in_array( $problem['key'], array( 'unsupported_service', 'unsupported_capability', 'other_organization' ), true ) ? 'idle' : 'warn';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::pill escapes.
			echo Ui::pill( $tone, $problem['label'] );
			if ( $channel->enabled ) {
				echo ' <span class="pq-muted pq-small">' . esc_html__( 'Skipped until it works again', 'pressqueue' ) . '</span>';
			}
		} elseif ( $channel->enabled && $used_by > 0 ) {
			/* translators: %d: number of rules. */
			echo Ui::pill( 'ok', sprintf( _n( 'Used by %d rule', 'Used by %d rules', $used_by, 'pressqueue' ), $used_by ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::pill escapes.
		} elseif ( $channel->enabled ) {
			echo '<span class="pq-muted pq-small">' . esc_html__( 'Add it to a rule to share posts here.', 'pressqueue' ) . '</span> ';
			echo Ui::pill( 'info', __( 'Not in a rule yet', 'pressqueue' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::pill escapes.
		} elseif ( $used_by > 0 ) {
			/* translators: %d: number of rules. */
			echo Ui::pill( 'warn', sprintf( _n( 'Off, %d rule skips it', 'Off, %d rules skip it', $used_by, 'pressqueue' ), $used_by ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::pill escapes.
		} else {
			echo '<span class="pq-muted">' . esc_html__( 'Off', 'pressqueue' ) . '</span>';
		}
		echo '</div>';

		echo '<div class="pq-channel-switch">';
		if ( null === $problem ) {
			ChannelController::render_channel_action(
				$channel,
				/* translators: 1: channel name, 2: social network name. */
				sprintf( __( 'Post to %1$s on %2$s', 'pressqueue' ), $name, $service ),
				$used_by > 0
					/* translators: 1: channel name, 2: social network name. */
					? sprintf( __( 'Switch off %1$s on %2$s? Rules that use this channel will skip it until you switch it back on.', 'pressqueue' ), $name, $service )
					: ''
			);
		} elseif ( $channel->enabled ) {
			// A channel that stopped working can still be switched off.
			ChannelController::render_channel_action(
				$channel,
				/* translators: 1: channel name, 2: social network name. */
				sprintf( __( 'Post to %1$s on %2$s', 'pressqueue' ), $name, $service )
			);
		}
		echo '</div>';
		echo '</li>';
	}

	/**
	 * Render the "Add another Buffer account" disclosure.
	 *
	 * @param string|null $storage_problem Credential storage problem, if any.
	 */
	private static function render_add_another( ?string $storage_problem ): void {
		echo '<details class="pq-more pq-add-account"' . ( self::$open_add_another ? ' open' : '' ) . '>';
		echo '<summary>' . esc_html__( 'Add another Buffer account', 'pressqueue' ) . '</summary>';
		echo '<div class="pq-more-body">';
		self::render_connect_card( $storage_problem, true );
		echo '</div>';
		echo '</details>';
	}

	/**
	 * Render IDs and raw states behind a disclosure.
	 *
	 * @param array<int,ConnectionRecord>            $connections Connections.
	 * @param array<string,array<int,ChannelRecord>> $channels Channels by connection.
	 */
	private static function render_technical_details( array $connections, array $channels ): void {
		if ( array() === $connections ) {
			return;
		}

		echo '<details class="pq-more pq-tech">';
		echo '<summary>' . esc_html__( 'Technical details', 'pressqueue' ) . '</summary>';
		echo '<div class="pq-more-body pq-stack">';
		echo '<p class="pq-help pq-flush">' . esc_html__( 'IDs for support requests and WP-CLI. Disconnected accounts stay listed here so their channels can come back when you connect the same account again.', 'pressqueue' ) . '</p>';

		echo '<div class="pq-card"><div class="pq-table-wrap"><table class="pq-table">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Buffer connections', 'pressqueue' ) . '</caption>';
		echo '<thead><tr>';
		foreach ( array( __( 'Connection', 'pressqueue' ), __( 'Status', 'pressqueue' ), __( 'PressQueue ID', 'pressqueue' ), __( 'Buffer account ID', 'pressqueue' ), __( 'Buffer organization ID', 'pressqueue' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $connections as $connection ) {
			echo '<tr>';
			echo '<td>' . esc_html( self::connection_name( $connection ) ) . '</td>';
			echo '<td><code class="pq-code">' . esc_html( $connection->connection_status ) . '</code> ' . esc_html( $connection->enabled ? __( 'on', 'pressqueue' ) : __( 'off', 'pressqueue' ) ) . '</td>';
			echo '<td><code class="pq-code">' . esc_html( $connection->public_id ) . '</code></td>';
			echo '<td>' . self::code_or_none( $connection->remote_account_id ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- code_or_none escapes.
			echo '<td>' . self::code_or_none( $connection->remote_organization_id ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- code_or_none escapes.
			echo '</tr>';
		}
		echo '</tbody></table></div></div>';

		$rows = array();
		foreach ( $connections as $connection ) {
			foreach ( $channels[ $connection->public_id ] ?? array() as $channel ) {
				$rows[] = $channel;
			}
		}

		if ( array() !== $rows ) {
			echo '<div class="pq-card"><div class="pq-table-wrap"><table class="pq-table">';
			echo '<caption class="screen-reader-text">' . esc_html__( 'Buffer channels', 'pressqueue' ) . '</caption>';
			echo '<thead><tr>';
			foreach ( array( __( 'Channel', 'pressqueue' ), __( 'Availability', 'pressqueue' ), __( 'PressQueue ID', 'pressqueue' ), __( 'Buffer channel ID', 'pressqueue' ), __( 'Buffer organization ID', 'pressqueue' ) ) as $heading ) {
				echo '<th scope="col">' . esc_html( $heading ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( $rows as $channel ) {
				echo '<tr>';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ui::channel_name escapes.
				echo '<td>' . Ui::channel_name( $channel->service, ActivitySummary::channel_display_name( $channel ) ) . '</td>';
				echo '<td><code class="pq-code">' . esc_html( $channel->availability ) . '</code> ' . esc_html( $channel->enabled ? __( 'on', 'pressqueue' ) : __( 'off', 'pressqueue' ) ) . '</td>';
				echo '<td><code class="pq-code">' . esc_html( $channel->public_id ) . '</code></td>';
				echo '<td><code class="pq-code">' . esc_html( $channel->remote_channel_id ) . '</code></td>';
				echo '<td>' . self::code_or_none( $channel->remote_organization_id ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- code_or_none escapes.
				echo '</tr>';
			}
			echo '</tbody></table></div></div>';
		}

		echo '</div>';
		echo '</details>';
	}

	/**
	 * Return an ID in code style, or a muted "none".
	 *
	 * @param string|null $value Value.
	 * @return string Escaped markup.
	 */
	private static function code_or_none( ?string $value ): string {
		if ( null === $value || '' === $value ) {
			return '<span class="pq-muted">' . esc_html__( 'none', 'pressqueue' ) . '</span>';
		}

		return '<code class="pq-code">' . esc_html( $value ) . '</code>';
	}

	/**
	 * Return up to two initials for the account avatar.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function initials( string $name ): string {
		$words    = preg_split( '/\s+/u', trim( (string) preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $name ) ) );
		$initials = '';

		foreach ( is_array( $words ) ? $words : array() as $word ) {
			if ( '' === $word ) {
				continue;
			}

			$initials .= mb_substr( $word, 0, 1 );
			if ( mb_strlen( $initials ) >= 2 ) {
				break;
			}
		}

		if ( '' === $initials ) {
			return 'B';
		}

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $initials ) : strtoupper( $initials );
	}

	/**
	 * Render the result of the last action.
	 *
	 * @param array<int,ConnectionRecord>            $connections Connections.
	 * @param array<string,array<int,ChannelRecord>> $channels Channels by connection.
	 * @param array<string,int>                      $usage Enabled rule counts per channel.
	 */
	private static function render_notice( array $connections, array $channels, array $usage ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Notice query args are display-only.
		$message       = isset( $_GET['pressqueue_message'] ) ? sanitize_key( wp_unslash( $_GET['pressqueue_message'] ) ) : '';
		$error         = isset( $_GET['pressqueue_error'] ) ? sanitize_key( wp_unslash( $_GET['pressqueue_error'] ) ) : '';
		$detail        = isset( $_GET['pressqueue_detail'] ) ? sanitize_text_field( wp_unslash( $_GET['pressqueue_detail'] ) ) : '';
		$reason        = isset( $_GET['pressqueue_reason'] ) ? sanitize_key( wp_unslash( $_GET['pressqueue_reason'] ) ) : '';
		$connection_id = isset( $_GET['pressqueue_connection'] ) ? sanitize_text_field( wp_unslash( $_GET['pressqueue_connection'] ) ) : '';
		$channel_id    = isset( $_GET['pressqueue_channel'] ) ? sanitize_text_field( wp_unslash( $_GET['pressqueue_channel'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$connections_by_id = array();
		foreach ( $connections as $candidate ) {
			$connections_by_id[ $candidate->public_id ] = $candidate;
		}

		$connection = PublicIds::is_valid( $connection_id ) ? ( $connections_by_id[ $connection_id ] ?? null ) : null;
		$channel    = null;
		$all_listed = array();
		foreach ( $channels as $connection_channels ) {
			foreach ( $connection_channels as $candidate ) {
				if ( PublicIds::is_valid( $channel_id ) && $candidate->public_id === $channel_id ) {
					$channel = $candidate;
				}
				$owner = $connections_by_id[ $candidate->connection_public_id ] ?? null;
				if ( null !== $owner && 'disconnected' !== $owner->connection_status && ChannelStatus::is_listed( $candidate, $owner ) ) {
					$all_listed[] = $candidate;
				}
			}
		}

		$name = null === $connection ? __( 'Buffer', 'pressqueue' ) : self::connection_name( $connection );

		if ( '' !== $error ) {
			$text = self::error_text( $error, $name );
			if ( '' === $text ) {
				return;
			}

			$technical = '';
			if ( in_array( $reason, ConnectionController::PROVIDER_REASONS, true ) ) {
				/* translators: %s: fixed error code such as network_failure. */
				$technical = sprintf( __( 'Buffer request failed with code %s.', 'pressqueue' ), $reason );
			} elseif ( '' !== $detail ) {
				$technical = SafeOperatorError::notice_detail( $detail );
			}

			self::render_error_notice( $text, $technical );
			return;
		}

		if ( '' === $message ) {
			return;
		}

		$text = self::success_text( $message, $name, $channel, $all_listed, $usage );
		if ( '' === $text ) {
			return;
		}

		if ( 'connection_error' === $message ) {
			self::render_error_notice( $text, '' === $detail ? '' : SafeOperatorError::notice_detail( $detail ) );
			return;
		}

		Ui::notice( 'success', $text );
	}

	/**
	 * Render an error notice with optional technical details.
	 *
	 * @param string $text Plain message.
	 * @param string $technical Safe technical detail.
	 */
	private static function render_error_notice( string $text, string $technical ): void {
		echo '<div class="notice notice-error pq-notice"><p>' . esc_html( $text ) . '</p>';
		if ( '' !== $technical ) {
			echo '<details class="pq-more pq-notice-details"><summary>' . esc_html__( 'Technical details', 'pressqueue' ) . '</summary><div class="pq-more-body"><p><code class="pq-code">' . esc_html( $technical ) . '</code></p></div></details>';
		}
		echo '</div>';
	}

	/**
	 * Return the plain message for an error code.
	 *
	 * @param string $error Error code.
	 * @param string $name Connection name.
	 * @return string
	 */
	private static function error_text( string $error, string $name ): string {
		switch ( $error ) {
			case 'key_missing':
				return __( 'Paste your Buffer API key first, then click Connect.', 'pressqueue' );
			case 'key_rejected':
				return __( 'Buffer did not accept this key. Copy it again from Buffer\'s API settings and paste the whole key.', 'pressqueue' );
			case 'key_forbidden':
				return __( 'Buffer accepted the key but would not let PressQueue read this account. Create a new key in Buffer\'s API settings and try again.', 'pressqueue' );
			case 'network':
				return __( 'This site could not reach Buffer. Your server needs to allow outgoing HTTPS connections to api.buffer.com. Try again in a moment, or ask your host to check it.', 'pressqueue' );
			case 'rate_limited':
				return __( 'Buffer is limiting requests from this key right now. Wait a few minutes, then try again.', 'pressqueue' );
			case 'no_organization':
				return __( 'This Buffer account has no organization yet. Finish setting up your account in Buffer, then try again.', 'pressqueue' );
			case 'already_connected':
				/* translators: %s: Buffer organization name. */
				return sprintf( __( '%s is already connected. To use a new key for it, open More next to it and choose Replace API key.', 'pressqueue' ), $name );
			case 'all_connected':
				return __( 'Every Buffer organization this key can see is already connected.', 'pressqueue' );
			case 'organization_missing':
				return __( 'That Buffer organization is not available to this key anymore. Pick another one.', 'pressqueue' );
			case 'other_account':
				return __( 'This key belongs to a different Buffer account. Use a key from the account you connected, or add the other account with Add another Buffer account.', 'pressqueue' );
			case 'storage_sodium':
			case 'storage_salts':
				return __( 'PressQueue cannot store a Buffer key on this server yet.', 'pressqueue' ) . ' ' . self::storage_problem_text( substr( $error, 8 ) );
			case 'key_unreadable':
				return __( 'PressQueue can no longer read the saved key. This happens when the security keys in wp-config.php change. Replace the API key to fix it.', 'pressqueue' );
			case 'stale':
				return __( 'Something changed while you were working. The page now shows the latest state. Please try again.', 'pressqueue' );
			case 'busy':
				return __( 'PressQueue is already loading channels for this account. Wait a moment, then reload the page.', 'pressqueue' );
			case 'needs_refresh':
				return __( 'Refresh channels first. PressQueue can turn an account on only after it has loaded its channels.', 'pressqueue' );
			case 'no_key':
				return __( 'This account has no saved key. Connect it again with a key from Buffer.', 'pressqueue' );
			case 'channel_unavailable':
				return __( 'This channel cannot be switched on right now. Click Refresh channels to check it again.', 'pressqueue' );
			case 'provider_failure':
				return __( 'Buffer answered in a way PressQueue did not expect. Try again in a few minutes.', 'pressqueue' );
			case 'failed':
				return __( 'PressQueue could not finish this change. Try again.', 'pressqueue' );
		}

		return '';
	}

	/**
	 * Return the plain message for a success code.
	 *
	 * @param string                   $message Message code.
	 * @param string                   $name Connection name.
	 * @param ChannelRecord|null       $channel Channel the action changed.
	 * @param array<int,ChannelRecord> $listed Channels shown on the page.
	 * @param array<string,int>        $usage Enabled rule counts per channel.
	 * @return string
	 */
	private static function success_text( string $message, string $name, ?ChannelRecord $channel, array $listed, array $usage ): string {
		$channel_label = null === $channel ? __( 'The channel', 'pressqueue' ) : self::channel_label( $channel, $listed );
		$used_by       = null === $channel ? 0 : (int) ( $usage[ $channel->public_id ] ?? 0 );

		switch ( $message ) {
			case 'buffer_connected':
				return array() === $listed
					? __( 'Buffer connected.', 'pressqueue' )
					: __( 'Buffer connected. Switch on the channels you want to use.', 'pressqueue' );
			case 'buffer_reconnected':
				return __( 'Buffer reconnected. Channels you had switched on are on again.', 'pressqueue' );
			case 'organization_switched':
				/* translators: %s: Buffer organization name. */
				return sprintf( __( 'Now using %s. Switch on the channels you want to use.', 'pressqueue' ), $name );
			case 'channels_synced':
				return __( 'Channels refreshed.', 'pressqueue' );
			case 'channel_enabled':
				return 0 === $used_by
					/* translators: %s: channel, such as LinkedIn. */
					? sprintf( __( '%s is on. Add it to a rule to share posts there.', 'pressqueue' ), $channel_label )
					/* translators: %s: channel, such as LinkedIn. */
					: sprintf( __( '%s is on.', 'pressqueue' ), $channel_label );
			case 'channel_disabled':
				return 0 === $used_by
					/* translators: %s: channel, such as LinkedIn. */
					? sprintf( __( '%s is off.', 'pressqueue' ), $channel_label )
					/* translators: %s: channel, such as LinkedIn. */
					: sprintf( __( '%s is off. Rules that use it skip it until you switch it back on.', 'pressqueue' ), $channel_label );
			case 'connection_enabled':
				/* translators: %s: Buffer organization name. */
				return sprintf( __( '%s is turned on.', 'pressqueue' ), $name );
			case 'connection_disabled':
				/* translators: %s: Buffer organization name. */
				return sprintf( __( '%s is turned off. Its channels stop sharing until you turn it back on.', 'pressqueue' ), $name );
			case 'connection_disconnected':
				/* translators: %s: Buffer organization name. */
				return sprintf( __( '%s is disconnected and its saved key is deleted.', 'pressqueue' ), $name );
			case 'setup_cancelled':
				return __( 'Setup cancelled. The key was deleted from this site.', 'pressqueue' );
			case 'credential_rotated':
				return __( 'API key replaced.', 'pressqueue' );
			case 'credential_reconnected':
				return __( 'API key replaced. The account is connected again.', 'pressqueue' );
			case 'credential_verified':
				return __( 'Buffer accepted the saved key.', 'pressqueue' );
			case 'connection_created':
				return __( 'Key saved. Finish the setup below.', 'pressqueue' );
			case 'connection_error':
				return __( 'PressQueue could not finish this change. Try again.', 'pressqueue' );
		}

		return '';
	}

	/**
	 * Return a short name for a channel in notices: the network, plus the channel name when needed to tell channels apart.
	 *
	 * @param ChannelRecord            $channel Channel.
	 * @param array<int,ChannelRecord> $listed Channels shown on the page.
	 * @return string
	 */
	private static function channel_label( ChannelRecord $channel, array $listed ): string {
		$service = Labels::service( $channel->service );
		$same    = 0;

		foreach ( $listed as $other ) {
			if ( strtolower( $other->service ) === strtolower( $channel->service ) ) {
				++$same;
			}
		}

		if ( $same <= 1 ) {
			return $service;
		}

		/* translators: 1: social network name, 2: channel name. */
		return sprintf( __( '%1$s (%2$s)', 'pressqueue' ), $service, ActivitySummary::channel_display_name( $channel ) );
	}
}
