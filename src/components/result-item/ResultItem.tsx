import { __ } from '@wordpress/i18n';
import type { ResultItemProps } from './types';

import { resultItemStyles as S } from './styles';

export function ResultItem( {
	result,
	isSelected,
	postTypeLabel,
	onSelect,
}: ResultItemProps ) {
	let displayUrl = result.url;
	try {
		const { pathname } = new URL( result.url );
		displayUrl = pathname.replace( /\/$/, '' ) || '/';
	} catch {
		// Malformed URL — fall back to the raw value.
	}

	return (
		<button
			type="button"
			className={ `dmg-read-more__result${
				isSelected ? ' is-selected' : ''
			}` }
			onClick={ onSelect }
			aria-pressed={ isSelected }
			style={ S.button( isSelected ) }
		>
			<span style={ S.meta }>
				<span style={ S.title }>
					{ result.title || __( '(no title)', 'dmg-read-more' ) }
				</span>
				<span style={ S.url }>{ displayUrl } (id: { result.id })</span>
			</span>
			<span style={ S.pill }>{ postTypeLabel }</span>
		</button>
	);
}
