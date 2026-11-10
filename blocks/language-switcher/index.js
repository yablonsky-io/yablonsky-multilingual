/* global wp */
(function (blocks, element, blockEditor, components) {
  'use strict';

  if (!blocks || !element || !blockEditor || !components) return;

  var labels = window.YMultiBlockI18n || {};

  var el = element.createElement;
  var InspectorControls = blockEditor.InspectorControls;
  var useBlockProps = blockEditor.useBlockProps;
  var PanelBody = components.PanelBody;
  var SelectControl = components.SelectControl;
  var ToggleControl = components.ToggleControl;
  var Placeholder = components.Placeholder;

  blocks.registerBlockType('ypm/language-switcher', {
    apiVersion: 3,
    title: labels.title || '',
    description: labels.description || '',
    icon: 'translation',
    category: 'widgets',
    attributes: {
      type: { type: 'string', default: 'dropdown' },
      showFlag: { type: 'boolean', default: true },
      showName: { type: 'boolean', default: true },
      showCode: { type: 'boolean', default: false },
      textAlign: { type: 'string', default: '' }
    },
    supports: {
      html: false,
      customClassName: false
    },
    edit: function (props) {
      var attrs = props.attributes || {};
      var setAttributes = props.setAttributes;
      var blockProps = useBlockProps ? useBlockProps() : {};

      return el(
        'div',
        blockProps,
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            { title: labels.settings || '', initialOpen: true },
            el(SelectControl, {
              label: labels.type || '',
              value: attrs.type || 'dropdown',
              options: [
                { label: labels.dropdown || '', value: 'dropdown' },
                { label: labels.inlineLinks || '', value: 'inline' }
              ],
              onChange: function (value) { setAttributes({ type: value }); }
            }),
            el(SelectControl, {
              label: labels.alignment || '',
              value: attrs.textAlign || '',
              options: [
                { label: labels.themeDefault || '', value: '' },
                { label: labels.left || '', value: 'left' },
                { label: labels.center || '', value: 'center' },
                { label: labels.right || '', value: 'right' }
              ],
              onChange: function (value) { setAttributes({ textAlign: value }); }
            }),
            el(ToggleControl, {
              label: labels.showFlags || '',
              checked: attrs.showFlag !== false,
              onChange: function (value) { setAttributes({ showFlag: !!value }); }
            }),
            el(ToggleControl, {
              label: labels.showName || '',
              checked: attrs.showName !== false,
              onChange: function (value) { setAttributes({ showName: !!value }); }
            }),
            el(ToggleControl, {
              label: labels.showCode || '',
              checked: !!attrs.showCode,
              onChange: function (value) { setAttributes({ showCode: !!value }); }
            })
          )
        ),
        el(
          Placeholder,
          {
            icon: 'translation',
            label: labels.title || '',
            instructions: labels.instructions || ''
          },
          el(
            'p',
            null,
            (attrs.type === 'inline' ? labels.inlineLinks || '' : labels.dropdown || '') +
            ' · ' +
            (attrs.textAlign ? attrs.textAlign : labels.themeDefault || '')
          )
        )
      );
    },
    save: function () {
      return null;
    }
  });
})(wp.blocks, wp.element, wp.blockEditor, wp.components);
