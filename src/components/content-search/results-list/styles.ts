import type { CSSProperties } from 'react';

export const resultsListStyles = {
	statusRow: {
		display: 'flex',
		alignItems: 'center',
		gap: '8px',
		minHeight: '20px',
	} as CSSProperties,

	statusLabel: {
		margin: 0,
		fontSize: '11px',
		fontWeight: 600,
		textTransform: 'uppercase',
		letterSpacing: '0.04em',
		color: '#757575',
		flex: 1,
	} as CSSProperties,

	errorText: {
		margin: 0,
		fontSize: '12px',
		color: 'rgb(204, 0, 0)',
	} as CSSProperties,

	emptyText: {
		margin: 0,
		fontSize: '12px',
		color: '#757575',
	} as CSSProperties,

	list: {
		listStyle: 'none',
		margin: 0,
		padding: 0,
		display: 'flex',
		flexDirection: 'column',
		gap: '2px',
	} as CSSProperties,

	pagination: {
		display: 'flex',
		alignItems: 'center',
		justifyContent: 'space-between',
		marginTop: '4px',
	} as CSSProperties,

	pageIndicator: {
		fontSize: '11px',
		color: '#757575',
	} as CSSProperties,
};
