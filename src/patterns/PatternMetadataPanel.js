/**
 * WordPress dependencies
 */
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import {
	TextareaControl,
	FormTokenField,
	TextControl,
	ToggleControl,
	PanelRow
} from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Pattern Metadata Panel Component
 */
export default function PatternMetadataPanel() {
	// Only show on btd_pattern post type
	const postType = useSelect( ( select ) => {
		return select( 'core/editor' ).getCurrentPostType();
	}, [] );

	if ( 'btd_pattern' !== postType ) {
		return null;
	}

	// Get current metadata
	const metadata = useSelect( ( select ) => {
		const meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		return {
			description: meta._btd_description || '',
			categories: meta._btd_categories || [],
			keywords: meta._btd_keywords || [],
			viewportWidth: meta._btd_viewport_width || 1280,
			blockTypes: meta._btd_block_types || [],
			postTypes: meta._btd_post_types || [],
			templateTypes: meta._btd_template_types || [],
			inserter: meta._btd_inserter !== undefined ? meta._btd_inserter : true,
		};
	}, [] );

	// Get pattern categories, post types, block types, and template types from localized data
	const [ coreCategories, setCoreCategories ] = useState( [] );
	const [ postTypes, setPostTypes ] = useState( [] );
	const [ blockTypes, setBlockTypes ] = useState( [] );
	const [ templateTypes, setTemplateTypes ] = useState( [] );

	useEffect( () => {
		// Use data passed via wp_localize_script (more reliable than REST API)
		if ( window.btdData ) {
			setCoreCategories( window.btdData.patternCategories || [] );
			setPostTypes( window.btdData.postTypes || [] );
			setBlockTypes( window.btdData.blockTypes || [] );
			setTemplateTypes( window.btdData.templateTypes || [] );
		} else {
			// Fallback to WordPress core endpoints if localized data isn't available
			Promise.all( [
				apiFetch( { path: '/wp/v2/block-patterns/categories' } ),
				apiFetch( { path: '/wp/v2/types' } )
			] )
				.then( ( [ wpCategories, wpTypes ] ) => {
					const categorySlugs = wpCategories.map( cat => cat.name );
					const publicTypes = Object.values( wpTypes )
						.filter( type => type.viewable && type.slug !== 'attachment' )
						.map( type => type.slug );

					setCoreCategories( categorySlugs );
					setPostTypes( publicTypes );
					// Note: Block types would need a custom endpoint for fallback
					setBlockTypes( [] );
				} )
				.catch( ( error ) => {
					setCoreCategories( [] );
					setPostTypes( [] );
					setBlockTypes( [] );
					setTemplateTypes( [] );
				} );
		}
	}, [] );

	// Dispatch function to update post meta
	const { editPost } = useDispatch( 'core/editor' );

	/**
	 * Update metadata field
	 */
	const updateMetadata = ( field, value ) => {
		editPost( {
			meta: {
				[ `_btd_${ field }` ]: value,
			},
		} );
	};


	/**
	 * Convert token field values to arrays
	 */
	const stringArrayToTokens = ( value ) => {
		if ( Array.isArray( value ) ) {
			return value;
		}
		return value ? value.split( ',' ).map( item => item.trim() ).filter( item => item ) : [];
	};

	const tokensToStringArray = ( tokens ) => {
		return Array.isArray( tokens ) ? tokens : [];
	};

	return (
		<PluginDocumentSettingPanel
			name="btd-pattern-metadata"
			className="btd-pattern-metadata-panel"
		>
			<PanelRow>
				<TextareaControl
					label={ __( 'Description', 'block-theme-developer' ) }
					value={ metadata.description }
					onChange={ ( value ) => updateMetadata( 'description', value ) }
					help={ __( 'A brief description of what this pattern does.', 'block-theme-developer' ) }
					__nextHasNoMarginBottom
				/>
			</PanelRow>

			<PanelRow>
				<div style={ { width: '100%' } }>
					<FormTokenField
						id="btd-categories"
						label={ __( 'Categories', 'block-theme-developer' ) }
						value={ stringArrayToTokens( metadata.categories ) }
						_experimentalAutoSelectFirstMatch
						__experimentalExpandOnFocus
						__next40pxDefaultSize
						suggestions={ coreCategories }
						onChange={ ( tokens ) => updateMetadata( 'categories', tokensToStringArray( tokens ) ) }
						help={ __( 'Select from WordPress core pattern categories.', 'block-theme-developer' ) }
						__nextHasNoMarginBottom
					/>
				</div>
			</PanelRow>

			<PanelRow>
				<div style={ { width: '100%' } }>
					<FormTokenField
						id="btd-keywords"
						label={ __( 'Keywords', 'block-theme-developer' ) }
						value={ stringArrayToTokens( metadata.keywords ) }
						onChange={ ( tokens ) => updateMetadata( 'keywords', tokensToStringArray( tokens ) ) }
						help={ __( 'Keywords to help users find this pattern.', 'block-theme-developer' ) }
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</div>
			</PanelRow>

			<PanelRow>
				<div style={ { width: '100%' } }>
					<FormTokenField
						id="btd-block-types"
						label={ __( 'Block Types', 'block-theme-developer' ) }
						value={ stringArrayToTokens( metadata.blockTypes ) }
						suggestions={ blockTypes }
						onChange={ ( tokens ) => updateMetadata( 'block_types', tokensToStringArray( tokens ) ) }
						help={ __( 'Select from available block types and template part areas.', 'block-theme-developer' ) }
						__experimentalExpandOnFocus
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						/>
				</div>
			</PanelRow>

			<PanelRow>
				<div style={ { width: '100%' } }>
					<FormTokenField
						id="btd-post-types"
						label={ __( 'Post Types', 'block-theme-developer' ) }
						value={ stringArrayToTokens( metadata.postTypes ) }
						suggestions={ postTypes }
						onChange={ ( tokens ) => updateMetadata( 'post_types', tokensToStringArray( tokens ) ) }
						help={ __( 'Select from registered post types.', 'block-theme-developer' ) }
						__experimentalExpandOnFocus
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</div>
			</PanelRow>

			<PanelRow>
				<div style={ { width: '100%' } }>
					<FormTokenField
						id="btd-template-types"
						label={ __( 'Template Types', 'block-theme-developer' ) }
						value={ stringArrayToTokens( metadata.templateTypes ) }
						onChange={ ( tokens ) => updateMetadata( 'template_types', tokensToStringArray( tokens ) ) }
						suggestions={ Object.keys( templateTypes ).map( slug => templateTypes[ slug ]?.title || slug ) }
						help={ __( 'Template types this pattern is designed for.', 'block-theme-developer' ) }
						__experimentalExpandOnFocus
						__next40pxDefaultSize
						__nextHasNoMarginBottom
					/>
				</div>
			</PanelRow>

			<PanelRow>
				<TextControl
					label={ __( 'Viewport Width', 'block-theme-developer' ) }
					type="number"
					value={ metadata.viewportWidth }
					onChange={ ( value ) => updateMetadata( 'viewport_width', parseInt( value, 10 ) || 1200 ) }
					help={ __( 'Suggested viewport width for displaying this pattern.', 'block-theme-developer' ) }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
				/>
			</PanelRow>

			<PanelRow>
				<ToggleControl
					label={ __( 'Show in Inserter', 'block-theme-developer' ) }
					checked={ metadata.inserter }
					onChange={ ( value ) => updateMetadata( 'inserter', value ) }
					help={ __( 'Whether this pattern should appear in the pattern inserter.', 'block-theme-developer' ) }
					__nextHasNoMarginBottom
				/>
			</PanelRow>
		</PluginDocumentSettingPanel>
	);
}
