import type { CSSProperties } from 'react';

const buttonBase: CSSProperties = {
	display: 'flex',
	alignItems: 'center',
	gap: '8px',
	width: '100%',
	textAlign: 'left',
	padding: '6px 8px',
	borderRadius: '2px',
	cursor: 'pointer',
	font: 'inherit',
	color: 'inherit',
};

export const resultItemStyles = {
	buttonDefault: {
		...buttonBase,
		border: '1px solid transparent',
		background: 'transparent',
	} as CSSProperties,

	buttonSelected: {
		...buttonBase,
		border: '1px solid var(--wp-admin-theme-color, #3858e9)',
		background: 'rgba(var(--wp-admin-theme-color--rgb, 56, 88, 233), 0.06)',
	} as CSSProperties,

	meta: {
		display: 'flex',
		flexDirection: 'column',
		minWidth: 0,
		flex: 1,
	} as CSSProperties,

	title: {
		overflow: 'hidden',
		textOverflow: 'ellipsis',
		whiteSpace: 'nowrap',
		fontSize: '13px',
		fontWeight: 500,
		lineHeight: '1.4',
	} as CSSProperties,

	url: {
		overflow: 'hidden',
		textOverflow: 'ellipsis',
		whiteSpace: 'nowrap',
		fontSize: '11px',
		color: '#757575',
		lineHeight: '1.4',
		marginTop: '1px',
	} as CSSProperties,

	pill: {
		display: 'inline-flex',
		alignItems: 'center',
		padding: '1px 5px',
		borderRadius: '2px',
		fontSize: '10px',
		fontWeight: 600,
		lineHeight: '1.4',
		backgroundColor: '#f0f0f0',
		color: '#757575',
		textTransform: 'uppercase',
		letterSpacing: '0.05em',
		flexShrink: 0,
		whiteSpace: 'nowrap',
	} as CSSProperties,
};
