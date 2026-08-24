<?php
/**
 * Buffer discovery adapter.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider\Buffer;

use PressQueue\Provider\DiscoveryProvider;
use PressQueue\Provider\Dto\ProviderAccount;
use PressQueue\Provider\Dto\ProviderChannel;
use PressQueue\Provider\Dto\ProviderOrganization;
use PressQueue\Provider\ProviderException;
use PressQueue\Storage\StorageEnums;
use PressQueue\Storage\StorageValidator;

/**
 * Reads Buffer account, organization, and channel data.
 */
final class BufferDiscoveryProvider implements DiscoveryProvider {
	private const PROVIDER      = 'buffer';
	private const ACCOUNT_QUERY = <<<'GRAPHQL'
query PressQueueAccount {
  account {
    id
    email
    name
    organizations {
      id
      name
      channelCount
    }
  }
}
GRAPHQL;

	private const CHANNELS_QUERY = <<<'GRAPHQL'
query PressQueueChannels($input: ChannelsInput!) {
  channels(input: $input) {
    id
    name
    displayName
    service
    organizationId
    isDisconnected
    isLocked
    isQueuePaused
    allowedActions
    externalLink
  }
}
GRAPHQL;

	private const SUPPORTED_SERVICES = array(
		'bluesky',
		'facebook',
		'linkedin',
		'mastodon',
		'threads',
		'twitter',
	);

	/**
	 * GraphQL client.
	 *
	 * @var BufferGraphqlClient
	 */
	private BufferGraphqlClient $client;

	/**
	 * Constructor.
	 *
	 * @param BufferGraphqlClient $client Client.
	 */
	public function __construct( BufferGraphqlClient $client ) {
		$this->client = $client;
	}

	/**
	 * Fetch the authenticated account.
	 *
	 * @param string $credential Opaque provider credential.
	 * @return ProviderAccount
	 */
	public function account( string $credential ): ProviderAccount {
		$data    = $this->client->request( 'PressQueueAccount', self::ACCOUNT_QUERY, array(), $credential );
		$account = $data['account'] ?? null;

		if ( ! is_array( $account ) || array_is_list( $account ) ) {
			throw new ProviderException( 'malformed_response', 'Provider account response was malformed.' );
		}

		$id = $account['id'] ?? null;
		if ( ! is_string( $id ) || '' === $id ) {
			throw new ProviderException( 'malformed_response', 'Provider account response did not include an account ID.' );
		}

		StorageValidator::assert_remote_identifier( 'Remote account ID', $id );
		$display_label = $this->display_label( $account['name'] ?? null, $account['email'] ?? null, $id );
		$organizations = $account['organizations'] ?? null;

		if ( ! is_array( $organizations ) ) {
			throw new ProviderException( 'malformed_response', 'Provider account response did not include organizations.' );
		}

		$organization_dtos = array();
		foreach ( $organizations as $organization ) {
			$organization_dtos[] = $this->organization( $organization );
		}

		return new ProviderAccount( self::PROVIDER, $id, $display_label, $organization_dtos );
	}

	/**
	 * Fetch channels for one organization.
	 *
	 * @param string $credential Opaque provider credential.
	 * @param string $remote_organization_id Remote organization ID.
	 * @return array<int,ProviderChannel>
	 */
	public function channels( string $credential, string $remote_organization_id ): array {
		StorageValidator::assert_remote_identifier( 'Remote organization ID', $remote_organization_id );
		$data = $this->client->request(
			'PressQueueChannels',
			self::CHANNELS_QUERY,
			array(
				'input' => array(
					'organizationId' => $remote_organization_id,
				),
			),
			$credential
		);

		$channels = $data['channels'] ?? null;
		if ( ! is_array( $channels ) ) {
			throw new ProviderException( 'malformed_response', 'Provider channel response did not include channels.' );
		}

		$dtos = array();
		foreach ( $channels as $channel ) {
			if ( ! is_array( $channel ) || array_is_list( $channel ) ) {
				throw new ProviderException( 'malformed_response', 'Provider channel response contained a malformed channel.' );
			}

			$dtos[] = $this->channel( $channel, $remote_organization_id );
		}

		return $dtos;
	}

	/**
	 * Normalize one organization.
	 *
	 * @param mixed $organization Organization payload.
	 * @return ProviderOrganization
	 */
	private function organization( $organization ): ProviderOrganization {
		if ( ! is_array( $organization ) || array_is_list( $organization ) ) {
			throw new ProviderException( 'malformed_response', 'Provider organization response was malformed.' );
		}

		$id = $organization['id'] ?? null;
		if ( ! is_string( $id ) || '' === $id ) {
			throw new ProviderException( 'malformed_response', 'Provider organization response did not include an organization ID.' );
		}

		StorageValidator::assert_remote_identifier( 'Remote organization ID', $id );
		$name          = $organization['name'] ?? null;
		$channel_count = $organization['channelCount'] ?? 0;

		if ( ! is_int( $channel_count ) ) {
			$channel_count = 0;
		}

		return new ProviderOrganization( self::PROVIDER, $id, $this->display_label( $name, null, $id ), max( 0, $channel_count ) );
	}

