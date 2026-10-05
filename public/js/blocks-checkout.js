/**
 * WooCommerce Card-to-Card Payment - Checkout block integration.
 *
 * Registers the gateway with the WooCommerce Blocks payment registry so it
 * appears in the block-based Checkout (the classic hooks only cover the
 * shortcode checkout and post-checkout pages).
 *
 * Vanilla JS + wp.element; no build step required.
 */
( function () {
        'use strict';

        var registry = window.wc && window.wc.wcBlocksRegistry;
        var settingsApi = window.wc && window.wc.wcSettings;
        var element = window.wp && window.wp.element;
        var htmlEntities = window.wp && window.wp.htmlEntities;

        if ( ! registry || ! element || ! settingsApi ) {
                return;
        }

        var settings = settingsApi.getPaymentMethodData
                ? settingsApi.getPaymentMethodData( 'card_to_card', {} )
                : {};

        function decode( value ) {
                if ( ! htmlEntities || ! htmlEntities.decodeEntities ) {
                        var tmp = document.createElement( 'textarea' );
                        tmp.innerHTML = value || '';
                        return tmp.value;
                }
                return htmlEntities.decodeEntities( value || '' );
        }

        var fallbackTitle = ( window.ctpBlocksI18n && window.ctpBlocksI18n.title ) || 'Card-to-Card Payment';
        var title = decode( settings.title ) || fallbackTitle;
        var description = decode( settings.description ) || '';

        /**
         * Render the gateway description (sanitized) inside the checkout block.
         */
        function renderContent() {
                var RawHTML = element.RawHTML;
                var sanitize = window.wc && window.wc.sanitize ? window.wc.sanitize : null;
                var html = description ? '<p>' + ( sanitize && sanitize.sanitizeHTML ? sanitize.sanitizeHTML( '<p>' + description + '</p>' ) : description ) + '</p>' : '';
                return element.createElement( RawHTML, { className: 'ctp-block-description' }, html );
        }

        /**
         * Payment option label.
         */
        function renderLabel( props ) {
                var PaymentMethodLabel = props && props.components ? props.components.PaymentMethodLabel : null;
                if ( PaymentMethodLabel ) {
                        return element.createElement( PaymentMethodLabel, { text: title } );
                }
                return element.createElement( 'span', { className: 'ctp-block-label' }, title );
        }

        /**
         * The gateway can always take a payment: transfer + manual verification.
         */
        function canMakePayment() {
                return true;
        }

        registry.registerPaymentMethod( {
                name: 'card_to_card',
                label: element.createElement( renderLabel ),
                content: element.createElement( renderContent ),
                edit: element.createElement( renderContent ),
                canMakePayment: canMakePayment,
                ariaLabel: title,
                supports: {
                        features: ( settings.supports && settings.supports.features ) || [ 'products' ],
                },
        } );
} )();
