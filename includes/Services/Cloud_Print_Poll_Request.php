<?php
/**
 * Star CloudPRNT poll request body.
 *
 * The printer POSTs a small JSON document on every poll. Until now the plugin
 * discarded it, which cost us two things the protocol hands over for free: the
 * printer telling us it is mid-job (so we must not offer another), and the
 * printer's answers to capability questions we asked in an earlier poll
 * response (`clientAction`). This value object reads both, defensively — the
 * body comes off the public poll route, so every field is untrusted and any of
 * them can be absent, mistyped, or hostile.
 *
 * @package WCPOS\WooCommercePOS\Services
 */

namespace WCPOS\WooCommercePOS\Services;

/**
 * Cloud_Print_Poll_Request class.
 */
class Cloud_Print_Poll_Request {
	/**
	 * Longest accepted answer string, to bound what a printer can make us store.
	 */
	private const MAX_ANSWER_LENGTH = 512;

	/**
	 * Whether the printer reports a job still printing.
	 *
	 * @var bool
	 */
	private $printing_in_progress = false;

	/**
	 * The printer's reported status code (e.g. '200 OK').
	 *
	 * @var string
	 */
	private $status_code = '';

	/**
	 * Answers to `clientAction` requests, keyed by request name.
	 *
	 * @var array<string, string>
	 */
	private $answers = array();

	/**
	 * Parse a poll body.
	 *
	 * WordPress only decodes JSON into `get_json_params()` when the request
	 * carries a JSON content type; Star firmware is not guaranteed to send one,
	 * so the raw body is decoded here as a fallback.
	 *
	 * @param string     $raw_body    The raw request body.
	 * @param array|null $json_params Already-decoded JSON params, when available.
	 *
	 * @return self
	 */
	public static function from_body( string $raw_body, ?array $json_params = null ): self {
		$data = $json_params;
		if ( ! \is_array( $data ) || array() === $data ) {
			$decoded = json_decode( $raw_body, true );
			$data    = \is_array( $decoded ) ? $decoded : array();
		}

		$request = new self();

		// Firmware sends this as a JSON boolean, but the string forms "true"/"false"
		// have been seen in the wild; FILTER_VALIDATE_BOOLEAN reads both and treats
		// anything it cannot parse as false — the safe answer, since a false
		// negative only costs us one poll cycle.
		$request->printing_in_progress = filter_var( $data['printingInProgress'] ?? false, FILTER_VALIDATE_BOOLEAN );

		if ( isset( $data['statusCode'] ) && \is_scalar( $data['statusCode'] ) ) {
			$request->status_code = self::clean( (string) $data['statusCode'] );
		}

		$request->answers = self::parse_client_action( $data['clientAction'] ?? null );

		return $request;
	}

	/**
	 * Whether the printer says it is still printing the previous job.
	 *
	 * @return bool
	 */
	public function printing_in_progress(): bool {
		return $this->printing_in_progress;
	}

	/**
	 * The printer's reported status code, or '' when it sent none.
	 *
	 * @return string
	 */
	public function status_code(): string {
		return $this->status_code;
	}

	/**
	 * Answers to `clientAction` requests, keyed by request name.
	 *
	 * @return array<string, string>
	 */
	public function answers(): array {
		return $this->answers;
	}

	/**
	 * Read the `clientAction` array into request => result pairs.
	 *
	 * The printer echoes the request name alongside its answer. Firmware has
	 * been observed using both `result` and `response` for the answer key, so
	 * both are accepted.
	 *
	 * @param mixed $client_action The raw `clientAction` value.
	 *
	 * @return array<string, string>
	 */
	private static function parse_client_action( $client_action ): array {
		if ( ! \is_array( $client_action ) ) {
			return array();
		}

		$answers = array();
		foreach ( $client_action as $entry ) {
			if ( ! \is_array( $entry ) || ! isset( $entry['request'] ) || ! \is_scalar( $entry['request'] ) ) {
				continue;
			}

			$name = self::clean( (string) $entry['request'] );
			if ( '' === $name ) {
				continue;
			}

			$value = $entry['result'] ?? ( $entry['response'] ?? null );
			if ( \is_array( $value ) ) {
				$value = implode( ',', array_filter( $value, 'is_scalar' ) );
			}
			if ( ! \is_scalar( $value ) ) {
				continue;
			}

			$answers[ $name ] = self::clean( (string) $value );
		}

		return $answers;
	}

	/**
	 * Strip control characters and cap the length of an untrusted field.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string
	 */
	private static function clean( string $value ): string {
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $value );

		return trim( substr( $value, 0, self::MAX_ANSWER_LENGTH ) );
	}
}
