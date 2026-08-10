<?php
/**
 * Passkey (WebAuthn) helpers using web-auth/webauthn-lib v5.
 *
 * @package Logliy
 */

defined( 'ABSPATH' ) || exit;

use Cose\Algorithms;
use Cose\Algorithm\Manager as CoseAlgorithmManager;
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\ECDSA\ES256K;
use Cose\Algorithm\Signature\ECDSA\ES384;
use Cose\Algorithm\Signature\ECDSA\ES512;
use Cose\Algorithm\Signature\EdDSA\Ed25519;
use Cose\Algorithm\Signature\RSA\PS256;
use Cose\Algorithm\Signature\RSA\RS256;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Counter\CounterChecker;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;
use Webauthn\TrustPath\TrustPath;

/**
 * Permissive counter checker for platform passkeys (often stay at 0).
 */
class Logliy_Counter_Checker implements CounterChecker {
	public function check( CredentialRecord $credential_record, int $current_counter ): void {
		if ( $current_counter === 0 && $credential_record->counter === 0 ) {
			return;
		}
		if ( $current_counter > 0 && $current_counter <= $credential_record->counter ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CounterException constructor args are integers, not rendered HTML.
			throw \Webauthn\Exception\CounterException::create(
				$current_counter,
				$credential_record->counter,
				'Invalid counter.'
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}
}

/**
 * Whether WebAuthn library is available.
 */
function logliy_passkey_available(): bool {
	return class_exists( PublicKeyCredential::class );
}

/**
 * Recursively drop null values (browsers dislike null WebAuthn fields).
 *
 * @param array<string, mixed> $data Data.
 * @return array<string, mixed>
 */
function logliy_array_remove_nulls( array $data ): array {
	$out = array();
	foreach ( $data as $key => $value ) {
		if ( $value === null ) {
			continue;
		}
		if ( is_array( $value ) ) {
			$value = logliy_array_remove_nulls( $value );
		}
		$out[ $key ] = $value;
	}
	return $out;
}

/**
 * Shared serializer.
 *
 * @return \Symfony\Component\Serializer\SerializerInterface
 */
function logliy_webauthn_serializer() {
	static $serializer = null;
	if ( $serializer !== null ) {
		return $serializer;
	}
	$manager = AttestationStatementSupportManager::create( array( new NoneAttestationStatementSupport() ) );
	$factory = new WebauthnSerializerFactory( $manager );
	$serializer = $factory->create();
	return $serializer;
}

/**
 * Ceremony factory with site origin + algorithms.
 */
function logliy_ceremony_factory(): CeremonyStepManagerFactory {
	$factory = new CeremonyStepManagerFactory();
	$factory->setCounterChecker( new Logliy_Counter_Checker() );

	$origins = array( logliy_origin() );
	$related = logliy_get_setting( 'related_origins', array() );
	if ( is_array( $related ) ) {
		foreach ( $related as $origin ) {
			$origin = untrailingslashit( (string) $origin );
			if ( $origin !== '' ) {
				$origins[] = $origin;
			}
		}
	}
	$factory->setAllowedOrigins( array_values( array_unique( $origins ) ), false );

	$host = logliy_rp_id();
	if ( $host === 'localhost' || str_ends_with( $host, '.local' ) ) {
		$factory->setSecuredRelyingPartyId( array( $host ) );
	}

	$algo = CoseAlgorithmManager::create()->add(
		ES256::create(),
		ES384::create(),
		ES512::create(),
		ES256K::create(),
		RS256::create(),
		PS256::create(),
		Ed25519::create()
	);
	$factory->setAlgorithmManager( $algo );

	return $factory;
}

/**
 * User handle binary string (stable per WP user).
 */
function logliy_user_handle( int $user_id ): string {
	return (string) $user_id;
}

/**
 * Build creation options for registration.
 *
 * @return array{options:array<string,mixed>,challenge_id:string}|WP_Error
 */
function logliy_passkey_register_options( WP_User $user ) {
	if ( ! logliy_passkey_available() ) {
		return new WP_Error( 'logliy_webauthn_missing', __( 'Passkey library is not available.', 'logliy' ), array( 'status' => 500 ) );
	}
	if ( ! logliy_get_setting( 'enable_passkey', true ) ) {
		return new WP_Error( 'logliy_passkey_disabled', __( 'Passkeys are disabled.', 'logliy' ), array( 'status' => 403 ) );
	}
	if ( ! logliy_is_https() && logliy_rp_id() !== 'localhost' ) {
		return new WP_Error( 'logliy_https_required', __( 'HTTPS is required for Passkeys.', 'logliy' ), array( 'status' => 400 ) );
	}

	$challenge = random_bytes( 32 );
	$rp        = PublicKeyCredentialRpEntity::create( logliy_rp_name(), logliy_rp_id() );
	$user_entity = PublicKeyCredentialUserEntity::create(
		$user->user_login,
		logliy_user_handle( (int) $user->ID ),
		$user->display_name !== '' ? $user->display_name : $user->user_login
	);

	$params = array(
		PublicKeyCredentialParameters::createPk( Algorithms::COSE_ALGORITHM_ES256 ),
		PublicKeyCredentialParameters::createPk( Algorithms::COSE_ALGORITHM_ES384 ),
		PublicKeyCredentialParameters::createPk( Algorithms::COSE_ALGORITHM_ES512 ),
		PublicKeyCredentialParameters::createPk( Algorithms::COSE_ALGORITHM_RS256 ),
		PublicKeyCredentialParameters::createPk( Algorithms::COSE_ALGORITHM_EDDSA ),
	);

	$exclude = array();
	foreach ( logliy_db_get_credentials_for_user( (int) $user->ID ) as $row ) {
		$raw = logliy_b64u_decode( (string) $row['credential_id'] );
		if ( $raw === '' ) {
			continue;
		}
		$transports = json_decode( (string) ( $row['transports'] ?? '[]' ), true );
		$exclude[]  = PublicKeyCredentialDescriptor::create(
			PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
			$raw,
			is_array( $transports ) ? $transports : array()
		);
	}

	$uv = (string) logliy_get_setting( 'passkey_uv', 'required' );
	$rk = (string) logliy_get_setting( 'passkey_resident_key', 'required' );

	$selection = AuthenticatorSelectionCriteria::create(
		null,
		$uv,
		$rk
	);

	$options = PublicKeyCredentialCreationOptions::create(
		$rp,
		$user_entity,
		$challenge,
		$params,
		$selection,
		PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
		$exclude,
		120000
	);

	$serializer = logliy_webauthn_serializer();
	$json       = $serializer->serialize( $options, 'json' );
	$decoded    = json_decode( $json, true );
	if ( ! is_array( $decoded ) ) {
		return new WP_Error( 'logliy_options_failed', __( 'Could not build Passkey options.', 'logliy' ), array( 'status' => 500 ) );
	}
	$decoded = logliy_array_remove_nulls( $decoded );

	$challenge_id = wp_generate_uuid4();
	set_transient(
		'logliy_pk_reg_' . $challenge_id,
		array(
			'user_id' => (int) $user->ID,
			'options' => $json,
		),
		5 * MINUTE_IN_SECONDS
	);

	return array(
		'options'      => $decoded,
		'challenge_id' => $challenge_id,
	);
}

/**
 * Verify registration attestation and store credential.
 *
 * @param array<string, mixed> $credential Browser credential JSON.
 * @return array<string, mixed>|WP_Error
 */
function logliy_passkey_register_verify( WP_User $user, string $challenge_id, array $credential, string $name = '' ) {
	if ( ! logliy_passkey_available() ) {
		return new WP_Error( 'logliy_webauthn_missing', __( 'Passkey library is not available.', 'logliy' ), array( 'status' => 500 ) );
	}

	$stored = get_transient( 'logliy_pk_reg_' . $challenge_id );
	delete_transient( 'logliy_pk_reg_' . $challenge_id );
	if ( ! is_array( $stored ) || (int) ( $stored['user_id'] ?? 0 ) !== (int) $user->ID || empty( $stored['options'] ) ) {
		return new WP_Error( 'logliy_challenge_expired', __( 'Passkey challenge expired. Please try again.', 'logliy' ), array( 'status' => 400 ) );
	}

	$serializer = logliy_webauthn_serializer();
	/** @var PublicKeyCredentialCreationOptions $creation_options */
	$creation_options = $serializer->deserialize( (string) $stored['options'], PublicKeyCredentialCreationOptions::class, 'json' );

	$credential_json = wp_json_encode( $credential );
	if ( ! is_string( $credential_json ) ) {
		return new WP_Error( 'logliy_bad_credential', __( 'Invalid Passkey response.', 'logliy' ), array( 'status' => 400 ) );
	}

	/** @var PublicKeyCredential $public_key_credential */
	$public_key_credential = $serializer->deserialize( $credential_json, PublicKeyCredential::class, 'json' );
	$response              = $public_key_credential->response;
	if ( ! $response instanceof AuthenticatorAttestationResponse ) {
		return new WP_Error( 'logliy_bad_response', __( 'Invalid Passkey attestation response.', 'logliy' ), array( 'status' => 400 ) );
	}

	try {
		$factory   = logliy_ceremony_factory();
		$validator = AuthenticatorAttestationResponseValidator::create( $factory->creationCeremony() );
		$host      = wp_parse_url( home_url(), PHP_URL_HOST );
		$host      = is_string( $host ) ? $host : logliy_rp_id();
		$record    = $validator->check( $response, $creation_options, $host );
	} catch ( Throwable $e ) {
		return new WP_Error( 'logliy_attestation_failed', __( 'Passkey registration failed.', 'logliy' ) . ' ' . $e->getMessage(), array( 'status' => 400 ) );
	}

	$cred_id = logliy_b64u_encode( $record->publicKeyCredentialId );
	if ( logliy_db_get_credential_by_id( $cred_id ) ) {
		return new WP_Error( 'logliy_duplicate_credential', __( 'This Passkey is already registered.', 'logliy' ), array( 'status' => 409 ) );
	}

	$trust_json = $serializer->serialize( $record->trustPath, 'json' );
	$label      = $name !== '' ? sanitize_text_field( $name ) : __( 'Passkey', 'logliy' );

	$id = logliy_db_insert_credential(
		array(
			'credential_id'    => $cred_id,
			'user_id'          => (int) $user->ID,
			'public_key'       => $record->credentialPublicKey,
			'sign_count'       => (int) $record->counter,
			'name'             => $label,
			'transports'       => $record->transports,
			'aaguid'           => $record->aaguid->toRfc4122(),
			'attestation_type' => $record->attestationType,
			'trust_path'       => $trust_json,
			'backup_eligible'  => $record->backupEligible,
			'backup_status'    => $record->backupStatus,
			'uv_initialized'   => $record->uvInitialized,
		)
	);

	if ( ! $id ) {
		return new WP_Error( 'logliy_store_failed', __( 'Could not store Passkey.', 'logliy' ), array( 'status' => 500 ) );
	}

	return array(
		'ok'   => true,
		'id'   => $id,
		'name' => $label,
	);
}

/**
 * Build authentication options (discoverable / conditional UI).
 *
 * @param WP_User|null $user Optional user to limit allowCredentials.
 * @return array{options:array<string,mixed>,challenge_id:string}|WP_Error
 */
function logliy_passkey_auth_options( ?WP_User $user = null ) {
	if ( ! logliy_passkey_available() ) {
		return new WP_Error( 'logliy_webauthn_missing', __( 'Passkey library is not available.', 'logliy' ), array( 'status' => 500 ) );
	}
	if ( ! logliy_get_setting( 'enable_passkey', true ) ) {
		return new WP_Error( 'logliy_passkey_disabled', __( 'Passkeys are disabled.', 'logliy' ), array( 'status' => 403 ) );
	}

	$ip_check = logliy_rate_limit_hit( 'pk_options_' . logliy_client_ip(), 60, 15 * MINUTE_IN_SECONDS );
	if ( is_wp_error( $ip_check ) ) {
		return $ip_check;
	}

	$challenge = random_bytes( 32 );
	$allow     = array();

	if ( $user instanceof WP_User ) {
		foreach ( logliy_db_get_credentials_for_user( (int) $user->ID ) as $row ) {
			$raw = logliy_b64u_decode( (string) $row['credential_id'] );
			if ( $raw === '' ) {
				continue;
			}
			$transports = json_decode( (string) ( $row['transports'] ?? '[]' ), true );
			$allow[]    = PublicKeyCredentialDescriptor::create(
				PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
				$raw,
				is_array( $transports ) ? $transports : array()
			);
		}
	}

	$uv = (string) logliy_get_setting( 'passkey_uv', 'required' );

	$options = PublicKeyCredentialRequestOptions::create(
		$challenge,
		logliy_rp_id(),
		$allow,
		$uv,
		120000
	);

	$serializer = logliy_webauthn_serializer();
	$json       = $serializer->serialize( $options, 'json' );
	$decoded    = json_decode( $json, true );
	if ( ! is_array( $decoded ) ) {
		return new WP_Error( 'logliy_options_failed', __( 'Could not build Passkey options.', 'logliy' ), array( 'status' => 500 ) );
	}
	$decoded = logliy_array_remove_nulls( $decoded );
	if ( isset( $decoded['allowCredentials'] ) && $decoded['allowCredentials'] === array() ) {
		unset( $decoded['allowCredentials'] );
	}

	$challenge_id = wp_generate_uuid4();
	set_transient(
		'logliy_pk_auth_' . $challenge_id,
		array(
			'user_id' => $user instanceof WP_User ? (int) $user->ID : 0,
			'options' => $json,
		),
		5 * MINUTE_IN_SECONDS
	);

	return array(
		'options'      => $decoded,
		'challenge_id' => $challenge_id,
	);
}

/**
 * Rebuild CredentialRecord from DB row.
 *
 * @param array<string, mixed> $row DB row.
 */
function logliy_credential_record_from_row( array $row ): CredentialRecord {
	$raw_id     = logliy_b64u_decode( (string) $row['credential_id'] );
	$transports = json_decode( (string) ( $row['transports'] ?? '[]' ), true );
	if ( ! is_array( $transports ) ) {
		$transports = array();
	}

	$trust_path = EmptyTrustPath::create();
	if ( ! empty( $row['trust_path'] ) ) {
		try {
			$trust_path = logliy_webauthn_serializer()->deserialize( (string) $row['trust_path'], TrustPath::class, 'json' );
		} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			$trust_path = EmptyTrustPath::create();
		}
	}

	$aaguid = (string) ( $row['aaguid'] ?? '' );
	try {
		$uuid = $aaguid !== '' ? Uuid::fromString( $aaguid ) : Uuid::fromString( '00000000-0000-0000-0000-000000000000' );
	} catch ( Throwable $e ) {
		$uuid = Uuid::fromString( '00000000-0000-0000-0000-000000000000' );
	}

	return CredentialRecord::create(
		$raw_id,
		PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
		$transports,
		(string) ( $row['attestation_type'] ?? 'none' ),
		$trust_path,
		$uuid,
		(string) $row['public_key'],
		logliy_user_handle( (int) $row['user_id'] ),
		(int) $row['sign_count'],
		null,
		isset( $row['backup_eligible'] ) ? (bool) $row['backup_eligible'] : null,
		isset( $row['backup_status'] ) ? (bool) $row['backup_status'] : null,
		isset( $row['uv_initialized'] ) ? (bool) $row['uv_initialized'] : null
	);
}

/**
 * Verify assertion and log in.
 *
 * @param array<string, mixed> $credential Browser credential JSON.
 * @return array<string, mixed>|WP_Error
 */
function logliy_passkey_auth_verify( string $challenge_id, array $credential, bool $remember = false, string $redirect_to = '' ) {
	if ( ! logliy_passkey_available() ) {
		return new WP_Error( 'logliy_webauthn_missing', __( 'Passkey library is not available.', 'logliy' ), array( 'status' => 500 ) );
	}

	$ip_check = logliy_rate_limit_hit( 'pk_auth_' . logliy_client_ip(), 30, 15 * MINUTE_IN_SECONDS );
	if ( is_wp_error( $ip_check ) ) {
		return $ip_check;
	}

	$stored = get_transient( 'logliy_pk_auth_' . $challenge_id );
	delete_transient( 'logliy_pk_auth_' . $challenge_id );
	if ( ! is_array( $stored ) || empty( $stored['options'] ) ) {
		logliy_fire_login_failed( 'passkey', __( 'Passkey challenge expired.', 'logliy' ) );
		return new WP_Error( 'logliy_challenge_expired', __( 'Passkey challenge expired. Please try again.', 'logliy' ), array( 'status' => 400 ) );
	}

	$serializer = logliy_webauthn_serializer();
	/** @var PublicKeyCredentialRequestOptions $request_options */
	$request_options = $serializer->deserialize( (string) $stored['options'], PublicKeyCredentialRequestOptions::class, 'json' );

	$credential_json = wp_json_encode( $credential );
	if ( ! is_string( $credential_json ) ) {
		return new WP_Error( 'logliy_bad_credential', __( 'Invalid Passkey response.', 'logliy' ), array( 'status' => 400 ) );
	}

	try {
		/** @var PublicKeyCredential $public_key_credential */
		$public_key_credential = $serializer->deserialize( $credential_json, PublicKeyCredential::class, 'json' );
	} catch ( Throwable $e ) {
		logliy_fire_login_failed( 'passkey', __( 'Invalid Passkey response.', 'logliy' ) );
		return new WP_Error( 'logliy_bad_credential', __( 'Invalid Passkey response.', 'logliy' ), array( 'status' => 400 ) );
	}

	$response = $public_key_credential->response;
	if ( ! $response instanceof AuthenticatorAssertionResponse ) {
		logliy_fire_login_failed( 'passkey', __( 'Invalid Passkey assertion.', 'logliy' ) );
		return new WP_Error( 'logliy_bad_response', __( 'Invalid Passkey assertion response.', 'logliy' ), array( 'status' => 400 ) );
	}

	$cred_id = logliy_b64u_encode( $public_key_credential->rawId );
	$row     = logliy_db_get_credential_by_id( $cred_id );
	if ( ! $row ) {
		logliy_fire_login_failed( 'passkey', __( 'Unknown Passkey.', 'logliy' ) );
		return new WP_Error( 'logliy_unknown_credential', __( 'Unknown Passkey.', 'logliy' ), array( 'status' => 401 ) );
	}

	$user = get_user_by( 'id', (int) $row['user_id'] );
	if ( ! $user instanceof WP_User ) {
		logliy_fire_login_failed( 'passkey', __( 'Unknown user.', 'logliy' ) );
		return new WP_Error( 'logliy_unknown_user', __( 'Unknown user for this Passkey.', 'logliy' ), array( 'status' => 401 ) );
	}

	$record = logliy_credential_record_from_row( $row );

	try {
		$factory   = logliy_ceremony_factory();
		$validator = AuthenticatorAssertionResponseValidator::create( $factory->requestCeremony() );
		$host      = wp_parse_url( home_url(), PHP_URL_HOST );
		$host      = is_string( $host ) ? $host : logliy_rp_id();
		$updated   = $validator->check( $record, $response, $request_options, $host, null );
	} catch ( Throwable $e ) {
		logliy_fire_login_failed( $user->user_login, __( 'Passkey verification failed.', 'logliy' ) );
		return new WP_Error( 'logliy_assertion_failed', __( 'Passkey verification failed.', 'logliy' ), array( 'status' => 401 ) );
	}

	logliy_db_touch_credential(
		(int) $row['id'],
		(int) $updated->counter,
		$updated->backupEligible,
		$updated->backupStatus
	);

	if ( $redirect_to !== '' ) {
		$_REQUEST['redirect_to'] = $redirect_to;
	}

	$result = logliy_complete_login( $user, $remember );
	if ( has_filter( 'woocommerce_login_redirect' ) ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core redirect filter.
		$result['redirect'] = (string) apply_filters( 'woocommerce_login_redirect', $result['redirect'], $user );
		$result['redirect'] = logliy_safe_redirect_url( $result['redirect'] );
	}

	return array(
		'ok'       => true,
		'redirect' => $result['redirect'],
	);
}
