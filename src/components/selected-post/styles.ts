import type { CSSProperties } from 'react';

export const selectedPostStyles = {
	root: {
		marginBottom: '16px',
	} as CSSProperties,

	label: {
		fontSize: '11px',
		fontWeight: 600,
		textTransform: 'uppercase',
		letterSpacing: '0.05em',
		color: '#757575',
		margin: '0 0 6px',
	} as CSSProperties,

	itemWrapper: {
		position: 'relative',
	} as CSSProperties,

	itemOverlay: {
		pointerEvents: 'none',
	} as CSSProperties,

	removeButton: {
		position: 'absolute',
		top: '-7px',
		right: '-7px',
		width: '20px',
		height: '20px',
		padding: 0,
		border: '1px solid #ddd',
		borderRadius: '50%',
		background: '#fff',
		cursor: 'pointer',
		display: 'flex',
		alignItems: 'center',
		justifyContent: 'center',
		color: '#757575',
		zIndex: 1,
		lineHeight: 1,
	} as CSSProperties,

	removeIcon: {
		fontSize: '14px',
		width: '14px',
		height: '14px',
	} as CSSProperties,

	emptyHint: {
		margin: 0,
		padding: '6px 8px',
		fontSize: '12px',
		color: '#757575',
		minHeight: '46px',
		display: 'flex',
		alignItems: 'center',
		border: '1px dashed #ddd',
		borderRadius: '2px',
		fontStyle: 'italic',
	} as CSSProperties,

	warning: {
		display: 'flex',
		alignItems: 'flex-start',
		gap: '4px',
		margin: '6px 0 0',
		fontSize: '12px',
		color: '#996800',
	} as CSSProperties,

	warningIcon: {
		fontSize: '14px',
		width: '14px',
		height: '14px',
		flexShrink: 0,
		marginTop: '1px',
	} as CSSProperties,
};
