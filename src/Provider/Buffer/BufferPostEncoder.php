<?php
/**
 * Buffer delivery encoder.
 *
 * @package PressQueue
 */

declare(strict_types=1);

namespace PressQueue\Provider\Buffer;

use PressQueue\Provider\Dto\ProviderDeliveryRequest;
use PressQueue\Storage\CanonicalJson;
use PressQueue\Storage\ValidationException;

/**
 * Encodes immutable payload schema v2 into Buffer createPost input v2.
 */
final class BufferPostEncoder {
	public const OPERATION_NAME = 'PressQueueCreatePost';
	public const OPERATION      = <<<'GRAPHQL'
mutation PressQueueCreatePost($input: CreatePostInput!) {
  createPost(input: $input) {
    __typename
    ... on PostActionSuccess {
      post { id status dueAt shareMode schedulingType text }
    }
    ... on LimitReachedError { message }
    ... on InvalidInputError { message }
    ... on UnauthorizedError { message }
    ... on NotFoundError { message }
    ... on UnexpectedError { message }
    ... on RestProxyError { message }
    ... on MutationError { message }
  }
}
GRAPHQL;

	/**
	 * Build a deterministic request body.
	 *
	 * @param ProviderDeliveryRequest $request Request.
	 * @return string JSON body.
	 */
	public function body( ProviderDeliveryRequest $request ): string {
		return CanonicalJson::encode_object(
			array(
				'operationName' => self::OPERATION_NAME,
				'query'         => self::OPERATION,
				'variables'     => $this->variables( $request ),
			)
		);
	}

	/**
	 * Build deterministic GraphQL variables.
	 *
	 * @param ProviderDeliveryRequest $request Request.
	 * @return array<string,mixed>
	 */
	public function variables( ProviderDeliveryRequest $request ): array {
		if ( 2 !== $request->payload_schema_version ) {
			throw new ValidationException( 'Delivery payload schema version is unsupported.' );
		}

		if ( 2 !== $request->adapter_encoding_version ) {
			throw new ValidationException( 'Delivery adapter encoding version is unsupported.' );
		}

		$input = array(
			'assets'         => $this->assets( $request ),
			'channelId'      => $request->bound_remote_channel_id,
			'mode'           => $this->mode( $request ),
			'needsApproval'  => false,
			'schedulingType' => 'automatic',
			'text'           => $this->text( $request ),
		);

		if ( 'draft' === $request->schedule_slot ) {
			$input['saveToDraft'] = true;
		}

		if ( 'explicit_time' === $request->schedule_slot ) {
			if ( null === $request->scheduled_for_utc ) {
				throw new ValidationException( 'Explicit delivery schedule is missing a due time.' );
			}

			$input['dueAt'] = $this->due_at( $request->scheduled_for_utc );
		}

		return array( 'input' => $input );
	}

	/**
	 * Compose Buffer text from immutable message and canonical URL.
	 *
	 * @param ProviderDeliveryRequest $request Request.
	 * @return string
	 */
	public function text( ProviderDeliveryRequest $request ): string {
		return $request->final_encoded_text;
	}

	/**
	 * Map provider-neutral schedule slots to Buffer modes.
	 *
	 * @param ProviderDeliveryRequest $request Request.
	 * @return string
	 */
	private function mode( ProviderDeliveryRequest $request ): string {
		switch ( $request->schedule_slot ) {
			case 'immediate':
				return 'shareNow';
			case 'queue_next':
				return 'shareNext';
			case 'queue_end':
			case 'draft':
				return 'addToQueue';
			case 'explicit_time':
				return 'customScheduled';
		}

		throw new ValidationException( 'Delivery schedule slot is unsupported.' );
	}

	/**
	 * Return ordered image assets.
	 *
	 * @param ProviderDeliveryRequest $request Request.
	 * @return array<int,array<string,mixed>>
	 */
	private function assets( ProviderDeliveryRequest $request ): array {
		$items = isset( $request->media_snapshot['items'] ) && is_array( $request->media_snapshot['items'] )
			? $request->media_snapshot['items']
			: array();

		$assets = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['url'] ) || ! is_string( $item['url'] ) ) {
				throw new ValidationException( 'Delivery media snapshot is invalid.' );
			}

			$image = array(
				'url' => $item['url'],
			);

			if ( isset( $item['alt_text'] ) && is_string( $item['alt_text'] ) && '' !== trim( $item['alt_text'] ) ) {
				$image['metadata'] = array( 'altText' => $item['alt_text'] );
			}

			$assets[] = array( 'image' => $image );
		}

		return $assets;
	}

	/**
	 * Convert storage UTC to provider ISO 8601 milliseconds.
	 *
	 * @param string $scheduled_for_utc Storage UTC datetime.
	 * @return string
	 */
	private function due_at( string $scheduled_for_utc ): string {
		$datetime = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $scheduled_for_utc, new \DateTimeZone( 'UTC' ) );

		if ( false === $datetime ) {
			throw new ValidationException( 'Explicit delivery schedule due time is invalid.' );
		}

		return $datetime->format( 'Y-m-d\TH:i:s.000\Z' );
	}
}