	/**
	 * Normalize one channel.
	 *
	 * @param array<string,mixed> $channel Channel payload.
	 * @param string              $selected_organization_id Selected organization ID.
	 * @return ProviderChannel
	 */
	private function channel( array $channel, string $selected_organization_id ): ProviderChannel {
		$id              = $channel['id'] ?? null;
		$organization_id = $channel['organizationId'] ?? null;
		$service         = $channel['service'] ?? null;

		if ( ! is_string( $id ) || '' === $id || ! is_string( $organization_id ) || '' === $organization_id || ! is_string( $service ) || '' === $service ) {
			throw new ProviderException( 'malformed_response', 'Provider channel response missed required identity fields.' );
		}

		StorageValidator::assert_remote_identifier( 'Remote channel ID', $id );
		StorageValidator::assert_remote_identifier( 'Remote organization ID', $organization_id );
		StorageValidator::assert_text_key( 'Service', $service );

		$name         = $this->string_or_fallback( $channel['name'] ?? null, $id );
		$display_name = $this->string_or_fallback( $channel['displayName'] ?? null, $name );
		$external     = isset( $channel['externalLink'] ) && is_string( $channel['externalLink'] ) ? $channel['externalLink'] : null;
		$flags        = $this->raw_status_flags( $channel );
		$actions      = $this->allowed_actions( $channel['allowedActions'] ?? null );
		$reason       = $this->unavailable_reason( $service, $organization_id, $selected_organization_id, $flags, $actions );
		$availability = null === $reason ? 'available' : 'unsupported';
		$capability   = array(
			'delivery'       => array(
				'schedule_slots' => null === $reason ? array( 'immediate' ) : array(),
				'text'           => null === $reason,
			),
			'schema_version' => 1,
			'service'        => $service,
		);

		StorageEnums::assert_channel_availability( $availability );

		return new ProviderChannel(
			self::PROVIDER,
			$organization_id,
			$id,
			$name,
			$display_name,
			$service,
			$external,
			$flags,
			$actions,
			$availability,
			$capability,
			$reason
		);
	}

	/**
	 * Return raw status flags, using unsupported sentinels for unknown shapes.
	 *
	 * @param array<string,mixed> $channel Channel payload.
	 * @return array<string,mixed>
	 */
	private function raw_status_flags( array $channel ): array {
		$flags = array();

		foreach ( array( 'isDisconnected', 'isLocked', 'isQueuePaused' ) as $key ) {
			$value         = $channel[ $key ] ?? null;
			$flags[ $key ] = is_bool( $value ) ? $value : 'unknown';
		}

		return $flags;
	}

	/**
	 * Return allowed actions.
	 *
	 * @param mixed $value Raw allowed actions.
	 * @return array<int,string>
	 */
	private function allowed_actions( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$actions = array();
		foreach ( $value as $action ) {
			if ( ! is_string( $action ) || '' === $action ) {
				return array();
			}

			$actions[] = $action;
		}

		return array_values( array_unique( $actions ) );
	}

	/**
	 * Return an unavailable reason or null when the channel is compatible.
	 *
	 * @param string              $service Service.
	 * @param string              $organization_id Channel organization ID.
	 * @param string              $selected_organization_id Selected organization ID.
	 * @param array<string,mixed> $flags Status flags.
	 * @param array<int,string>   $actions Allowed actions.
	 * @return string|null
	 */
	private function unavailable_reason( string $service, string $organization_id, string $selected_organization_id, array $flags, array $actions ): ?string {
		if ( $organization_id !== $selected_organization_id ) {
			return 'organization_mismatch';
		}

		if ( ! in_array( $service, self::SUPPORTED_SERVICES, true ) ) {
			return 'unsupported_service';
		}

		foreach ( array( 'isDisconnected', 'isLocked', 'isQueuePaused' ) as $flag ) {
			if ( ! is_bool( $flags[ $flag ] ?? null ) ) {
				return 'unknown_status_flags';
			}
		}

		if ( true === $flags['isDisconnected'] ) {
			return 'disconnected';
		}

		if ( true === $flags['isLocked'] ) {
			return 'locked';
		}

		if ( true === $flags['isQueuePaused'] ) {
			return 'queue_paused';
		}

		if ( ! in_array( 'manageUpdates', $actions, true ) ) {
			return 'missing_manage_updates';
		}

		return null;
	}

	/**
	 * Return a display label from preferred fields.
	 *
	 * @param mixed  $primary Primary label.
	 * @param mixed  $secondary Secondary label.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private function display_label( $primary, $secondary, string $fallback ): string {
		if ( is_string( $primary ) && '' !== trim( $primary ) ) {
			return $primary;
		}

		if ( is_string( $secondary ) && '' !== trim( $secondary ) ) {
			return $secondary;
		}

		return $fallback;
	}

	/**
	 * Return a string or fallback.
	 *
	 * @param mixed  $value Value.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private function string_or_fallback( $value, string $fallback ): string {
		return is_string( $value ) && '' !== trim( $value ) ? $value : $fallback;
	}
}
