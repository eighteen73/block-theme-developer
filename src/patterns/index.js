/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import PatternMetadataPanel from './PatternMetadataPanel';

/**
 * Register the pattern metadata panel plugin
 */
registerPlugin( 'btd-pattern-metadata', {
	render: PatternMetadataPanel,
} );
