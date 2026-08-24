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
 * Encodes immutable payload schema v1 into Buffer createPost input v1.
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
		if ( 1 !== $request->payload_schema_version ) {
			throw new ValidationException( 'Delivery payload schema version is unsupported.' );
		}

		if ( 1 !== $request->adapter_encoding_version ) {
			throw new ValidationException( 'Delivery adapter encoding version is unsupported.' );
		}

		return array(
			'input' => array(
				'channelId'      => $request->bound_remote_channel_id,
				'mode'           => 'shareNow',
				'schedulingType' => 'automatic',
				'text'           => $this->text( $request ),
			),
		);
	}

	/**
	 * Compose Buffer text from immutable message and canonical URL.
	 *
	 * @param ProviderDeliveryRequest $request Request.
	 * @return string
	 */
	public function text( ProviderDeliveryRequest $request ): string {
		return trim( $request->message ) . "\n\n" . $request->canonical_url;
	}
}
