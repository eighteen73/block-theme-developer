/**
 * WordPress dependencies
 */
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import TemplateExportSidebar from './TemplateExportSidebar';

/**
 * Register the template export sidebar plugin
 * This will be implemented in Part 2
 */
registerPlugin( 'btd-template-export', {
	render: TemplateExportSidebar,
} );
