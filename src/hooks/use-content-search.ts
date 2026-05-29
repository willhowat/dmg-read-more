import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { decodeEntities } from '@wordpress/html-entities';
import { useDebouncedInput } from './use-debounced-input';

export type SearchResult = {
	id: number;
	title: string;
	url: string;
	postType: string;
};

export type PostTypeConfig = {
	slug: string;
	restBase: string;
	label: string;
};

export type UseContentSearchReturn = {
	query: string;
	setQuery: ( value: string ) => void;
	debouncedQuery: string;
	results: SearchResult[];
	isLoading: boolean;
	error: string | null;
	page: number;
	setPage: ( page: number | ( ( prev: number ) => number ) ) => void;
	totalPages: number;
};

// Narrowed shape returned by /wp/v2/search
type WpSearchItem = {
	id: number;
	title: string;
	url: string;
	subtype: string;
};

// Narrowed shape returned by /wp/v2/{type}/{id}
type WpPost = {
	id: number;
	title: { rendered: string };
	link: string;
	type: string;
};

export const DEFAULT_POST_TYPES: PostTypeConfig[] = [
	{ slug: 'post', restBase: 'posts', label: 'Post' },
	{ slug: 'page', restBase: 'pages', label: 'Page' },
];

function isIdLookup( value: string ): boolean {
	const trimmed = value.trim();
	return /^\d+$/.test( trimmed ) && parseInt( trimmed, 10 ) > 0;
}

/**
 * Fetches posts matching a search query, normalized for use in the block editor.
 *
 * Query mode is detected automatically from the debounced input value:
 * - Empty string   → recent posts via `/wp/v2/search` (no search param)
 * - Numeric string → ID lookup via each post type's individual REST endpoint in parallel
 * - Any other text → keyword search via `/wp/v2/search?search=…`
 *
 * Entity titles and post URLs are decoded before being returned. Only published
 * posts are included; draft/trashed/private posts are excluded.
 *
 * @param {PostTypeConfig[]} postTypes      Post types to search across. Defaults to post and page.
 *                                          Pass a stable reference (constant or memoised) to avoid
 *                                          triggering unnecessary re-fetches.
 * @param {number}           perPage        Results per page for keyword/recent modes. Defaults to 10.
 * @param {boolean}          skipEmptyQuery Skip fetching when the query is empty. Defaults to false.
 *
 * @return {UseContentSearchReturn} Live query state, paginated results, and loading/error flags.
 */
export function useContentSearch(
	postTypes: PostTypeConfig[] = DEFAULT_POST_TYPES,
	perPage: number = 10,
	skipEmptyQuery: boolean = false
): UseContentSearchReturn {
	const [ query, setQuery, debouncedQuery ] = useDebouncedInput( '' );
	const [ results, setResults ] = useState< SearchResult[] >( [] );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ page, setPage ] = useState( 1 );
	const [ totalPages, setTotalPages ] = useState( 1 );

	// Stable ref so the effect always has the current postTypes without being
	// triggered by reference churn when the array is defined inline by a parent.
	const postTypesRef = useRef( postTypes );
	postTypesRef.current = postTypes;

	// String key used as the effect dependency — stable across re-renders as
	// long as the set of slugs doesn't change.
	const postTypeKey = postTypes
		.map( ( pt ) => pt.slug )
		.sort()
		.join( ',' );

	// Reset to page 1 whenever the debounced query changes.
	// setPage(1) when page is already 1 is a React bail-out no-op, so no mount guard is needed.
	useEffect( () => {
		setPage( 1 );
	}, [ debouncedQuery, setPage ] ); // setPage is a stable useState setter — included to satisfy exhaustive-deps, not as a trigger

	useEffect( () => {
		if ( skipEmptyQuery && ! debouncedQuery.trim() ) {
			setResults( [] );
			setIsLoading( false );
			setTotalPages( 1 );
			return;
		}

		let cancelled = false;

		const run = async () => {
			setIsLoading( true );
			setError( null );

			const types = postTypesRef.current;

			try {
				if ( isIdLookup( debouncedQuery ) ) {
					// Try each registered post type endpoint in parallel.
					const id = parseInt( debouncedQuery.trim(), 10 );
					const settled = await Promise.allSettled(
						types.map( ( pt ) =>
							apiFetch< WpPost >( {
								path: `/wp/v2/${ pt.restBase }/${ id }?status=publish&_fields=id,title,link,type`,
							} )
						)
					);

					if ( ! cancelled ) {
						const found: SearchResult[] = settled
							.filter(
								( r ): r is PromiseFulfilledResult< WpPost > =>
									r.status === 'fulfilled'
							)
							.map( ( r ) => ( {
								id: r.value.id,
								title: decodeEntities( r.value.title.rendered ),
								url: r.value.link,
								postType: r.value.type,
							} ) );

						setResults( found );
						setTotalPages( 1 );
					}
				} else {
					// Keyword search or recent posts via the unified /wp/v2/search endpoint.
					const params = new URLSearchParams( {
						type: 'post',
						subtype: types.map( ( pt ) => pt.slug ).join( ',' ),
						per_page: String( perPage ),
						page: String( page ),
						_fields: 'id,title,url,subtype',
					} );

					const trimmedQuery = debouncedQuery.trim();
					if ( trimmedQuery ) {
						params.set( 'search', trimmedQuery );
					}

					const response = ( await apiFetch( {
						path: `/wp/v2/search?${ params.toString() }`,
						parse: false,
					} ) ) as unknown as Response;

					if ( ! response.ok ) {
						throw new Error(
							response.statusText || 'Failed to load posts.'
						);
					}

					const pages =
						parseInt(
							response.headers.get( 'X-WP-TotalPages' ) ?? '1',
							10
						) || 1;
					const data: WpSearchItem[] = await response.json();

					if ( ! cancelled ) {
						setTotalPages( pages );
						setResults(
							data.map( ( item ) => ( {
								id: item.id,
								title: decodeEntities( item.title ),
								url: item.url,
								postType: item.subtype,
							} ) )
						);
					}
				}
			} catch ( err: unknown ) {
				if ( ! cancelled ) {
					setError(
						err instanceof Error
							? err.message
							: 'Failed to load posts.'
					);
					setResults( [] );
				}
			} finally {
				if ( ! cancelled ) {
					setIsLoading( false );
				}
			}
		};

		run();

		return () => {
			cancelled = true;
		};
	}, [ debouncedQuery, page, perPage, postTypeKey, skipEmptyQuery ] );

	return {
		query,
		setQuery,
		debouncedQuery,
		results,
		isLoading,
		error,
		page,
		setPage,
		totalPages,
	};
}
