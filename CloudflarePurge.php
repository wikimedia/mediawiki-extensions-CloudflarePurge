<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class CloudflarePurge {

	/**
	 * Purge the Cloudflare cache of the changed page
	 *
	 * WikiPage $wikiPage
	 */
	public static function onPageSaveComplete( WikiPage $wikiPage ) {
		$title = $wikiPage->getTitle();
		$url = $title->getFullURL();
		self::purge( $url );
	}

	/**
	 * Purge URL when a page is deleted
	 *
	 * MediaWiki\Page\ProperPageIdentity $page
	 */
	public static function onPageDeleteComplete( MediaWiki\Page\ProperPageIdentity $page ) {
		$title = Title::newFromPageIdentity( $page );
		$url = $title->getFullURL();
		self::purge( $url );
	}

	/**
	 * Purge the given URL
	 *
	 * string $url URL of the page to purge
	 */
	public static function purge( string $url ) {
		// Make sure all the necessary config is set
		$config = MediaWikiServices::getInstance()->getMainConfig();
		$zoneID = $config->get( 'CloudflarePurgeZoneID' );
		$authEmail = $config->get( 'CloudflarePurgeAuthEmail' );
		$authKey = $config->get( 'CloudflarePurgeAuthKey' );
		if ( !$zoneID || !$authEmail || !$authKey ) {
			return;
		}

		// Send the purge request to Cloudflare
		$curl = curl_init();
		curl_setopt( $curl, CURLOPT_URL, 'https://api.cloudflare.com/client/v4/zones/' . $zoneID . '/purge_cache' );
		curl_setopt( $curl, CURLOPT_CUSTOMREQUEST, 'DELETE' );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
		$headers = [
			'X-Auth-Email: ' . $authEmail,
			'X-Auth-Key: ' . $authKey,
			'Content-Type: application/json'
		];
		$data = json_encode( [ 'files' => [ $url ] ] );
		curl_setopt( $curl, CURLOPT_POST, true );
		curl_setopt( $curl, CURLOPT_POSTFIELDS, $data );
		curl_setopt( $curl, CURLOPT_HTTPHEADER, $headers );
		$response = curl_exec( $curl );
		$result = json_decode( $response, true );
		curl_close( $curl );
	}
}
