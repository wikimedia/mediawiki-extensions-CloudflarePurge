<?php

use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class CloudflarePurge {

		// If a page is transcluded in more than this many places, purge everything
		// rather than making many individual API calls (Cloudflare limit is 30 per request)
		const MAX_INDIVIDUAL_PURGES = 30;

	/**
	 * Purge the Cloudflare cache of the changed page
	 *
	 * WikiPage $wikiPage
	 */
	public static function onPageSaveComplete( WikiPage $wikiPage ) {
			$title = $wikiPage->getTitle();
			$urls = [ $title->getFullURL() ];

			try {
					$transclusions = self::getTransclusionUrls( $title );
					if ( $transclusions === null ) {
							self::purgeEverything();
							return;
					}
					$urls = array_merge( $urls, $transclusions );
			} catch ( Exception $e ) {
					// Log but don't fail — still purge the edited page itself
					wfLogWarning( 'CloudflarePurge: transclusion lookup failed: ' . $e->getMessage() );
			}

			self::purgeUrls( $urls );
	}

	 /**
	 * Purge URL when a page is deleted
	 *
	 * MediaWiki\Page\ProperPageIdentity $page
	 */
	public static function onPageDeleteComplete( MediaWiki\Page\ProperPageIdentity $page ) {
		$title = Title::newFromPageIdentity( $page );
		self::purgeUrls( [ $title->getFullURL() ] );
	}
		
	/**
	 * Get URLs of pages that transclude the given title.
	 * Returns null if there are too many (caller should purge everything instead).
	*/
	private static function getTransclusionUrls( Title $title ): ?array {
		$dbr = MediaWikiServices::getInstance()->getConnectionProvider()->getReplicaDatabase();

		// templatelinks joins through linktarget in MW 1.43
		$res = $dbr->select(
			[ 'templatelinks', 'linktarget', 'page' ],
			[ 'page_namespace', 'page_title' ],
			[
				'lt_namespace' => $title->getNamespace(),
				'lt_title'	 => $title->getDBkey(),
			],
			__METHOD__,
			[ 'LIMIT' => self::MAX_INDIVIDUAL_PURGES + 1 ],
						[
								'linktarget' => [ 'INNER JOIN', 'tl_target_id = lt_id' ],
								'page'	   => [ 'INNER JOIN', 'page_id = tl_from' ],
						]
				);

				if ( $res->numRows() > self::MAX_INDIVIDUAL_PURGES ) {
						return null;
				}

				$urls = [];
				foreach ( $res as $row ) {
						$t = Title::makeTitle( $row->page_namespace, $row->page_title );
						$urls[] = $t->getFullURL();
				}
				return $urls;
		}
		
		private static function getAuthHeaders(): ?array {
				$config = MediaWikiServices::getInstance()->getMainConfig();
				$purgeToken = $config->get( 'CloudflarePurgeToken' );
				$authEmail  = $config->get( 'CloudflarePurgeAuthEmail' );
				$authKey	= $config->get( 'CloudflarePurgeAuthKey' );

				if ( $purgeToken ) {
						return [
								'Authorization: Bearer ' . $purgeToken,
								'Content-Type: application/json',
						];
				} elseif ( $authEmail && $authKey ) {
						return [
								'X-Auth-Email: ' . $authEmail,
								'X-Auth-Key: ' . $authKey,
								'Content-Type: application/json',
						];
				}
				return null;
		}
		
		private static function cfRequest( string $zoneID, array $data ): void {
				$headers = self::getAuthHeaders();
				if ( !$headers ) {
						return;
				}

				$curl = curl_init();
				curl_setopt( $curl, CURLOPT_URL, 'https://api.cloudflare.com/client/v4/zones/' . $zoneID . '/purge_cache' );
				curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
				curl_setopt( $curl, CURLOPT_POST, true );
				curl_setopt( $curl, CURLOPT_POSTFIELDS, json_encode( $data ) );
				curl_setopt( $curl, CURLOPT_HTTPHEADER, $headers );

				$response = curl_exec( $curl );
				$result = json_decode( $response, true );

				if ( !is_array( $result ) || !isset( $result['success'] ) ) {
						throw new RuntimeException( 'Invalid response from Cloudflare API' );
				}
				if ( !$result['success'] ) {
						$messages = array_column( $result['errors'] ?? [], 'message' );
						throw new RuntimeException( 'Cloudflare API Error: ' . implode( ', ', $messages ) );
				}
		}
		
		public static function purgeUrls( array $urls ): void {
				$config = MediaWikiServices::getInstance()->getMainConfig();
				$zoneID = $config->get( 'CloudflarePurgeZoneID' );
				if ( !$zoneID || !$urls ) {
						return;
				}

				// Cloudflare accepts max 30 URLs per request — batch if needed
				foreach ( array_chunk( $urls, 30 ) as $batch ) {
						self::cfRequest( $zoneID, [ 'files' => $batch ] );
				}
		}

		public static function purgeEverything(): void {
				$config = MediaWikiServices::getInstance()->getMainConfig();
				$zoneID = $config->get( 'CloudflarePurgeZoneID' );
				if ( !$zoneID ) {
						return;
				}
				self::cfRequest( $zoneID, [ 'purge_everything' => true ] );
		}
}
