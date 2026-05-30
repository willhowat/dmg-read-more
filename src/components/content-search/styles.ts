import type { CSSProperties } from 'react';

export const contentSearchStyles = {
	root: {
		display: 'flex',
		flexDirection: 'column',
		gap: '8px',
	} as CSSProperties,

	idleHint: {
		margin: 0,
		fontSize: '12px',
		color: '#757575',
		fontStyle: 'italic',
	} as CSSProperties,
};
