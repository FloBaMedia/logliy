<?php
/**
 * Email access rules shared by code, link and SSO login.
 *
 * @package Logliy
 */
defined( 'ABSPATH' ) || exit;

/** Validate one address, exact domain or local-part wildcard rule. */
function logliy_email_rule_valid( string $rule ): bool {
	$rule = strtolower( trim( $rule ) );
	if ( str_starts_with( $rule, '@' ) ) {
		return (bool) is_email( 'logliy' . $rule );
	}
	if ( substr_count( $rule, '@' ) !== 1 ) {
		return false;
	}
	[ $local, $domain ] = explode( '@', $rule, 2 );
	return $local !== '' && ! str_contains( $domain, '*' ) && (bool) is_email( str_replace( '*', 'x', $local ) . '@' . $domain );
}

/** Match complete addresses; domains never match subdomains or suffixes. */
function logliy_email_matches_rules( string $email, string $rules ): bool {
	$email = strtolower( trim( $email ) );
	if ( ! is_email( $email ) ) {
		return false;
	}
	foreach ( preg_split( '/\r\n|\r|\n/', $rules ) as $rule ) {
		$rule = strtolower( trim( $rule ) );
		if ( ! logliy_email_rule_valid( $rule ) ) {
			continue;
		}
		if ( str_starts_with( $rule, '@' ) ) {
			if ( substr( $email, strrpos( $email, '@' ) ) === $rule ) {
				return true;
			}
		} elseif ( preg_match( '/\A' . str_replace( '\\*', '[^@]*', preg_quote( $rule, '/' ) ) . '\z/iD', $email ) === 1 ) {
			return true;
		}
	}
	return false;
}

/** Check the stored account email, including when login uses a username. */
function logliy_email_login_allowed( WP_User $user ): bool {
	return ! logliy_get_setting( 'email_allowlist_enabled', false )
		|| logliy_email_matches_rules( $user->user_email, (string) logliy_get_setting( 'email_allowed_addresses', '' ) );
}
