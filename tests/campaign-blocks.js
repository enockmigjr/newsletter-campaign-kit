const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const vm = require( 'node:vm' );

function field( value, start, end ) {
	return {
		value,
		selectionStart: start,
		selectionEnd: end,
		dispatched: false,
		setRangeText( replacement, from, to ) {
			this.value = this.value.slice( 0, from ) + replacement + this.value.slice( to );
		},
		dispatchEvent() {
			this.dispatched = true;
		},
	};
}

const html = field( '<p>Before</p>', 13, 13 );
const text = field( 'Before', 6, 6 );
const select = { value: '42' };
let clickHandler;

const context = {
	Event: class Event {},
	window: {
		NewsletterCampaignBlocks: {
			blocks: { 42: { html: '<p>Inserted</p>', text: 'Inserted' } },
		},
	},
	document: {
		addEventListener( event, handler ) {
			if ( event === 'click' ) clickHandler = handler;
		},
		getElementById( id ) {
			return { 'nck-editorial-block': select, 'nck-campaign-html-body': html, 'nck-campaign-text-body': text }[ id ];
		},
	},
};

vm.runInNewContext( fs.readFileSync( require.resolve( '../assets/js/campaign-blocks.js' ), 'utf8' ), context );
assert.equal( typeof clickHandler, 'function' );
clickHandler( { preventDefault() {}, target: { closest: () => ( {} ) } } );
assert.equal( html.value, '<p>Before</p>\n\n<p>Inserted</p>' );
assert.equal( text.value, 'Before\n\nInserted' );
assert.equal( html.dispatched, true );
assert.equal( text.dispatched, true );
console.log( JSON.stringify( { html: 'inserted_at_cursor', text: 'inserted_at_cursor', inputEvents: 'dispatched' } ) );
