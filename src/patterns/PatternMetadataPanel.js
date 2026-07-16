/**
 * WordPress dependencies
 */
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import {
	TextareaControl,
	FormTokenField,
	TextControl,
	ToggleControl,
	PanelRow,
} from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useEffect, useLayoutEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Convert token field values to arrays.
 *
 * @param {string|string[]} value Value.
 * @return {string[]} Tokens.
 */
function stringArrayToTokens( value ) {
	if ( Array.isArray( value ) ) {
		return value;
	}
	return value ? value.split( ',' ).map( ( item ) => item.trim() ).filter( Boolean ) : [];
}

/**
 * @param {string[]} tokens Tokens.
 * @return {string[]} Array.
 */
function tokensToStringArray( tokens ) {
	return Array.isArray( tokens ) ? tokens : [];
}

/**
 * Core hardcodes PluginDocumentSettingPanel above PostTaxonomies.
 * Move our panel to sit directly under Pattern Categories when present.
 *
 * @param {boolean} enabled Whether reordering should run.
 */
function usePanelBelowPatternCategories( enabled ) {
	useLayoutEffect( () => {
		if ( ! enabled ) {
			return;
		}

		const movePanel = () => {
			const btdPanel = document.querySelector( '.btd-pattern-details-panel' );
			if ( ! btdPanel?.parentElement ) {
				return false;
			}

			const parent = btdPanel.parentElement;
			const siblings = Array.from( parent.children ).filter( ( el ) =>
				el.classList?.contains( 'components-panel__body' )
			);

			const categoriesPanel = siblings.find( ( el ) => {
				const title = el.querySelector( '.components-panel__body-title' );
				return title && /pattern categories/i.test( title.textContent || '' );
			} );

			if ( ! categoriesPanel ) {
				return false;
			}

			if ( categoriesPanel.nextElementSibling !== btdPanel ) {
				categoriesPanel.after( btdPanel );
			}

			return true;
		};

		if ( movePanel() ) {
			return;
		}

		const observer = new MutationObserver( () => {
			if ( movePanel() ) {
				observer.disconnect();
			}
		} );

		observer.observe( document.body, { childList: true, subtree: true } );

		return () => observer.disconnect();
	}, [ enabled ] );
}

/**
 * Pattern Details sidebar panel for native wp_block patterns.
 *
 * Categories use core's Pattern Categories panel.
 */
export default function PatternMetadataPanel() {
	const postType = useSelect( ( select ) => {
		return select( 'core/editor' ).getCurrentPostType();
	}, [] );

	const isWpBlock = 'wp_block' === postType;

	usePanelBelowPatternCategories( isWpBlock );

	const metadata = useSelect( ( select ) => {
		const meta = select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {};
		return {
			description: meta._btd_description || '',
			keywords: meta._btd_keywords || [],
			viewportWidth: meta._btd_viewport_width || 1280,
			blockTypes: meta._btd_block_types || [],
			postTypes: meta._btd_post_types || [],
			templateTypes: meta._btd_template_types || [],
			inserter: meta._btd_inserter !== undefined ? meta._btd_inserter : true,
			contentOnlyLock: meta._btd_content_only_lock !== undefined ? meta._btd_content_only_lock : false,
			synced: meta._btd_synced !== undefined ? meta._btd_synced : false,
		};
	}, [] );

	const [ suggestions, setSuggestions ] = useState( {
		postTypes: [],
		blockTypes: [],
		templateTypes: {},
	} );

	useEffect( () => {
		if ( ! isWpBlock ) {
			return;
		}

		if ( window.btdData ) {
			setSuggestions( {
				postTypes: window.btdData.postTypes || [],
				blockTypes: window.btdData.blockTypes || [],
				templateTypes: window.btdData.templateTypes || {},
			} );
			return;
		}

		apiFetch( { path: '/wp/v2/types' } )
			.then( ( wpTypes ) => {
				setSuggestions( {
					postTypes: Object.values( wpTypes )
						.filter( ( type ) => type.viewable && type.slug !== 'attachment' )
						.map( ( type ) => type.slug ),
					blockTypes: [],
					templateTypes: {},
				} );
			} )
			.catch( () => {
				setSuggestions( {
					postTypes: [],
					blockTypes: [],
					templateTypes: {},
				} );
			} );
	}, [ isWpBlock ] );

	const { editPost } = useDispatch( 'core/editor' );

	if ( ! isWpBlock ) {
		return null;
	}

	const updateMetadata = ( field, value ) => {
		editPost( {
			meta: {
				[ `_btd_${ field }` ]: value,
			},
		} );
	};

	const normalizeTemplateTypeToken = ( token ) => {
		if ( suggestions.templateTypes[ token ] ) {
			return token;
		}
		const match = Object.entries( suggestions.templateTypes ).find(
			( [ , data ] ) => ( data?.title || '' ) === token
		);
		return match ? match[ 0 ] : token;
	};

	return (
		<PluginDocumentSettingPanel
			name="btd-pattern-details"
			title={ __( 'Pattern Details', 'block-theme-developer' ) }
			className="btd-pattern-details-panel"
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
						suggestions={ suggestions.blockTypes }
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
						suggestions={ suggestions.postTypes }
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
						value={ stringArrayToTokens( metadata.templateTypes ).map( normalizeTemplateTypeToken ) }
						onChange={ ( tokens ) => {
							updateMetadata(
								'template_types',
								tokensToStringArray( tokens ).map( normalizeTemplateTypeToken )
							);
						} }
						suggestions={ Object.keys( suggestions.templateTypes ) }
						displayTransform={ ( token ) =>
							suggestions.templateTypes[ token ]?.title || token
						}
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

			<PanelRow>
				<ToggleControl
					label={ __( 'Content-only editing', 'block-theme-developer' ) }
					checked={ metadata.contentOnlyLock }
					onChange={ ( value ) => updateMetadata( 'content_only_lock', value ) }
					help={ __( 'Applies templateLock "contentOnly" to the exported pattern file only. The authoring editor stays fully editable.', 'block-theme-developer' ) }
					__nextHasNoMarginBottom
				/>
			</PanelRow>

			<PanelRow>
				<ToggleControl
					label={ __( 'Synced theme pattern', 'block-theme-developer' ) }
					checked={ metadata.synced }
					onChange={ ( value ) => updateMetadata( 'synced', value ) }
						help={ __( 'Writes Synced: true to the theme pattern file so Orbit can sync layout updates. When off, the theme file is written without Synced.', 'block-theme-developer' ) }
					__nextHasNoMarginBottom
				/>
			</PanelRow>
		</PluginDocumentSettingPanel>
	);
}
