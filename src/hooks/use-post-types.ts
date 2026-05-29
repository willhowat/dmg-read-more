import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { applyFilters } from '@wordpress/hooks';
import { DEFAULT_POST_TYPES, type PostTypeConfig } from './use-content-search';

// Narrowed shape of each entry in the /wp/v2/types response.
type WpTypeItem = {
	slug: string;
	rest_base: string;
	name: string;
};

declare global {
	interface Window {
		dmgReadMore?: {
			// Injected by dmg-read-more.php via wp_add_inline_script.
			// Empty array means the PHP filter was not used — no restriction applied.
			allowedPostTypes: string[];
		};
	}
}

// Read once at module load — the value is set synchronously before this script runs.
const phpAllowedSlugs: string[] = window.dmgReadMore?.allowedPostTypes ?? [];

/**
 * Resolves the list of post types available for selection in the block editor.
 *
 * Resolution order:
 *   1. Fetches all publicly available post types from /wp/v2/types.
 *   2. If the `dmg_read_more_post_types` PHP filter provided an allowlist,
 *      restricts the list to those slugs.
 *   3. Applies the `dmg.readMore.postTypes` JS filter for further JS-side
 *      restriction or reordering.
 *   4. Falls back to DEFAULT_POST_TYPES (post + page) if the resolved list is
 *      empty — an empty list would make the search silently return no results.
 *
 * Initialises from DEFAULT_POST_TYPES so the block is usable immediately while
 * the fetch is in flight.
 */
export function usePostTypes(): PostTypeConfig[] {
	const [ postTypes, setPostTypes ] = useState< PostTypeConfig[] >( DEFAULT_POST_TYPES );

	useEffect( () => {
		apiFetch< Record< string, WpTypeItem > >( {
			path: '/wp/v2/types?_fields=slug,rest_base,name',
		} )
			.then( ( data ) => {
				let types: PostTypeConfig[] = Object.values( data ).map( ( t ) => ( {
					slug: t.slug,
					restBase: t.rest_base,
					label: t.name,
				} ) );

				// PHP allowlist: when non-empty, restrict to only the specified slugs.
				// An empty allowlist signals that the PHP filter was not used, so all
				// REST-available types are kept.
				if ( phpAllowedSlugs.length > 0 ) {
					types = types.filter( ( t ) => phpAllowedSlugs.includes( t.slug ) );
				}

				/**
				 * Filters the post types shown in the DMG Read More block search.
				 *
				 * Runs after the PHP allowlist has been applied, so it always receives
				 * a subset of (or equal to) the PHP-allowed types. Use it to further
				 * restrict, reorder, or augment the list from a JS-only context.
				 *
				 * Returning an empty array falls back to DEFAULT_POST_TYPES (post + page),
				 * ensuring the search field always has something to query.
				 *
				 * Example — exclude the Page post type:
				 *   wp.hooks.addFilter(
				 *     'dmg.readMore.postTypes',
				 *     'my-plugin',
				 *     ( types ) => types.filter( ( t ) => t.slug !== 'page' )
				 *   );
				 *
				 * @param {PostTypeConfig[]} types Resolved post type list.
				 * @return {PostTypeConfig[]}
				 */
				const filtered = applyFilters(
					'dmg.readMore.postTypes',
					types
				) as PostTypeConfig[];

				// Guard: if both filters drain the list, fall back to hardcoded defaults
				// so the search field remains functional rather than silently broken.
				setPostTypes( filtered.length > 0 ? filtered : DEFAULT_POST_TYPES );
			} )
			.catch( () => {
				// REST API unavailable — silent fallback keeps the block usable.
			} );
	}, [] );

	return postTypes;
}
